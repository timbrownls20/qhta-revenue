<?php
/**
 * Store income — read through WooCommerce's own API.
 *
 * Everything here goes through wc_get_orders() and WC_Order getters, and
 * nothing anywhere in this plugin queries wp_posts or wp_postmeta for an order.
 * That is not a style preference: with High-Performance Order Storage enabled
 * WooCommerce orders are not posts, so a wp_posts query returns an empty set —
 * not an error, an empty set — and the report would show no store income at all
 * while looking like it had worked. wc_get_orders() reads whichever storage the
 * site is actually on.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store order rows for the active filters.
 *
 * @param array $filters Filter state.
 * @return array[] Normalised rows.
 */
function qhta_revenue_get_store_rows( $filters ) {
	if ( ! qhta_revenue_woo_active() ) {
		return array(); // WooCommerce absent → no store income to report, no fatal.
	}

	$args = array(
		'limit'   => -1,
		'type'    => 'shop_order', // Excludes refund objects, which are orders too.
		'orderby' => 'date',
		'order'   => 'DESC',
	);

	$statuses = qhta_revenue_raw_statuses( 'woo', $filters['status'] );

	if ( is_array( $statuses ) ) {
		if ( ! $statuses ) {
			return array(); // A normalised status with no WooCommerce equivalent.
		}

		$args['status'] = $statuses;
	} else {
		// "All" means every status this shop actually has, including any a
		// third-party plugin registered — but not `checkout-draft`, which is an
		// abandoned basket rather than an order and would pad the row count
		// with income that was never taken.
		$args['status'] = array_diff(
			array_map(
				static function ( $status ) {
					return preg_replace( '/^wc-/', '', $status );
				},
				array_keys( wc_get_order_statuses() )
			),
			array( 'checkout-draft' )
		);
	}

	$range = qhta_revenue_woo_date_range( $filters['from'], $filters['to'] );

	if ( $range ) {
		$args['date_created'] = $range;
	}

	$orders = wc_get_orders( $args );

	if ( ! $orders ) {
		return array();
	}

	$rows = array();

	foreach ( $orders as $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			continue; // 'return' => 'ids' was not asked for, but be certain.
		}

		$rows[] = qhta_revenue_normalise_store_order( $order );
	}

	return $rows;
}

/**
 * Build WooCommerce's date_created range string.
 *
 * WooCommerce reads a bare `Y-m-d` as a site-local date and the `...` form as an
 * inclusive range, which is exactly the semantics the filter form promises. An
 * open end is expressed with `>=` / `<=` rather than by inventing a boundary
 * date.
 *
 * @param string $from Site-local Y-m-d, or ''.
 * @param string $to   Site-local Y-m-d, or ''.
 * @return string Range expression, or '' for unbounded.
 */
function qhta_revenue_woo_date_range( $from, $to ) {
	if ( '' !== $from && '' !== $to ) {
		return $from . '...' . $to;
	}

	if ( '' !== $from ) {
		return '>=' . $from;
	}

	if ( '' !== $to ) {
		return '<=' . $to;
	}

	return '';
}

/**
 * Turn one WooCommerce order into a normalised report row.
 *
 * @param WC_Order $order Order.
 * @return array
 */
function qhta_revenue_normalise_store_order( $order ) {
	$created = $order->get_date_created();
	$email   = (string) $order->get_billing_email();
	$user_id = (int) $order->get_customer_id();

	$customer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

	if ( '' === $customer && $user_id > 0 ) {
		$user     = get_userdata( $user_id );
		$customer = $user ? $user->display_name : '';
	}

	list( $fee, $net, $fee_breakdown ) = qhta_revenue_store_fee_net( $order );

	list( $member_state, $member_level, $member_note ) = qhta_revenue_member_flag( $user_id, $email );

	$raw_status = (string) $order->get_status();

	return qhta_revenue_row(
		array(
			'source'       => 'store',
			'timestamp'    => $created ? (int) $created->getTimestamp() : 0,
			'ref'          => '#' . $order->get_order_number(),
			'edit_url'     => $order->get_edit_order_url(),
			'customer'     => $customer,
			'email'        => $email,
			'item'         => qhta_revenue_order_items_label( $order ),
			'amount'        => (float) $order->get_total(),
			'fee'           => $fee,
			'net'           => $net,
			'fee_breakdown' => $fee_breakdown,
			'status'       => qhta_revenue_normalise_status( 'woo', $raw_status ),
			'status_raw'   => $raw_status,
			'gateway'      => (string) $order->get_payment_method_title(),
			'member'       => $member_state,
			'member_level' => $member_level,
			'member_note'  => $member_note,
			'txn_ids'      => (string) $order->get_transaction_id(),
		)
	);
}

/**
 * A short label for what an order contained.
 *
 * First line item plus a count of the rest. The full list would make the column
 * unreadable on a multi-item order and the report is about money rather than
 * fulfilment; the order number links through to the detail.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function qhta_revenue_order_items_label( $order ) {
	$names = array();

	foreach ( $order->get_items() as $item ) {
		$names[] = $item->get_name();
	}

	$names = array_values( array_filter( array_map( 'trim', $names ) ) );

	if ( ! $names ) {
		return '';
	}

	if ( 1 === count( $names ) ) {
		return $names[0];
	}

	return sprintf(
		/* translators: 1: first product name, 2: how many further products the order held. */
		__( '%1$s (+%2$d)', 'qhta-revenue' ),
		$names[0],
		count( $names ) - 1
	);
}

/**
 * Meta keys the WooCommerce Stripe gateway has used for the fee and net.
 *
 * Current versions write `_stripe_fee` and `_stripe_net`. Orders taken under
 * WooCommerce Stripe Gateway 3.x carry the same figures under the older
 * human-readable keys, and this site has history — so both are read, newest
 * first. An order predating either simply has no recorded fee and is reported
 * as unknown.
 *
 * @return array{fee:string[],net:string[]}
 */
function qhta_revenue_woo_fee_meta_keys() {
	return (array) apply_filters(
		'qhta_revenue_woo_fee_meta_keys',
		array(
			'fee' => array( '_stripe_fee', 'Stripe Fee' ),
			'net' => array( '_stripe_net', 'Net Revenue From Stripe' ),
		)
	);
}

/**
 * Was this order paid through Stripe?
 *
 * Substring rather than equality because the gateway registers several payment
 * method ids off one integration — `stripe`, `stripe_sepa`, `stripe_alipay` and
 * so on — and all of them take a fee.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function qhta_revenue_is_stripe( $order ) {
	return false !== strpos( strtolower( (string) $order->get_payment_method() ), 'stripe' );
}

/**
 * Resolve a store order's Stripe fee and net.
 *
 * Reads only what the gateway recorded. A Stripe order with nothing recorded is
 * **unknown**, not zero: zero would quietly add the whole gross to the net
 * income and make the report overstate what was banked. Non-Stripe orders —
 * bank transfer, cheque — are a genuine zero, because that money arrives whole.
 *
 * @param WC_Order $order Order.
 * @return array{0:float|null,1:float|null} Fee, net.
 */
function qhta_revenue_store_fee_net( $order ) {
	$keys = qhta_revenue_woo_fee_meta_keys();

	$fee = qhta_revenue_order_meta_number( $order, isset( $keys['fee'] ) ? $keys['fee'] : array() );
	$net = qhta_revenue_order_meta_number( $order, isset( $keys['net'] ) ? $keys['net'] : array() );

	if ( null === $fee && ! qhta_revenue_is_stripe( $order ) ) {
		$fee = 0.0;
		$net = ( null === $net ) ? (float) $order->get_total() : $net;
	}

	if ( null !== $net && null === $fee ) {
		$fee = (float) $order->get_total() - (float) $net;
	}

	/**
	 * Filter a store order's resolved fee and net.
	 *
	 * The hook an optional Stripe-API backfill would use for historic orders
	 * with no recorded fee.
	 *
	 * @param array{0:float|null,1:float|null,2:array} $fee_net Fee, net and fee breakdown.
	 * @param WC_Order                                 $order   Order.
	 */
	return qhta_revenue_normalise_fee_net(
		apply_filters( 'qhta_revenue_store_fee_net', array( $fee, $net, array() ), $order )
	);
}

/**
 * First of several order meta keys holding a usable number.
 *
 * get_meta() is used rather than get_post_meta() so the read works under HPOS,
 * where there is no post to read meta from.
 *
 * @param WC_Order $order Order.
 * @param string[] $keys  Candidate keys in preference order.
 * @return float|null
 */
function qhta_revenue_order_meta_number( $order, $keys ) {
	foreach ( (array) $keys as $key ) {
		$value = $order->get_meta( $key );

		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
	}

	return null;
}
