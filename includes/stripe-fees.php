<?php
/**
 * Stripe fee backfill — asking Stripe what a charge actually cost.
 *
 * WHY THIS EXISTS
 *
 * PMPro never records the Stripe processing fee. Not under a meta key this
 * plugin failed to guess — it does not record it at all: there is no
 * balance-transaction lookup anywhere in PMPro's own gateway code, and nothing
 * fee-related on its orders screen. So for membership orders there is no stored
 * figure to read, and the Stripe API is not the convenient route to the fee, it
 * is the only one. (WooCommerce is different: its Stripe gateway does store
 * `_stripe_fee`, so store rows only reach this file when that meta is missing —
 * historic orders, or ones taken by a different gateway plugin.)
 *
 * WHAT IT READS
 *
 * The charge's **balance transaction**, which is Stripe's own record of what it
 * deducted. Its `fee` is the all-in deduction and `fee_details` breaks that
 * into components. That matters here beyond pedantry: PMPro's free Stripe
 * Connect integration adds its own **2% application fee** on top of Stripe's
 * processing fee (see PMProGateway_stripe::get_application_fee_percentage() —
 * it returns 0 only on manual API keys or a premium licence, and Australia is
 * not in the country list where it is disabled). That 2% comes out of the same
 * payout. A stored meta key could never have shown it; the balance transaction
 * does, as an `application_fee` line, which is why the fee cell breaks the
 * total down on hover.
 *
 * WHAT IT WRITES
 *
 * Transients, and nothing else. This is the only file in the plugin that writes
 * anything at all, and it is a cache of figures fetched from Stripe: a settled
 * charge's fee never changes, so re-fetching it on every page load would be
 * both slow and pointless. No order, member, user or setting is ever touched.
 * Deleting every one of these transients loses nothing but speed.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long a resolved fee is cached.
 *
 * Long, because it is immutable: once a charge has settled, what Stripe took
 * out of it is history. The cache exists to avoid re-asking, not to stay fresh.
 *
 * @return int Seconds.
 */
function qhta_revenue_stripe_cache_ttl() {
	return (int) apply_filters( 'qhta_revenue_stripe_cache_ttl', 30 * DAY_IN_SECONDS );
}

/**
 * How long a *failed* lookup is remembered.
 *
 * Short, and deliberately non-zero. Without it, a Stripe outage or a revoked
 * key would mean every page load retried every uncached order and the screen
 * would hang for as long as the problem lasted. An hour is long enough to stop
 * a retry storm and short enough that fixing the key shows up quickly.
 *
 * @return int Seconds.
 */
function qhta_revenue_stripe_miss_ttl() {
	return (int) apply_filters( 'qhta_revenue_stripe_miss_ttl', HOUR_IN_SECONDS );
}

/**
 * How many live lookups one page load may make.
 *
 * A ceiling, not a target — cached rows do not count against it. It stops a
 * wide date range full of uncached orders from turning one page load into
 * hundreds of sequential HTTP requests and a timeout. Rows past the ceiling
 * keep their Unknown fee and the screen says how many are still to do, so
 * reloading walks through the backlog instead of failing.
 *
 * @return int
 */
function qhta_revenue_stripe_lookup_budget() {
	return (int) apply_filters( 'qhta_revenue_stripe_lookup_budget', 25 );
}

/**
 * How many lookups this request has spent, and how many it wanted.
 *
 * @param bool $deferred Count a lookup that was skipped for want of budget.
 * @return array{spent:int,deferred:int}
 */
function qhta_revenue_stripe_budget_state( $deferred = null ) {
	static $state = array(
		'spent'    => 0,
		'deferred' => 0,
	);

	if ( true === $deferred ) {
		++$state['deferred'];
	} elseif ( false === $deferred ) {
		++$state['spent'];
	}

	return $state;
}

/**
 * The Stripe secret key to read with.
 *
 * Reuses the credentials PMPro already holds, so there is no second key to
 * create, store or rotate — and because the site runs both Stripe connections
 * against one Stripe account, PMPro's key can look up a WooCommerce charge as
 * readily as a membership one.
 *
 * The options are read directly rather than through
 * PMProGateway_stripe::get_secretkey(), which has been **private** since PMPro
 * 3.0 and cannot be called from outside the class. The logic below is that
 * method's, against the same public option names; `using_api_keys()` is still
 * public and static, so it is asked rather than reimplemented when available.
 *
 * This plugin only ever issues GET requests with this key. If you would rather
 * it used a Stripe *restricted* key scoped to read Charges, PaymentIntents and
 * Balance transactions, return it from the filter below — nothing else changes.
 *
 * @return string Secret key, or '' when none is available.
 */
function qhta_revenue_stripe_key() {
	$key = '';

	$using_api_keys = class_exists( 'PMProGateway_stripe' )
		? PMProGateway_stripe::using_api_keys()
		: ( get_option( 'pmpro_stripe_secretkey' ) && get_option( 'pmpro_stripe_publishablekey' ) );

	if ( $using_api_keys ) {
		$key = (string) get_option( 'pmpro_stripe_secretkey' );
	} else {
		$key = 'live' === get_option( 'pmpro_gateway_environment' )
			? (string) get_option( 'pmpro_live_stripe_connect_secretkey' )
			: (string) get_option( 'pmpro_sandbox_stripe_connect_secretkey' );
	}

	/**
	 * Filter the Stripe secret key used for fee lookups.
	 *
	 * Return '' to switch the backfill off entirely, or a restricted read-only
	 * key to use that instead of PMPro's.
	 *
	 * @param string $key Secret key.
	 */
	return (string) apply_filters( 'qhta_revenue_stripe_key', $key );
}

/**
 * Is the backfill usable?
 *
 * @return bool
 */
function qhta_revenue_stripe_enabled() {
	return '' !== qhta_revenue_stripe_key();
}

/**
 * The currency the report expects, lower-cased as Stripe writes it.
 *
 * @return string
 */
function qhta_revenue_expected_currency() {
	$currency = get_option( 'woocommerce_currency' );

	if ( ! $currency ) {
		$currency = get_option( 'pmpro_currency' );
	}

	return strtolower( $currency ? $currency : 'AUD' );
}

/**
 * Currencies Stripe quotes in whole units rather than cents.
 *
 * @return string[]
 */
function qhta_revenue_zero_decimal_currencies() {
	return array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
}

/**
 * Convert a Stripe minor-unit amount to a decimal one.
 *
 * @param int    $amount   Amount in the currency's smallest unit.
 * @param string $currency Lower-case ISO code.
 * @return float
 */
function qhta_revenue_from_minor_units( $amount, $currency ) {
	if ( in_array( strtolower( $currency ), qhta_revenue_zero_decimal_currencies(), true ) ) {
		return (float) $amount;
	}

	return round( (float) $amount / 100, 2 );
}

/**
 * The Stripe endpoint that will yield a balance transaction for a stored id.
 *
 * PMPro and WooCommerce both store whatever the gateway handed back, and that
 * has changed shape over the years — a charge id on older orders, a
 * PaymentIntent id on newer ones, occasionally an invoice id on a renewal. Each
 * needs a different expansion to reach the same balance transaction, so the id
 * prefix decides the route. An id of any other shape (a `sub_` subscription,
 * say) has no single charge behind it and is not looked up at all.
 *
 * @param string $txn_id Stored transaction id.
 * @return array{0:string,1:string[]}|null Path and expand list, or null.
 */
function qhta_revenue_stripe_endpoint( $txn_id ) {
	if ( 0 === strpos( $txn_id, 'ch_' ) || 0 === strpos( $txn_id, 'py_' ) ) {
		return array( 'charges/' . $txn_id, array( 'balance_transaction' ) );
	}

	if ( 0 === strpos( $txn_id, 'pi_' ) ) {
		return array( 'payment_intents/' . $txn_id, array( 'latest_charge.balance_transaction' ) );
	}

	if ( 0 === strpos( $txn_id, 'in_' ) ) {
		return array( 'invoices/' . $txn_id, array( 'charge.balance_transaction' ) );
	}

	return null;
}

/**
 * Dig the balance transaction out of whichever object came back.
 *
 * @param array $body Decoded Stripe response.
 * @return array|null
 */
function qhta_revenue_extract_balance_transaction( $body ) {
	$candidates = array(
		isset( $body['balance_transaction'] ) ? $body['balance_transaction'] : null,
		isset( $body['latest_charge']['balance_transaction'] ) ? $body['latest_charge']['balance_transaction'] : null,
		isset( $body['charge']['balance_transaction'] ) ? $body['charge']['balance_transaction'] : null,
	);

	foreach ( $candidates as $candidate ) {
		// An unexpanded balance transaction comes back as a bare id string,
		// which carries no fee — only the expanded object is usable.
		if ( is_array( $candidate ) && isset( $candidate['fee'] ) ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Look up what Stripe deducted from one charge.
 *
 * Returns null for every failure — no key, an id that is not a charge, a
 * network error, a currency that is not the one the report totals in — because
 * a failed lookup must leave the fee **Unknown**, never zero. Guessing here
 * would defeat the whole point of the Unknown state.
 *
 * @param string $txn_id Stored transaction id.
 * @return array{fee:float,net:float,currency:string,breakdown:array<string,float>}|null
 */
function qhta_revenue_stripe_fee_lookup( $txn_id ) {
	$txn_id = trim( (string) $txn_id );

	if ( '' === $txn_id || ! qhta_revenue_stripe_enabled() ) {
		return null;
	}

	$endpoint = qhta_revenue_stripe_endpoint( $txn_id );

	if ( ! $endpoint ) {
		return null;
	}

	$cache_key = 'qhta_rev_fee_' . md5( $txn_id );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return isset( $cached['miss'] ) ? null : $cached;
	}

	// Budget check happens after the cache, so a cached row is free.
	$budget = qhta_revenue_stripe_budget_state();

	if ( $budget['spent'] >= qhta_revenue_stripe_lookup_budget() ) {
		qhta_revenue_stripe_budget_state( true );
		return null;
	}

	qhta_revenue_stripe_budget_state( false );

	list( $path, $expand ) = $endpoint;

	$url = add_query_arg(
		array( 'expand' => $expand ),
		'https://api.stripe.com/v1/' . $path
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 8,
			'headers' => array(
				'Authorization'  => 'Bearer ' . qhta_revenue_stripe_key(),
				'Stripe-Version' => '2020-08-27',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $cache_key, array( 'miss' => true ), qhta_revenue_stripe_miss_ttl() );
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$txn  = is_array( $body ) ? qhta_revenue_extract_balance_transaction( $body ) : null;

	if ( ! $txn ) {
		set_transient( $cache_key, array( 'miss' => true ), qhta_revenue_stripe_miss_ttl() );
		return null;
	}

	$currency = strtolower( isset( $txn['currency'] ) ? $txn['currency'] : '' );

	// A balance transaction is denominated in the *settlement* currency. If that
	// is not the currency the order is in, its fee and net cannot be subtracted
	// from the order's gross without a conversion this report does not do — so
	// the honest answer is Unknown rather than a number that looks right.
	if ( $currency !== qhta_revenue_expected_currency() ) {
		set_transient( $cache_key, array( 'miss' => true ), qhta_revenue_stripe_cache_ttl() );
		return null;
	}

	$breakdown = array();

	foreach ( (array) ( isset( $txn['fee_details'] ) ? $txn['fee_details'] : array() ) as $detail ) {
		if ( ! isset( $detail['type'], $detail['amount'] ) ) {
			continue;
		}

		$type               = (string) $detail['type'];
		$breakdown[ $type ] = ( isset( $breakdown[ $type ] ) ? $breakdown[ $type ] : 0.0 )
			+ qhta_revenue_from_minor_units( $detail['amount'], $currency );
	}

	$result = array(
		'fee'       => qhta_revenue_from_minor_units( isset( $txn['fee'] ) ? $txn['fee'] : 0, $currency ),
		'net'       => qhta_revenue_from_minor_units( isset( $txn['net'] ) ? $txn['net'] : 0, $currency ),
		'currency'  => $currency,
		'breakdown' => $breakdown,
	);

	set_transient( $cache_key, $result, qhta_revenue_stripe_cache_ttl() );

	return $result;
}

/**
 * Fill in a membership order's fee from Stripe when nothing was recorded.
 *
 * For PMPro this is not a fallback — it is the only path, because PMPro records
 * no fee at all.
 *
 * @param array  $fee_net Fee and net so far, null when unknown.
 * @param object $order   PMPro order row.
 * @return array
 */
function qhta_revenue_backfill_membership_fee( $fee_net, $order ) {
	if ( null !== $fee_net[0] ) {
		return $fee_net; // Already known — never overwrite a recorded figure.
	}

	$txn_id = isset( $order->payment_transaction_id ) ? (string) $order->payment_transaction_id : '';
	$looked = qhta_revenue_stripe_fee_lookup( $txn_id );

	if ( ! $looked ) {
		return $fee_net;
	}

	return array( $looked['fee'], null, $looked['breakdown'] );
}
add_filter( 'qhta_revenue_membership_fee_net', 'qhta_revenue_backfill_membership_fee', 10, 2 );

/**
 * Fill in a store order's fee from Stripe when the gateway recorded none.
 *
 * @param array    $fee_net Fee and net so far, null when unknown.
 * @param WC_Order $order   Order.
 * @return array
 */
function qhta_revenue_backfill_store_fee( $fee_net, $order ) {
	if ( null !== $fee_net[0] ) {
		return $fee_net;
	}

	$looked = qhta_revenue_stripe_fee_lookup( (string) $order->get_transaction_id() );

	if ( ! $looked ) {
		return $fee_net;
	}

	return array( $looked['fee'], null, $looked['breakdown'] );
}
add_filter( 'qhta_revenue_store_fee_net', 'qhta_revenue_backfill_store_fee', 10, 2 );

/**
 * Human summary of a fee breakdown, for the fee cell's tooltip.
 *
 * @param array $breakdown Fee type => amount.
 * @return string
 */
function qhta_revenue_fee_breakdown_label( $breakdown ) {
	if ( ! $breakdown ) {
		return '';
	}

	$labels = array(
		'stripe_fee'     => __( 'Stripe', 'qhta-revenue' ),
		'application_fee' => __( 'PMPro platform fee', 'qhta-revenue' ),
		'tax'            => __( 'tax', 'qhta-revenue' ),
	);

	$parts = array();

	foreach ( $breakdown as $type => $amount ) {
		$label   = isset( $labels[ $type ] ) ? $labels[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
		$parts[] = $label . ' ' . qhta_revenue_money( $amount );
	}

	return implode( ' + ', $parts );
}
