<?php
/**
 * The report itself: filter state, the merge of the two streams, and the totals.
 *
 * This is the only file that knows about both sources at once. data-pmpro.php
 * and data-woo.php each answer "give me your rows for this window" and neither
 * knows the other exists; everything that has to reason across both — merging,
 * sorting, the search, the member-only filter, the totals — happens here, in
 * PHP, after both have been normalised.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many rows a page of the table holds.
 *
 * A constant rather than a screen option on purpose: registering a screen option
 * would write user meta, and this plugin does not write. See the header note in
 * qhta-revenue.php.
 *
 * @return int
 */
function qhta_revenue_per_page() {
	return (int) apply_filters( 'qhta_revenue_per_page', 50 );
}

/**
 * The date-range presets offered above the table.
 *
 * "This financial year" is the Australian one — 1 July to 30 June — because
 * that is the year this organisation reports in. Ranges are resolved against
 * the site timezone (Australia/Brisbane), not the server's or the viewer's.
 *
 * `custom` is a valid preset value but is deliberately not in this list: it is
 * the state the From/To fields put the report into, not something to pick.
 * Presets are rendered as links and the date fields submit `custom`, so "I
 * chose a preset" and "I typed dates" can never be true at once — which is the
 * ambiguity a preset dropdown sitting next to two date inputs always has, and
 * which it resolves by silently ignoring one of them.
 *
 * @return array<string,string> Preset key => label.
 */
function qhta_revenue_date_presets() {
	return array(
		'this_month' => __( 'This month', 'qhta-revenue' ),
		'last_month' => __( 'Last month', 'qhta-revenue' ),
		'this_fy'    => __( 'This financial year', 'qhta-revenue' ),
		'all'        => __( 'All time', 'qhta-revenue' ),
	);
}

/**
 * Resolve a preset into a from/to pair of site-local Y-m-d dates.
 *
 * 'all' resolves to a pair of empty strings, which every caller reads as
 * "unbounded" and turns into an omitted clause rather than a date far enough in
 * the past to be safe. An omitted clause cannot be wrong; a sentinel date can.
 *
 * @param string $preset Preset key.
 * @return array{0:string,1:string} From, to.
 */
function qhta_revenue_preset_range( $preset ) {
	$now = new DateTimeImmutable( 'now', qhta_revenue_timezone() );

	switch ( $preset ) {
		case 'last_month':
			$start = $now->modify( 'first day of last month' );
			$end   = $now->modify( 'last day of last month' );
			break;

		case 'this_fy':
			// The AU financial year containing today: 1 Jul of this calendar
			// year if we are past June, otherwise 1 Jul of last year.
			$year  = (int) $now->format( 'n' ) >= 7 ? (int) $now->format( 'Y' ) : (int) $now->format( 'Y' ) - 1;
			$start = $now->setDate( $year, 7, 1 );
			$end   = $now->setDate( $year + 1, 6, 30 );
			break;

		case 'all':
			return array( '', '' );

		case 'this_month':
		default:
			$start = $now->modify( 'first day of this month' );
			$end   = $now->modify( 'last day of this month' );
			break;
	}

	return array( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
}

/**
 * Read and sanitise the filter state out of the request.
 *
 * Defaults to **this month, Paid only, both sources** — the "what came in this
 * month" view the screen is opened on most. Paid-only is the default because
 * the headline question is income actually received; pending and failed orders
 * are one dropdown away and are excluded from the totals until asked for.
 *
 * Every value is validated against a known set rather than sanitised and
 * trusted, so an unexpected value falls back to the default instead of reaching
 * a query.
 *
 * @param array|null $source Request array to read; defaults to $_GET.
 * @return array Filter state.
 */
function qhta_revenue_current_filters( $source = null ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report; the export path nonce-checks separately.
	$req = null === $source ? $_GET : $source;

	$get = static function ( $key, $default = '' ) use ( $req ) {
		return isset( $req[ $key ] ) ? sanitize_text_field( wp_unslash( $req[ $key ] ) ) : $default;
	};

	$preset = $get( 'preset', 'this_month' );
	if ( 'custom' !== $preset && ! array_key_exists( $preset, qhta_revenue_date_presets() ) ) {
		$preset = 'this_month';
	}

	$from = qhta_revenue_sanitise_date( $get( 'from' ) );
	$to   = qhta_revenue_sanitise_date( $get( 'to' ) );

	if ( 'custom' === $preset ) {
		// A custom range with neither end filled in is not a range — treat it
		// as unbounded rather than silently snapping back to this month, which
		// would look like the dates had been ignored.
		if ( '' === $from && '' === $to ) {
			$preset = 'all';
		}
	} else {
		list( $from, $to ) = qhta_revenue_preset_range( $preset );
	}

	// Reversed dates are a typo, not an empty report. Swap them.
	if ( '' !== $from && '' !== $to && $from > $to ) {
		list( $from, $to ) = array( $to, $from );
	}

	$src = $get( 'source', 'both' );
	if ( ! in_array( $src, array( 'both', 'membership', 'store' ), true ) ) {
		$src = 'both';
	}

	$status = $get( 'status', 'paid' );
	if ( 'all' !== $status && ! array_key_exists( $status, qhta_revenue_status_map() ) ) {
		$status = 'paid';
	}

	$orderby = $get( 'orderby', 'date' );
	if ( ! in_array( $orderby, array( 'date', 'source', 'customer', 'item', 'amount', 'fee', 'net', 'status' ), true ) ) {
		$orderby = 'date';
	}

	$order = strtolower( $get( 'order', 'desc' ) );
	if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
		$order = 'desc';
	}

	return array(
		'preset'      => $preset,
		'from'        => $from,
		'to'          => $to,
		'source'      => $src,
		'status'      => $status,
		'member_only' => (bool) $get( 'member_only', '' ),
		'search'      => $get( 's' ),
		'orderby'     => $orderby,
		'order'       => $order,
		'paged'       => max( 1, (int) $get( 'paged', 1 ) ),
	);
}

/**
 * The raw source statuses a normalised status filter selects.
 *
 * Returns null for "all", which each source reads as "do not constrain status
 * at all" — importantly *not* as "every status I know about", so a custom
 * status added by some other plugin still appears under All rather than being
 * quietly excluded by an allow-list.
 *
 * @param string $source 'pmpro' or 'woo'.
 * @param string $status Normalised status key, or 'all'.
 * @return string[]|null
 */
function qhta_revenue_raw_statuses( $source, $status ) {
	if ( 'all' === $status ) {
		return null;
	}

	$map = qhta_revenue_status_map();

	return isset( $map[ $status ][ $source ] ) ? (array) $map[ $status ][ $source ] : array();
}

/**
 * Fetch, merge and sort every row matching the filters.
 *
 * Deliberately unpaginated: both the table and the CSV export need the whole
 * filtered set — the table to compute totals that reflect the filters rather
 * than the visible page, the export because "export what I am looking at" means
 * all of it. The table slices a page off the end of this.
 *
 * Scale: this loads every in-range order from both systems into memory. At this
 * site's volume (low hundreds) that is the simpler and faster choice than
 * paginating each source separately and trying to merge pages, which cannot be
 * done correctly without over-fetching anyway. See the performance note in the
 * README for when that stops being true.
 *
 * @param array $filters Filter state from qhta_revenue_current_filters().
 * @return array[] Normalised rows, sorted.
 */
function qhta_revenue_get_rows( $filters ) {
	$rows = array();

	if ( 'store' !== $filters['source'] ) {
		$rows = array_merge( $rows, qhta_revenue_get_membership_rows( $filters ) );
	}

	if ( 'membership' !== $filters['source'] ) {
		$rows = array_merge( $rows, qhta_revenue_get_store_rows( $filters ) );
	}

	$rows = qhta_revenue_apply_search( $rows, $filters['search'] );

	if ( $filters['member_only'] ) {
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'yes' === $row['member'];
				}
			)
		);
	}

	qhta_revenue_sort_rows( $rows, $filters['orderby'], $filters['order'] );

	/**
	 * Filter the merged, sorted report rows.
	 *
	 * @param array[] $rows    Rows.
	 * @param array   $filters Active filters.
	 */
	return (array) apply_filters( 'qhta_revenue_rows', $rows, $filters );
}

/**
 * Free-text search over customer name and email.
 *
 * Applied after the merge rather than pushed into each source's query, so one
 * search means the same thing on both sides — PMPro's billing name and Woo's
 * billing name are different columns in different systems and a per-source
 * WHERE would inevitably drift.
 *
 * @param array[] $rows   Rows.
 * @param string  $search Search term.
 * @return array[]
 */
function qhta_revenue_apply_search( $rows, $search ) {
	$search = trim( (string) $search );

	if ( '' === $search ) {
		return $rows;
	}

	$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

	return array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $needle ) {
				$haystack = $row['customer'] . ' ' . $row['email'];
				$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

				return false !== strpos( $haystack, $needle );
			}
		)
	);
}

/**
 * Sort merged rows in place.
 *
 * Date is the primary sort everywhere else too: every other column falls back
 * to date descending as a tiebreak, so equal amounts or a run of identical
 * statuses still read chronologically rather than in whatever order the two
 * queries happened to return.
 *
 * Unknown fees and nets (null) sort last regardless of direction — they are
 * absent values, and letting them collate as zero would put them among the
 * cheapest orders and imply a fact about them that is not known.
 *
 * @param array[] $rows    Rows, by reference.
 * @param string  $orderby Column key.
 * @param string  $order   'asc' or 'desc'.
 * @return void
 */
function qhta_revenue_sort_rows( &$rows, $orderby, $order ) {
	$dir = ( 'asc' === $order ) ? 1 : -1;

	usort(
		$rows,
		static function ( $a, $b ) use ( $orderby, $dir ) {
			switch ( $orderby ) {
				case 'amount':
				case 'fee':
				case 'net':
					$av = $a[ $orderby ];
					$bv = $b[ $orderby ];

					if ( null === $av || null === $bv ) {
						if ( $av === $bv ) {
							break;
						}
						return ( null === $av ) ? 1 : -1;
					}

					$cmp = ( $av <=> $bv ) * $dir;
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					break;

				case 'source':
				case 'customer':
				case 'item':
				case 'status':
					$key = ( 'status' === $orderby ) ? 'status_label' : ( 'source' === $orderby ? 'source_label' : $orderby );
					$cmp = strcasecmp( (string) $a[ $key ], (string) $b[ $key ] ) * $dir;
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					break;

				case 'date':
				default:
					$cmp = ( $a['timestamp'] <=> $b['timestamp'] ) * $dir;
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					break;
			}

			// Stable, meaningful tiebreak: newest first.
			return $b['timestamp'] <=> $a['timestamp'];
		}
	);
}

/**
 * Totals for a set of rows, broken down by source.
 *
 * Reports **net of known fees**. A row whose Stripe fee could not be determined
 * contributes its gross to the gross total and contributes nothing to the fee or
 * net totals, and is counted in `unknown_fee` so the screen can say so out loud.
 * The alternative — treating an unknown fee as zero — would silently inflate the
 * net, which is exactly the error this report exists to prevent.
 *
 * @param array[] $rows Rows.
 * @return array Totals, keyed 'all', 'membership', 'store'.
 */
function qhta_revenue_totals( $rows ) {
	$blank = array(
		'count'        => 0,
		'gross'        => 0.0,
		'fees'         => 0.0,
		'net'          => 0.0,
		'unknown_fee'  => 0,
		// Partial refunds already deducted from the gross above, kept so the
		// screen can say the deduction happened rather than leaving a total
		// that quietly disagrees with WooCommerce's own order list.
		'refunded'     => 0.0,
		'refunded_rows' => 0,
		// The slice of the fees that is not Stripe's — PMPro's platform cut.
		// Tracked separately because it is the one component that can be
		// removed by a licence rather than negotiated with a card network.
		'platform_fee' => 0.0,
	);

	$totals = array(
		'all'        => $blank,
		'membership' => $blank,
		'store'      => $blank,
	);

	foreach ( $rows as $row ) {
		$buckets = array( 'all', $row['source'] );

		foreach ( $buckets as $bucket ) {
			if ( ! isset( $totals[ $bucket ] ) ) {
				continue;
			}

			++$totals[ $bucket ]['count'];
			$totals[ $bucket ]['gross'] += (float) $row['amount'];

			if ( ! empty( $row['refunded'] ) ) {
				$totals[ $bucket ]['refunded'] += (float) $row['refunded'];
				++$totals[ $bucket ]['refunded_rows'];
			}

			if ( null === $row['fee'] ) {
				++$totals[ $bucket ]['unknown_fee'];
				continue;
			}

			$totals[ $bucket ]['fees'] += (float) $row['fee'];
			$totals[ $bucket ]['net']  += ( null === $row['net'] ) ? ( (float) $row['amount'] - (float) $row['fee'] ) : (float) $row['net'];

			if ( ! empty( $row['fee_breakdown']['application_fee'] ) ) {
				$totals[ $bucket ]['platform_fee'] += (float) $row['fee_breakdown']['application_fee'];
			}
		}
	}

	return $totals;
}

/**
 * Build a normalised row.
 *
 * A single constructor for both sources, so a field cannot exist on one kind of
 * row and be missing on the other — the merge, the sort, the totals and the CSV
 * all index rows blindly and a missing key would be a notice at best.
 *
 * @param array $args Field overrides.
 * @return array
 */
function qhta_revenue_row( $args ) {
	$row = wp_parse_args(
		$args,
		array(
			'source'       => 'store',
			'source_label' => '',
			'timestamp'    => 0,
			'ref'          => '',
			'edit_url'     => '',
			'customer'     => '',
			'email'        => '',
			'item'         => '',
			'amount'       => 0.0,
			'fee'          => null,
			'net'          => null,
			// Components of the fee, when Stripe told us — e.g. its own charge
			// and PMPro's platform cut, which come out of the same payout.
			'fee_breakdown' => array(),
			// Set only for a PARTIALLY refunded order, where the amount above
			// has already had the refund taken off it and the status still
			// reads Paid. Zero on everything else, including fully refunded
			// orders, whose status carries the fact instead.
			'refunded'      => 0.0,
			'amount_before_refund' => 0.0,
			'status'       => 'other',
			'status_raw'   => '',
			'status_label' => '',
			'gateway'      => '',
			'member'       => 'no',
			'member_level' => '',
			'member_note'  => '',
			'txn_ids'      => '',
		)
	);

	if ( '' === $row['source_label'] ) {
		$row['source_label'] = ( 'membership' === $row['source'] )
			? __( 'Membership', 'qhta-revenue' )
			: __( 'Store', 'qhta-revenue' );
	}

	if ( '' === $row['status_label'] ) {
		$row['status_label'] = qhta_revenue_status_label( $row['status'], $row['status_raw'] );
	}

	$row['amount'] = (float) $row['amount'];
	$row['fee']    = ( null === $row['fee'] ) ? null : (float) $row['fee'];

	// Net is derived here rather than at each call site so the rule — a known
	// fee always yields a net, an unknown fee never does — holds identically for
	// both sources.
	if ( null === $row['net'] ) {
		$row['net'] = ( null === $row['fee'] ) ? null : ( $row['amount'] - $row['fee'] );
	} else {
		$row['net'] = (float) $row['net'];
	}

	return $row;
}

/**
 * Normalise whatever a fee/net filter returned.
 *
 * The two `*_fee_net` filters are extension points, so a third party may well
 * return the two-element array the first release documented rather than the
 * three-element one with the breakdown. Padding here means `list()` at the call
 * sites always destructures cleanly instead of emitting a notice on the day
 * someone hooks it.
 *
 * @param mixed $value Filter return value.
 * @return array{0:float|null,1:float|null,2:array}
 */
function qhta_revenue_normalise_fee_net( $value ) {
	$value = array_values( (array) $value );

	return array(
		isset( $value[0] ) ? $value[0] : null,
		isset( $value[1] ) ? $value[1] : null,
		isset( $value[2] ) && is_array( $value[2] ) ? $value[2] : array(),
	);
}

/**
 * Site-local display string for a UTC timestamp.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function qhta_revenue_date_display( $timestamp ) {
	if ( ! $timestamp ) {
		return '';
	}

	return wp_date( 'Y-m-d H:i', $timestamp );
}
