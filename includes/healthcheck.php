<?php
/**
 * Healthcheck canaries for qhta-revenue.
 *
 * Registered on qhta-healthcheck's `qhta_healthcheck_checks` filter.
 *
 * This plugin's failure mode is unusually quiet even by QHTA standards: it does
 * not error when a dependency moves, it produces a WRONG NUMBER. A renamed PMPro
 * column yields an empty membership column; a missing Stripe key yields a page of
 * Unknown fees and an overstated net. Both look like a report, so the canaries
 * below are mostly about the integrity of the figures rather than about features
 * appearing.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register this plugin's canaries.
 *
 * @param array $checks Slug => list of check specs.
 * @return array
 */
function qhta_revenue_healthcheck_checks( $checks ) {
	$checks['qhta-revenue'] = array_merge(
		isset( $checks['qhta-revenue'] ) ? (array) $checks['qhta-revenue'] : array(),
		array(
			array(
				'id'       => 'pmpro-orders-table',
				'label'    => __( 'PMPro orders table and columns', 'qhta-revenue' ),
				'why'      => __( 'Membership income is read straight off this table by column name. A renamed column does not error — it produces an empty or wrong income figure, which is the one number this report exists to get right.', 'qhta-revenue' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_table(
						'pmpro_membership_orders',
						array( 'id', 'code', 'user_id', 'total', 'status', 'timestamp', 'gateway', 'payment_transaction_id' )
					);
				},
			),
			array(
				'id'       => 'woo-orders-api',
				'label'    => __( 'wc_get_orders() available', 'qhta-revenue' ),
				'why'      => __( 'Store income must be read through wc_get_orders(), never wp_posts — under HPOS the orders are not in wp_posts at all and a posts query returns nothing, silently. If this function goes, half the report goes with it.', 'qhta-revenue' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_functions( 'wc_get_orders' );
				},
			),
			array(
				'id'       => 'report-capability',
				'label'    => __( 'Report capability exists', 'qhta-revenue' ),
				'why'      => __( 'The screen asks for manage_woocommerce, which WooCommerce grants to administrator and shop_manager. If the administrator role has lost it the plugin falls back to manage_options and shop managers quietly lose access.', 'qhta-revenue' ),
				'severity' => 'warning',
				'test'     => function () {
					$role = get_role( 'administrator' );

					if ( ! $role ) {
						return qhta_healthcheck_fail( __( 'There is no administrator role.', 'qhta-revenue' ) );
					}

					if ( ! $role->has_cap( 'manage_woocommerce' ) ) {
						return qhta_healthcheck_fail( __( 'administrator does not hold manage_woocommerce — the report has fallen back to administrators only.', 'qhta-revenue' ) );
					}

					return qhta_healthcheck_pass( qhta_revenue_capability() );
				},
			),
			array(
				'id'       => 'stripe-key',
				'label'    => __( 'Stripe secret key resolvable', 'qhta-revenue' ),
				'why'      => __( 'Without a key the Stripe fee column is Unknown for every row and the net-banked total cannot be trusted. The key is read from PMPro\'s own options, so it also disappears if PMPro is switched from API keys to Connect, or between live and sandbox.', 'qhta-revenue' ),
				'severity' => 'warning',
				'test'     => function () {
					if ( '' === (string) qhta_revenue_stripe_key() ) {
						return qhta_healthcheck_fail( __( 'No Stripe secret key is available from PMPro settings, so every fee reads Unknown.', 'qhta-revenue' ) );
					}

					return qhta_healthcheck_pass(
						sprintf(
							/* translators: %s: gateway environment. */
							__( 'Key present (%s environment)', 'qhta-revenue' ),
							get_option( 'pmpro_gateway_environment', 'unknown' )
						)
					);
				},
			),
			array(
				'id'       => 'stripe-api',
				'label'    => __( 'Stripe API accepts the key', 'qhta-revenue' ),
				'why'      => __( 'A present key is not a working key. A rotated or revoked secret looks identical from the database side and only shows up as a page full of Unknown fees. One GET to /v1/balance a day settles it.', 'qhta-revenue' ),
				'severity' => 'warning',
				'remote'   => true,
				'test'     => function () {
					$key = (string) qhta_revenue_stripe_key();

					if ( '' === $key ) {
						return qhta_healthcheck_skip( __( 'No key to test.', 'qhta-revenue' ) );
					}

					return qhta_healthcheck_assert_api_reachable(
						'https://api.stripe.com/v1/balance',
						array( 'Authorization' => 'Bearer ' . $key ),
						'Stripe'
					);
				},
			),
			array(
				'id'       => 'currency-agreement',
				'label'    => __( 'PMPro and WooCommerce agree on currency', 'qhta-revenue' ),
				'why'      => __( 'The report adds membership and store amounts into one total. If the two systems are configured in different currencies that total is arithmetic on unlike units, and nothing anywhere would say so.', 'qhta-revenue' ),
				'severity' => 'warning',
				'test'     => function () {
					$woo   = (string) get_option( 'woocommerce_currency', '' );
					$pmpro = (string) get_option( 'pmpro_currency', '' );

					if ( '' === $woo || '' === $pmpro ) {
						return qhta_healthcheck_skip( __( 'Only one of the two systems has a currency set.', 'qhta-revenue' ) );
					}

					if ( $woo !== $pmpro ) {
						return qhta_healthcheck_fail(
							sprintf(
								/* translators: 1: WooCommerce currency, 2: PMPro currency. */
								__( 'WooCommerce is in %1$s, PMPro in %2$s — the combined total adds unlike currencies.', 'qhta-revenue' ),
								$woo,
								$pmpro
							)
						);
					}

					return qhta_healthcheck_pass( $woo );
				},
			),
		)
	);

	return $checks;
}
add_filter( 'qhta_healthcheck_checks', 'qhta_revenue_healthcheck_checks' );
