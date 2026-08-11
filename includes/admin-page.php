<?php
/**
 * The screen: menu registration, the filter form, the totals bar, the table.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the menu.
 *
 * A top-level menu rather than a WooCommerce submenu, because half the income
 * on this screen is not WooCommerce's — filing a combined report under one of
 * the two systems it reconciles would imply the other is secondary, and it
 * would vanish along with the WooCommerce menu the moment WooCommerce were
 * deactivated, taking the membership income with it.
 *
 * @return void
 */
function qhta_revenue_admin_menu() {
	add_menu_page(
		__( 'QHTA Income', 'qhta-revenue' ),
		__( 'QHTA Income', 'qhta-revenue' ),
		qhta_revenue_capability(),
		QHTA_REVENUE_SLUG,
		'qhta_revenue_render_page',
		'dashicons-money-alt',
		56
	);
}
add_action( 'admin_menu', 'qhta_revenue_admin_menu' );

/**
 * Load the screen's stylesheet, on the screen only.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function qhta_revenue_admin_assets( $hook ) {
	if ( 'toplevel_page_' . QHTA_REVENUE_SLUG !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'qhta-revenue-admin',
		QHTA_REVENUE_URL . 'assets/admin.css',
		array(),
		QHTA_REVENUE_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'qhta_revenue_admin_assets' );

/**
 * Render the report.
 *
 * @return void
 */
function qhta_revenue_render_page() {
	if ( ! current_user_can( qhta_revenue_capability() ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to view the income report.', 'qhta-revenue' ),
			esc_html__( 'Not allowed', 'qhta-revenue' ),
			array( 'response' => 403 )
		);
	}

	require_once QHTA_REVENUE_PATH . 'includes/list-table.php';

	$filters = qhta_revenue_current_filters();
	$rows    = qhta_revenue_get_rows( $filters );
	$totals  = qhta_revenue_totals( $rows );

	$table = new QHTA_Revenue_List_Table();
	$table->set_rows( $rows, $filters );
	$table->prepare_items();

	echo '<div class="wrap qhta-revenue">';
	echo '<h1>' . esc_html__( 'QHTA Income', 'qhta-revenue' ) . '</h1>';

	echo '<p class="qhta-revenue-intro">' . esc_html__( 'Membership income (Paid Memberships Pro) and store income (WooCommerce) in one list. This screen only reads — nothing here changes an order. It is an operational reconciliation view, not an accounting ledger.', 'qhta-revenue' ) . '</p>';

	qhta_revenue_render_dependency_notice();
	qhta_revenue_render_presets( $filters );
	qhta_revenue_render_filter_form( $filters );
	qhta_revenue_render_totals( $totals, $filters );

	$table->display();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic toggle.
	if ( ! empty( $_GET['qhta_revenue_diag'] ) ) {
		qhta_revenue_render_fee_diagnostic();
	} else {
		qhta_revenue_render_diagnostic_prompt( $totals );
	}

	echo '</div>';
}

/**
 * Explain any remaining unknown fees, and what to do about them.
 *
 * There are three quite different reasons a fee can still be Unknown, and they
 * need three different actions — so the footer names which one applies rather
 * than leaving a page of Unknowns with no explanation:
 *
 *   1. The Stripe lookup is unavailable (no usable key). Nothing will resolve
 *      until that is fixed, so say so instead of implying the data is missing.
 *   2. The lookup ran out of its per-request budget. Nothing is wrong; a reload
 *      continues the backlog, and each fetched fee is cached from then on.
 *   3. Everything that could be looked up was, and some orders still have no
 *      retrievable fee — a transaction id that is not a charge, or a charge
 *      settled in another currency.
 *
 * @param array $totals Totals from qhta_revenue_totals().
 * @return void
 */
function qhta_revenue_render_diagnostic_prompt( $totals ) {
	if ( empty( $totals['all']['unknown_fee'] ) ) {
		return;
	}

	$unknown = (int) $totals['all']['unknown_fee'];
	$budget  = qhta_revenue_stripe_budget_state();

	if ( ! qhta_revenue_stripe_enabled() ) {
		printf(
			'<p class="qhta-revenue-muted qhta-revenue-unknown">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: how many orders have an unresolved fee. */
					_n(
						'%d order has no fee figure, and Stripe cannot be asked for it: no usable Stripe key was found in PMPro\'s payment settings. Those orders contribute their gross but no net.',
						'%d orders have no fee figure, and Stripe cannot be asked for them: no usable Stripe key was found in PMPro\'s payment settings. Those orders contribute their gross but no net.',
						$unknown,
						'qhta-revenue'
					),
					$unknown
				)
			)
		);
		return;
	}

	if ( $budget['deferred'] > 0 ) {
		printf(
			'<p class="qhta-revenue-muted">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: how many fee lookups were deferred to a later page load. */
					_n(
						'%d fee is still to be fetched from Stripe — lookups are capped per page load so a wide date range cannot time out. Each one is cached once fetched.',
						'%d fees are still to be fetched from Stripe — lookups are capped per page load so a wide date range cannot time out. Each one is cached once fetched.',
						$budget['deferred'],
						'qhta-revenue'
					),
					$budget['deferred']
				)
			),
			esc_url( add_query_arg( 'qhta-revenue-continue', time() ) ),
			esc_html__( 'Fetch the next batch', 'qhta-revenue' )
		);
		return;
	}

	printf(
		'<p class="qhta-revenue-muted">%1$s <a href="%2$s">%3$s</a></p>',
		esc_html(
			sprintf(
				/* translators: %d: how many orders have an unresolved fee. */
				_n(
					'%d order still has no retrievable fee — usually a transaction id that is not a Stripe charge, or a charge settled in another currency. It contributes its gross but no net.',
					'%d orders still have no retrievable fee — usually a transaction id that is not a Stripe charge, or a charge settled in another currency. They contribute their gross but no net.',
					$unknown,
					'qhta-revenue'
				),
				$unknown
			)
		),
		esc_url( add_query_arg( 'qhta_revenue_diag', 1 ) ),
		esc_html__( 'Inspect what is stored against these orders', 'qhta-revenue' )
	);
}

/**
 * Warn when half the report cannot run.
 *
 * A missing dependency does not break this screen — it just silently halves the
 * income, which is worse than an error. Say so at the top.
 *
 * @return void
 */
function qhta_revenue_render_dependency_notice() {
	$missing = array();

	if ( ! qhta_revenue_pmpro_active() ) {
		$missing[] = __( 'Paid Memberships Pro is not active — membership income is missing from this report.', 'qhta-revenue' );
	}

	if ( ! qhta_revenue_woo_active() ) {
		$missing[] = __( 'WooCommerce is not active — store income is missing from this report.', 'qhta-revenue' );
	}

	foreach ( $missing as $message ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}

/**
 * The quick date presets, as links.
 *
 * Links rather than form controls so that choosing a preset and typing a custom
 * range are separate actions that cannot contradict each other — see the note
 * on qhta_revenue_date_presets(). Each link keeps the other filters (source,
 * status, search, sort) and replaces only the period, so switching month does
 * not throw away the rest of the view.
 *
 * @param array $filters Active filters.
 * @return void
 */
function qhta_revenue_render_presets( $filters ) {
	echo '<ul class="subsubsub qhta-revenue-presets">';

	$presets = qhta_revenue_date_presets();
	$last    = array_key_last( $presets );

	foreach ( $presets as $key => $label ) {
		// add_query_arg() drops any argument whose value is false, which is how
		// the stale range and page number are cleared: a preset supplies its own
		// dates, and page 4 of the old range means nothing in the new one.
		$url = add_query_arg(
			array(
				'page'        => QHTA_REVENUE_SLUG,
				'preset'      => $key,
				'source'      => $filters['source'],
				'status'      => $filters['status'],
				's'           => $filters['search'] ? $filters['search'] : false,
				'member_only' => $filters['member_only'] ? '1' : false,
				'orderby'     => $filters['orderby'],
				'order'       => $filters['order'],
				'from'        => false,
				'to'          => false,
				'paged'       => false,
			),
			admin_url( 'admin.php' )
		);

		printf(
			'<li><a href="%1$s" class="%2$s">%3$s</a>%4$s</li>',
			esc_url( $url ),
			esc_attr( $filters['preset'] === $key ? 'current' : '' ),
			esc_html( $label ),
			$key === $last ? '' : ' <span class="qhta-revenue-presets__sep">|</span>'
		);
	}

	echo '</ul>';
}

/**
 * The filter form.
 *
 * A GET form, so every filtered view is a URL that can be bookmarked, shared,
 * or handed to the export link unchanged.
 *
 * @param array $filters Active filters.
 * @return void
 */
function qhta_revenue_render_filter_form( $filters ) {
	echo '<form method="get" class="qhta-revenue-filters">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( QHTA_REVENUE_SLUG ) );

	// Submitting this form means "use the dates in these fields", so it always
	// posts the custom preset. Picking a preset is the separate link above.
	echo '<input type="hidden" name="preset" value="custom">';

	// Sort state travels with the filters so re-filtering does not silently
	// reset a column the user sorted by.
	printf( '<input type="hidden" name="orderby" value="%s">', esc_attr( $filters['orderby'] ) );
	printf( '<input type="hidden" name="order" value="%s">', esc_attr( $filters['order'] ) );

	echo '<div class="qhta-revenue-filters__row">';

	// The date fields show the resolved range whichever way it was arrived at,
	// so a preset's actual boundaries are always visible rather than implied.
	echo '<label class="qhta-revenue-field"><span>' . esc_html__( 'From', 'qhta-revenue' ) . '</span>';
	printf( '<input type="date" name="from" value="%s">', esc_attr( $filters['from'] ) );
	echo '</label>';

	echo '<label class="qhta-revenue-field"><span>' . esc_html__( 'To', 'qhta-revenue' ) . '</span>';
	printf( '<input type="date" name="to" value="%s">', esc_attr( $filters['to'] ) );
	echo '</label>';

	// Source.
	echo '<label class="qhta-revenue-field"><span>' . esc_html__( 'Source', 'qhta-revenue' ) . '</span>';
	echo '<select name="source">';
	$sources = array(
		'both'       => __( 'Both', 'qhta-revenue' ),
		'membership' => __( 'Membership only', 'qhta-revenue' ),
		'store'      => __( 'Store only', 'qhta-revenue' ),
	);
	foreach ( $sources as $key => $label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $key ),
			selected( $filters['source'], $key, false ),
			esc_html( $label )
		);
	}
	echo '</select></label>';

	// Status.
	echo '<label class="qhta-revenue-field"><span>' . esc_html__( 'Status', 'qhta-revenue' ) . '</span>';
	echo '<select name="status">';
	printf(
		'<option value="all"%1$s>%2$s</option>',
		selected( $filters['status'], 'all', false ),
		esc_html__( 'All statuses', 'qhta-revenue' )
	);
	foreach ( qhta_revenue_status_map() as $key => $spec ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $key ),
			selected( $filters['status'], $key, false ),
			esc_html( $spec['label'] )
		);
	}
	echo '</select></label>';

	// Search.
	echo '<label class="qhta-revenue-field"><span>' . esc_html__( 'Name or email', 'qhta-revenue' ) . '</span>';
	printf(
		'<input type="search" name="s" value="%1$s" placeholder="%2$s">',
		esc_attr( $filters['search'] ),
		esc_attr__( 'Search customers', 'qhta-revenue' )
	);
	echo '</label>';

	// Member only.
	echo '<label class="qhta-revenue-field qhta-revenue-field--check">';
	printf( '<input type="checkbox" name="member_only" value="1"%s>', checked( $filters['member_only'], true, false ) );
	echo '<span>' . esc_html__( 'Members only', 'qhta-revenue' ) . '</span></label>';

	echo '<div class="qhta-revenue-field qhta-revenue-field--actions">';
	submit_button( __( 'Filter', 'qhta-revenue' ), 'secondary', '', false );
	printf(
		' <a class="button button-primary" href="%1$s">%2$s</a>',
		esc_url( qhta_revenue_export_url( $filters ) ),
		esc_html__( 'Export CSV', 'qhta-revenue' )
	);
	echo '</div>';

	echo '</div></form>';
}

/**
 * The totals bar.
 *
 * Reflects the active filters, not the visible page — "Paid, last month, Store
 * only" shows the store's paid gross, fees and net for last month.
 *
 * @param array $totals  Totals from qhta_revenue_totals().
 * @param array $filters Active filters.
 * @return void
 */
function qhta_revenue_render_totals( $totals, $filters ) {
	$range = qhta_revenue_range_label( $filters );

	echo '<div class="qhta-revenue-totals">';
	printf(
		'<h2>%s</h2>',
		esc_html(
			sprintf(
				/* translators: %s: the date range being reported on. */
				__( 'Totals — %s', 'qhta-revenue' ),
				$range
			)
		)
	);

	echo '<table class="widefat qhta-revenue-totals__table"><thead><tr>';
	echo '<th>' . esc_html__( 'Source', 'qhta-revenue' ) . '</th>';
	echo '<th>' . esc_html__( 'Orders', 'qhta-revenue' ) . '</th>';
	echo '<th>' . esc_html__( 'Gross', 'qhta-revenue' ) . '</th>';
	echo '<th>' . esc_html__( 'Stripe fees', 'qhta-revenue' ) . '</th>';
	echo '<th>' . esc_html__( 'Net banked', 'qhta-revenue' ) . '</th>';
	echo '</tr></thead><tbody>';

	$labels = array(
		'membership' => __( 'Membership', 'qhta-revenue' ),
		'store'      => __( 'Store', 'qhta-revenue' ),
		'all'        => __( 'Total', 'qhta-revenue' ),
	);

	foreach ( $labels as $key => $label ) {
		$bucket = $totals[ $key ];
		$class  = ( 'all' === $key ) ? ' class="qhta-revenue-totals__grand"' : '';

		// A row's net is only meaningful for the orders whose fee is known, so
		// the gross shown beside it is the whole filtered set while the net is
		// not — hence the unknown count spelled out on the same line rather
		// than a footnote somewhere else.
		printf(
			'<tr%1$s><th scope="row">%2$s</th><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s%7$s</td></tr>',
			$class, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above.
			esc_html( $label ),
			esc_html( number_format_i18n( $bucket['count'] ) ),
			esc_html( qhta_revenue_money( $bucket['gross'] ) ),
			esc_html( qhta_revenue_money( $bucket['fees'] ) ),
			esc_html( qhta_revenue_money( $bucket['net'] ) ),
			$bucket['unknown_fee']
				? ' <span class="qhta-revenue-unknown">' . esc_html(
					sprintf(
						/* translators: %d: how many orders have no recorded Stripe fee. */
						_n(
							'net of known fees — %d order has no recorded fee',
							'net of known fees — %d orders have no recorded fee',
							$bucket['unknown_fee'],
							'qhta-revenue'
						),
						$bucket['unknown_fee']
					)
				) . '</span>'
				: ''
		);
	}

	echo '</tbody></table>';

	// PMPro's free Stripe Connect integration adds its own 2% on top of
	// Stripe's processing fee, out of the same payout. It is worth calling out
	// separately rather than burying inside "fees", because unlike a card
	// network's cut it is removable — a premium PMPro licence sets it to zero.
	if ( ! empty( $totals['all']['platform_fee'] ) ) {
		printf(
			'<p class="qhta-revenue-muted">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: total platform fee for the filtered set. */
					__( 'Of those fees, %s is PMPro\'s own platform fee rather than Stripe\'s — that component is charged by the free Stripe Connect integration and goes away on a premium PMPro licence.', 'qhta-revenue' ),
					qhta_revenue_money( $totals['all']['platform_fee'] )
				)
			)
		);
	}

	echo '<p class="qhta-revenue-muted">' . esc_html__( 'Gross is the order total as recorded, including tax, in AUD. Net is gross minus the fee Stripe actually deducted; orders whose fee could not be determined contribute their gross but no net, and are counted above. Refunds are not subtracted — refunded orders appear under the Refunded status.', 'qhta-revenue' ) . '</p>';
	echo '</div>';
}

/**
 * Human label for the active date range.
 *
 * @param array $filters Active filters.
 * @return string
 */
function qhta_revenue_range_label( $filters ) {
	if ( '' === $filters['from'] && '' === $filters['to'] ) {
		return __( 'all time', 'qhta-revenue' );
	}

	if ( '' === $filters['to'] ) {
		/* translators: %s: start date. */
		return sprintf( __( 'from %s', 'qhta-revenue' ), $filters['from'] );
	}

	if ( '' === $filters['from'] ) {
		/* translators: %s: end date. */
		return sprintf( __( 'up to %s', 'qhta-revenue' ), $filters['to'] );
	}

	/* translators: 1: start date, 2: end date. */
	return sprintf( __( '%1$s to %2$s', 'qhta-revenue' ), $filters['from'], $filters['to'] );
}
