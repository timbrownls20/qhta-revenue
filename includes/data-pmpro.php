<?php
/**
 * Membership income — read from Paid Memberships Pro's own tables.
 *
 * PMPro orders are not WooCommerce orders. They live in
 * {$wpdb->prefix}pmpro_membership_orders and wc_get_orders() cannot see them at
 * all — that is the single fact that makes this plugin two readers rather than
 * one query. Everything here goes through $wpdb with prepared statements.
 *
 * The SELECT is `o.*` rather than a named column list on purpose. PMPro's order
 * schema has gained and renamed columns across 2.x and 3.x (billing fields in
 * particular), and a named list would fatal or return nulls on the version this
 * happens to be installed beside. Selecting everything and reading each field
 * defensively means a schema difference costs a blank cell, not an error.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Are PMPro order timestamps stored in UTC?
 *
 * They are: PMPro writes the order timestamp with MySQL NOW() or a PHP date()
 * call, and WordPress runs PHP in UTC, so what lands in the column is UTC even
 * though the column has no timezone. The report therefore converts the user's
 * site-local date range into UTC before querying, and converts each stored
 * timestamp back into site time for display — otherwise every order in the ten
 * hours after Brisbane midnight would fall in the wrong day, which for a
 * month-boundary report means income landing in the wrong month.
 *
 * Behind a filter because it is an assumption about someone else's storage. If
 * an order's date on this screen ever disagrees with the same order's date on
 * PMPro's own screen, this is the switch to try first.
 *
 * @return bool
 */
function qhta_revenue_pmpro_timestamps_are_utc() {
	return (bool) apply_filters( 'qhta_revenue_pmpro_timestamps_are_utc', true );
}

/**
 * Membership order rows for the active filters.
 *
 * @param array $filters Filter state.
 * @return array[] Normalised rows.
 */
function qhta_revenue_get_membership_rows( $filters ) {
	global $wpdb;

	if ( ! qhta_revenue_pmpro_active() ) {
		return array(); // PMPro absent → no membership income to report, no fatal.
	}

	$orders_table = $wpdb->prefix . 'pmpro_membership_orders';
	$levels_table = $wpdb->prefix . 'pmpro_membership_levels';

	$where  = array( '1=1' );
	$params = array();

	if ( '' !== $filters['from'] ) {
		$where[]  = 'o.timestamp >= %s';
		$params[] = qhta_revenue_pmpro_boundary( $filters['from'] . ' 00:00:00' );
	}

	if ( '' !== $filters['to'] ) {
		$where[]  = 'o.timestamp <= %s';
		$params[] = qhta_revenue_pmpro_boundary( $filters['to'] . ' 23:59:59' );
	}

	$statuses = qhta_revenue_raw_statuses( 'pmpro', $filters['status'] );

	if ( is_array( $statuses ) ) {
		if ( ! $statuses ) {
			return array(); // A normalised status with no PMPro equivalent.
		}

		$where[] = 'o.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params  = array_merge( $params, $statuses );
	}

	// Table names are interpolated (they are built from $wpdb->prefix, not from
	// input, and prepare() cannot placeholder an identifier); every value is a
	// placeholder.
	$sql = "SELECT o.*, l.name AS qhta_level_name, u.user_email AS qhta_user_email, u.display_name AS qhta_user_name
		FROM {$orders_table} o
		LEFT JOIN {$levels_table} l ON l.id = o.membership_id
		LEFT JOIN {$wpdb->users} u ON u.ID = o.user_id
		WHERE " . implode( ' AND ', $where ) . '
		ORDER BY o.timestamp DESC';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$results = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

	if ( ! $results ) {
		return array();
	}

	// One bulk meta fetch for the whole page rather than a lookup per order —
	// the fee lives in order meta and asking per row would be N queries.
	$meta = qhta_revenue_pmpro_order_meta( wp_list_pluck( $results, 'id' ) );

	$rows = array();

	foreach ( $results as $order ) {
		$rows[] = qhta_revenue_normalise_membership_order( $order, isset( $meta[ $order->id ] ) ? $meta[ $order->id ] : array() );
	}

	return $rows;
}

/**
 * Convert a site-local 'Y-m-d H:i:s' boundary into the form the column holds.
 *
 * @param string $local Site-local datetime.
 * @return string Datetime string to compare against o.timestamp.
 */
function qhta_revenue_pmpro_boundary( $local ) {
	return qhta_revenue_pmpro_timestamps_are_utc() ? get_gmt_from_date( $local ) : $local;
}

/**
 * Turn one PMPro order row into a normalised report row.
 *
 * @param object $order PMPro order row, as selected above.
 * @param array  $meta  That order's meta, key => value.
 * @return array
 */
function qhta_revenue_normalise_membership_order( $order, $meta ) {
	$stored = (string) ( isset( $order->timestamp ) ? $order->timestamp : '' );

	if ( qhta_revenue_pmpro_timestamps_are_utc() ) {
		$timestamp = $stored ? (int) strtotime( $stored . ' +0000' ) : 0;
	} else {
		$timestamp = $stored ? (int) strtotime( get_gmt_from_date( $stored ) . ' +0000' ) : 0;
	}

	$customer = qhta_revenue_first_nonempty(
		array(
			isset( $order->billing_name ) ? $order->billing_name : '',
			isset( $order->qhta_user_name ) ? $order->qhta_user_name : '',
		)
	);

	// PMPro's order schema has carried a billing email under more than one name
	// across versions and does not always carry one at all, so the order is
	// asked first and the buyer's account is the fallback.
	$email = qhta_revenue_first_nonempty(
		array(
			isset( $order->billing_email ) ? $order->billing_email : '',
			isset( $order->Email ) ? $order->Email : '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			isset( $order->qhta_user_email ) ? $order->qhta_user_email : '',
		)
	);

	$level = qhta_revenue_first_nonempty(
		array(
			isset( $order->qhta_level_name ) ? $order->qhta_level_name : '',
			// The level was deleted after the order was taken: name the id
			// rather than leaving the row looking like it bought nothing.
			! empty( $order->membership_id ) ? sprintf( /* translators: %d: PMPro level id. */ __( 'Level #%d', 'qhta-revenue' ), (int) $order->membership_id ) : '',
		)
	);

	$raw_status = isset( $order->status ) ? (string) $order->status : '';
	$gateway    = isset( $order->gateway ) ? (string) $order->gateway : '';
	$amount     = isset( $order->total ) ? (float) $order->total : 0.0;

	list( $fee, $net, $fee_breakdown ) = qhta_revenue_membership_fee_net( $order, $meta, $amount );

	$txn = array_filter(
		array(
			isset( $order->payment_transaction_id ) ? (string) $order->payment_transaction_id : '',
			isset( $order->subscription_transaction_id ) ? (string) $order->subscription_transaction_id : '',
		)
	);

	return qhta_revenue_row(
		array(
			'source'       => 'membership',
			'timestamp'    => $timestamp,
			'ref'          => isset( $order->code ) ? (string) $order->code : (string) $order->id,
			'edit_url'     => add_query_arg(
				array(
					'page'  => 'pmpro-orders',
					'order' => (int) $order->id,
				),
				admin_url( 'admin.php' )
			),
			'customer'     => $customer,
			'email'        => $email,
			'item'         => $level,
			'amount'        => $amount,
			'fee'           => $fee,
			'net'           => $net,
			'fee_breakdown' => $fee_breakdown,
			'status'       => qhta_revenue_normalise_status( 'pmpro', $raw_status ),
			'status_raw'   => $raw_status,
			'gateway'      => qhta_revenue_pmpro_gateway_label( $gateway ),
			// A membership order is a membership: the buyer held the level this
			// order paid for. Unlike a store row there is nothing to resolve.
			'member'       => 'yes',
			'member_level' => $level,
			'txn_ids'      => implode( ' / ', array_unique( $txn ) ),
		)
	);
}

/**
 * First non-empty string in a list.
 *
 * @param string[] $candidates Candidates in preference order.
 * @return string
 */
function qhta_revenue_first_nonempty( $candidates ) {
	foreach ( $candidates as $candidate ) {
		$candidate = trim( (string) $candidate );

		if ( '' !== $candidate ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Readable name for a PMPro gateway slug.
 *
 * @param string $gateway Gateway slug.
 * @return string
 */
function qhta_revenue_pmpro_gateway_label( $gateway ) {
	$labels = array(
		'stripe'   => 'Stripe',
		'check'    => __( 'Pay by Check / bank transfer', 'qhta-revenue' ),
		'paypal'   => 'PayPal',
		'free'     => __( 'Free', 'qhta-revenue' ),
	);

	if ( isset( $labels[ $gateway ] ) ) {
		return $labels[ $gateway ];
	}

	return $gateway ? ucwords( str_replace( array( '_', '-' ), ' ', $gateway ) ) : '';
}

/**
 * Fetch PMPro order meta for a set of orders in one query.
 *
 * @param int[] $order_ids Order ids.
 * @return array<int,array<string,string>> Order id => meta key => value.
 */
function qhta_revenue_pmpro_order_meta( $order_ids ) {
	global $wpdb;

	$order_ids = array_values( array_unique( array_map( 'intval', (array) $order_ids ) ) );

	if ( ! $order_ids ) {
		return array();
	}

	$table = $wpdb->prefix . 'pmpro_membership_ordermeta';

	// Order meta arrived in PMPro 2.6. On an older install the table is simply
	// absent and every fee stays unknown, which is the correct answer.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pmpro_membership_order_id AS order_id, meta_key, meta_value FROM {$table} WHERE pmpro_membership_order_id IN ({$placeholders})",
			$order_ids
		)
	);

	$meta = array();

	foreach ( (array) $rows as $row ) {
		$meta[ (int) $row->order_id ][ (string) $row->meta_key ] = (string) $row->meta_value;
	}

	return $meta;
}

/**
 * Candidate meta keys that may hold a PMPro order's Stripe fee and net.
 *
 * **These are unconfirmed.** PMPro's order screen shows "Stripe Fee" and
 * "Stripe Payout", so the figures exist, but whether they are stored as order
 * meta or fetched live from the charge's balance transaction at render time
 * has not been checked against a real order on this site — and the handover was
 * explicit that guessing a meta key is not good enough.
 *
 * So the behaviour is: try these keys; if none is present the fee is reported
 * as **Unknown**, never as zero. Nothing is invented and no total is inflated.
 * To settle it, open the report with `&qhta_revenue_diag=1` — that lists every
 * meta key actually stored against recent Stripe membership orders — then
 * either add the real key here or supply it through this filter.
 *
 * @return array{fee:string[],net:string[]}
 */
function qhta_revenue_pmpro_fee_meta_keys() {
	return (array) apply_filters(
		'qhta_revenue_pmpro_fee_meta_keys',
		array(
			'fee' => array( 'stripe_fee', 'pmpro_stripe_fee', 'application_fee' ),
			'net' => array( 'stripe_net', 'stripe_payout', 'pmpro_stripe_net' ),
		)
	);
}

/**
 * Does this PMPro gateway take a processor fee?
 *
 * Pay by Check and bank transfer income arrives whole — there is no processor
 * in the middle, so the fee is a known zero rather than an unknown. Getting
 * this distinction right is what lets the totals say "net of known fees" and
 * mean it: a site whose income is mostly bank transfer should show almost no
 * unknowns, not a page of them.
 *
 * @param string $gateway PMPro gateway slug.
 * @return bool
 */
function qhta_revenue_pmpro_gateway_has_fee( $gateway ) {
	return 'stripe' === strtolower( (string) $gateway );
}

/**
 * Resolve a membership order's Stripe fee and net.
 *
 * Never computes a fee from a percentage. Stripe's actual charge varies with
 * card origin, currency and GST, so a formula would produce a number that looks
 * authoritative and is wrong — the only acceptable sources are a figure the
 * gateway recorded or one read back from Stripe itself.
 *
 * @param object $order  PMPro order row.
 * @param array  $meta   That order's meta.
 * @param float  $amount Order gross.
 * @return array{0:float|null,1:float|null} Fee, net.
 */
function qhta_revenue_membership_fee_net( $order, $meta, $amount ) {
	$gateway = isset( $order->gateway ) ? (string) $order->gateway : '';
	$keys    = qhta_revenue_pmpro_fee_meta_keys();

	$fee = qhta_revenue_meta_number( $meta, isset( $keys['fee'] ) ? $keys['fee'] : array() );
	$net = qhta_revenue_meta_number( $meta, isset( $keys['net'] ) ? $keys['net'] : array() );

	if ( null === $fee && ! qhta_revenue_pmpro_gateway_has_fee( $gateway ) ) {
		// No processor, so no fee: a known zero.
		$fee = 0.0;
		$net = ( null === $net ) ? (float) $amount : $net;
	}

	if ( null !== $net && null === $fee ) {
		// A recorded net with no recorded fee still pins the fee down.
		$fee = (float) $amount - (float) $net;
	}

	/**
	 * Filter a membership order's resolved fee and net.
	 *
	 * The hook an optional Stripe-API backfill would use: given the order's
	 * payment_transaction_id, look up the charge's balance transaction and
	 * return its fee and net. Off by default — it needs a Stripe secret key.
	 *
	 * This is where the Stripe backfill in stripe-fees.php attaches — and for
	 * PMPro it is not a fallback but the only source, because PMPro records no
	 * processing fee of its own anywhere.
	 *
	 * @param array{0:float|null,1:float|null,2:array} $fee_net Fee, net and fee breakdown.
	 * @param object                                   $order   PMPro order row.
	 * @param array                                    $meta    Order meta.
	 * @param float                                    $amount  Order gross.
	 */
	return qhta_revenue_normalise_fee_net(
		apply_filters( 'qhta_revenue_membership_fee_net', array( $fee, $net, array() ), $order, $meta, $amount )
	);
}

/**
 * First of several meta keys that holds a usable number.
 *
 * An empty string is not zero — it means the key was never written — so only a
 * value that is actually numeric counts as an answer.
 *
 * @param array    $meta Meta key => value.
 * @param string[] $keys Candidate keys in preference order.
 * @return float|null
 */
function qhta_revenue_meta_number( $meta, $keys ) {
	foreach ( (array) $keys as $key ) {
		if ( isset( $meta[ $key ] ) && is_numeric( $meta[ $key ] ) ) {
			return (float) $meta[ $key ];
		}
	}

	return null;
}

/**
 * The fee-source diagnostic.
 *
 * Exists to answer one open question without guesswork: where does PMPro keep
 * the Stripe fee on *this* site? It lists every meta key stored against the
 * most recent Stripe membership orders. If a fee and a payout are in there, the
 * keys are on screen and can go straight into the
 * qhta_revenue_pmpro_fee_meta_keys filter; if the list comes back empty, PMPro
 * is fetching the figures live from Stripe and the only route to them is the
 * API backfill.
 *
 * Read-only and capability-gated like the rest of the screen. Shown only when
 * asked for with &qhta_revenue_diag=1.
 *
 * @return void
 */
function qhta_revenue_render_fee_diagnostic() {
	global $wpdb;

	if ( ! current_user_can( qhta_revenue_capability() ) ) {
		return;
	}

	echo '<div class="qhta-revenue-diag"><h2>' . esc_html__( 'Fee source diagnostic', 'qhta-revenue' ) . '</h2>';

	if ( ! qhta_revenue_pmpro_active() ) {
		echo '<p>' . esc_html__( 'PMPro is not active, so there are no membership orders to inspect.', 'qhta-revenue' ) . '</p></div>';
		return;
	}

	$orders_table = $wpdb->prefix . 'pmpro_membership_orders';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$orders = $wpdb->get_results(
		"SELECT id, code, total, gateway, timestamp FROM {$orders_table} WHERE gateway = 'stripe' ORDER BY timestamp DESC LIMIT 5"
	);

	if ( ! $orders ) {
		echo '<p>' . esc_html__( 'No Stripe membership orders found.', 'qhta-revenue' ) . '</p></div>';
		return;
	}

	$meta = qhta_revenue_pmpro_order_meta( wp_list_pluck( $orders, 'id' ) );

	echo '<p>' . esc_html__( 'Every meta key stored against the five most recent Stripe membership orders. A fee/payout key here can be added to the qhta_revenue_pmpro_fee_meta_keys filter; nothing here means PMPro reads the figures live from Stripe.', 'qhta-revenue' ) . '</p>';
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Order', 'qhta-revenue' ) . '</th>';
	echo '<th>' . esc_html__( 'Total', 'qhta-revenue' ) . '</th>';
	echo '<th>' . esc_html__( 'Meta stored against it', 'qhta-revenue' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $orders as $order ) {
		$order_meta = isset( $meta[ (int) $order->id ] ) ? $meta[ (int) $order->id ] : array();

		echo '<tr><td>' . esc_html( $order->code ) . '</td><td>' . esc_html( qhta_revenue_money( (float) $order->total ) ) . '</td><td>';

		if ( ! $order_meta ) {
			echo '<em>' . esc_html__( 'no meta rows', 'qhta-revenue' ) . '</em>';
		} else {
			echo '<ul style="margin:0">';
			foreach ( $order_meta as $key => $value ) {
				echo '<li><code>' . esc_html( $key ) . '</code> = ' . esc_html( $value ) . '</li>';
			}
			echo '</ul>';
		}

		echo '</td></tr>';
	}

	echo '</tbody></table></div>';
}
