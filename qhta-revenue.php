<?php
/**
 * Plugin Name:       QHTA Revenue
 * Description:       Admin-only, read-only combined income report for qhta.com.au — merges PMPro membership orders and WooCommerce store orders into one filterable, exportable table, with a Member? flag on store buyers and the Stripe fee and net banked per order. No writes, no front-end, no invoices.
 * Version:           1.3.0
 * Author:            QHTA
 * License:           GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * Scope rule: admin-only READ-ONLY reporting across PMPro + WooCommerce.
 * Reads PMPro via $wpdb on pmpro_membership_orders; reads Woo via wc_get_orders() (HPOS-safe).
 * Writes nothing. Invoices → qhta-pmpro-invoice-extensions; gate → qhta-commerce;
 * account front-end → qhta-membership; presentation → qhta-theme-extras.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QHTA_REVENUE_VERSION', '1.3.0' );
define( 'QHTA_REVENUE_PATH', plugin_dir_path( __FILE__ ) );
define( 'QHTA_REVENUE_URL', plugin_dir_url( __FILE__ ) );
define( 'QHTA_REVENUE_SLUG', 'qhta-revenue' );


/* -------------------------------------------------------------------------
 * Why this plugin exists
 *
 * Membership income and store income live on two admin screens that never meet
 * — PMPro's Memberships → Orders and WooCommerce → Orders — so "how much came in
 * last month, across both?" could only be answered by exporting each and
 * reconciling by hand. This merges the two streams into one normalised table.
 *
 * Two rules govern everything below, and both were learned the hard way:
 *
 *   1. READ EACH STREAM THROUGH ITS OWN MECHANISM. WooCommerce orders are read
 *      only via wc_get_orders()/WC_Order, never raw SQL against wp_posts — under
 *      HPOS the orders are not in wp_posts and a wp_posts query returns nothing
 *      at all, silently. PMPro orders are read via $wpdb on its own tables,
 *      because they are not WooCommerce orders and wc_get_orders() cannot see
 *      them. The two sets only ever meet in PHP, after both have been
 *      normalised. There is deliberately no SQL union.
 *
 *   2. NOTHING HERE CHANGES A RECORD. No order, member, product, user or setting
 *      is created or modified by any code path in this plugin, including the CSV
 *      export. Anything that mutates belongs in another QHTA plugin — invoices
 *      in qhta-pmpro-invoice-extensions, the purchase gate in qhta-commerce.
 *      That is why no screen option is registered for the per-page count: doing
 *      so would write a user meta row against the viewer, and "changes nothing"
 *      is worth more than a configurable page size.
 *
 *      The single exception, added in 1.1.0, is the Stripe fee cache in
 *      includes/stripe-fees.php: fee lookups are cached in transients, because
 *      a settled charge's fee is immutable and re-fetching it on every page
 *      load would be slow and pointless. That is a cache, not data — deleting
 *      every one of those transients loses nothing but speed, and no business
 *      record is touched either way.
 *
 * One file per concern under includes/, split along the line that actually
 * matters here — the two different data-access mechanisms.
 * ---------------------------------------------------------------------- */


/**
 * Which capability may see the report.
 *
 * `manage_woocommerce` is the intent: shop managers as well as administrators,
 * because this is a shop-facing operational report rather than a site setting.
 *
 * The fallback exists because that capability is not part of WordPress — it is
 * granted to the administrator and shop_manager roles by WooCommerce when it
 * installs. On a site where WooCommerce has never been installed, nobody holds
 * it, and defaulting to it would lock every user out of a screen that would
 * still have membership income to show. So the administrator role is asked
 * whether the capability exists at all, and if it does not the screen falls back
 * to `manage_options` (administrators only) rather than to nobody.
 *
 * Note this is deliberately not "does the *current user* have it" — that would
 * silently widen access to any admin the moment WooCommerce was deactivated.
 * Deactivating WooCommerce does not remove the capability from the role (it is
 * stored in the database), so an established site keeps shop managers' access
 * across a deactivation, which is what acceptance criterion 8 wants.
 *
 * @return string Capability required to view and export the report.
 */
function qhta_revenue_capability() {
	$cap  = 'manage_woocommerce';
	$role = get_role( 'administrator' );

	if ( ! $role || ! $role->has_cap( $cap ) ) {
		$cap = 'manage_options';
	}

	/**
	 * Filter the capability required to view the QHTA Income report.
	 *
	 * Set to 'manage_options' to restrict the screen to administrators.
	 *
	 * @param string $cap Capability.
	 */
	return (string) apply_filters( 'qhta_revenue_capability', $cap );
}

/**
 * Is Paid Memberships Pro present, with its orders table available to read?
 *
 * The table is tested rather than the plugin, because that is the thing this
 * plugin actually touches: PMPro could be active but mid-install, or the tables
 * could survive a deactivation. Cached per request — it is a SHOW TABLES round
 * trip and the answer cannot change mid-request.
 *
 * @return bool
 */
function qhta_revenue_pmpro_active() {
	static $active = null;

	if ( null !== $active ) {
		return $active;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'pmpro_membership_orders';

	// esc_like() because the prefix contains underscores, which LIKE reads as
	// single-character wildcards.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	$active = ( $found === $table );

	return $active;
}

/**
 * Is WooCommerce present and booted far enough to query orders?
 *
 * @return bool
 */
function qhta_revenue_woo_active() {
	return function_exists( 'wc_get_orders' );
}

/**
 * The normalised status vocabulary.
 *
 * PMPro and WooCommerce each have their own status words, and neither maps onto
 * the other. Reporting on the raw words would mean "paid" meant two different
 * things in one table and the totals could not be trusted, so both are folded
 * onto the small shared set below.
 *
 * `processing` counts as Paid alongside `completed` because on this site it
 * means the money has been taken and the goods are not yet delivered — the
 * payment happened either way, and this report is about income received rather
 * than fulfilment. `on-hold` deliberately does not: it is Woo's "awaiting
 * payment" state.
 *
 * A status in neither list (a custom one added by another plugin) is not an
 * error — it falls through to 'other' and keeps its raw label, so it is visible
 * under the All filter rather than being dropped.
 *
 * @return array<string,array{label:string,pmpro:string[],woo:string[]}>
 */
function qhta_revenue_status_map() {
	$map = array(
		'paid'     => array(
			'label' => __( 'Paid', 'qhta-revenue' ),
			'pmpro' => array( 'success' ),
			'woo'   => array( 'completed', 'processing' ),
		),
		'refunded' => array(
			'label' => __( 'Refunded', 'qhta-revenue' ),
			'pmpro' => array( 'refunded' ),
			'woo'   => array( 'refunded' ),
		),
		'pending'  => array(
			'label' => __( 'Pending', 'qhta-revenue' ),
			'pmpro' => array( 'pending', 'review', 'token' ),
			'woo'   => array( 'pending', 'on-hold' ),
		),
		'failed'   => array(
			'label' => __( 'Failed / Cancelled', 'qhta-revenue' ),
			'pmpro' => array( 'error', 'cancelled' ),
			'woo'   => array( 'failed', 'cancelled' ),
		),
	);

	/**
	 * Filter the raw-status → normalised-status mapping.
	 *
	 * @param array $map Status map.
	 */
	return (array) apply_filters( 'qhta_revenue_status_map', $map );
}

/**
 * Fold a raw source status onto the shared vocabulary.
 *
 * @param string $source 'pmpro' or 'woo'.
 * @param string $raw    Raw status from that system, with no 'wc-' prefix.
 * @return string Normalised key, or 'other' when unrecognised.
 */
function qhta_revenue_normalise_status( $source, $raw ) {
	$raw = (string) preg_replace( '/^wc-/', '', strtolower( (string) $raw ) );

	foreach ( qhta_revenue_status_map() as $key => $spec ) {
		if ( in_array( $raw, (array) $spec[ $source ], true ) ) {
			return $key;
		}
	}

	return 'other';
}

/**
 * Human label for a normalised status.
 *
 * @param string $key       Normalised key.
 * @param string $raw       Raw source status, used as the label for 'other'.
 * @return string
 */
function qhta_revenue_status_label( $key, $raw = '' ) {
	$map = qhta_revenue_status_map();

	if ( isset( $map[ $key ]['label'] ) ) {
		return (string) $map[ $key ]['label'];
	}

	return $raw ? ucwords( str_replace( array( '-', '_' ), ' ', $raw ) ) : __( 'Other', 'qhta-revenue' );
}

/**
 * Currency symbol for display.
 *
 * Everything on this site is AUD. WooCommerce is asked when it is available so
 * the report follows the shop rather than a hardcoded symbol, with a plain '$'
 * fallback for when it is not.
 *
 * @return string
 */
function qhta_revenue_currency_symbol() {
	if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
		return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
	}

	return '$';
}

/**
 * Format an amount for the screen.
 *
 * A null amount means "not known" rather than zero — an em dash, never $0.00.
 * Treating an unknown Stripe fee as zero would overstate the net income, which
 * is the one number this report exists to get right.
 *
 * @param float|null $amount Amount, or null when unknown.
 * @param string     $unknown Text to show when unknown.
 * @return string
 */
function qhta_revenue_money( $amount, $unknown = '—' ) {
	if ( null === $amount ) {
		return $unknown;
	}

	return qhta_revenue_currency_symbol() . number_format_i18n( (float) $amount, 2 );
}

/**
 * The site's timezone, which every date in this report is expressed in.
 *
 * @return DateTimeZone
 */
function qhta_revenue_timezone() {
	return wp_timezone();
}

/**
 * Validate a Y-m-d string from a request.
 *
 * Returns '' for anything that is not a real calendar date, so a junk value in
 * the URL widens the range to unbounded rather than producing an empty report
 * that looks like "no income".
 *
 * @param mixed $value Raw request value.
 * @return string 'Y-m-d' or ''.
 */
function qhta_revenue_sanitise_date( $value ) {
	$value = sanitize_text_field( (string) $value );

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
		return '';
	}

	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return '';
	}

	return $value;
}

require_once QHTA_REVENUE_PATH . 'includes/report.php';
require_once QHTA_REVENUE_PATH . 'includes/member-flag.php';
require_once QHTA_REVENUE_PATH . 'includes/data-pmpro.php';
require_once QHTA_REVENUE_PATH . 'includes/data-woo.php';
require_once QHTA_REVENUE_PATH . 'includes/stripe-fees.php';
require_once QHTA_REVENUE_PATH . 'includes/export-csv.php';
require_once QHTA_REVENUE_PATH . 'includes/admin-page.php';
require_once QHTA_REVENUE_PATH . 'includes/healthcheck.php';
