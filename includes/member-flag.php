<?php
/**
 * "Member?" resolution — the new signal this report adds.
 *
 * A membership order is a member by definition, so this earns its keep on the
 * *store* rows: which of the people buying recordings are also financial
 * members?
 *
 * It reports **current** membership status, not status at the time of the
 * order. That is a deliberate v1 decision, not an oversight. Current status is
 * one cheap call per buyer and answers the question actually being asked at the
 * screen ("is this person a member right now?"). Status-at-time-of-order is
 * reconstructable from PMPro's history in pmpro_memberships_users (startdate /
 * enddate / modified), but it is materially more work, it is ambiguous for
 * anyone whose membership lapsed and was renewed, and it answers a question
 * nobody has asked yet. The column header says which one it is, because a
 * reader who assumes the other one would draw the wrong conclusion from a
 * lapsed member's old order.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this buyer a financial member, and at what level?
 *
 * Resolution order, and why each step exists:
 *
 *   1. The order carries a WP user ID — ask PMPro directly. This is the normal
 *      case and the only unambiguous one.
 *   2. No user ID (a guest order) — look the billing email up as a WP user.
 *      Membership requires an account, so an email with no user behind it is a
 *      definite No rather than an unknown.
 *   3. A user ID that no longer resolves — the buyer's account has been
 *      deleted. This returns **Unknown**, not No. The orders screens on this
 *      site already show `[deleted]` buyers, and their membership history went
 *      with the account; reporting them as "not a member" would be asserting
 *      something nobody knows.
 *
 * Results are cached per request. The same buyer routinely appears on several
 * orders in one report and the answer cannot change mid-page.
 *
 * @param int    $user_id       Order's customer ID, 0 for a guest order.
 * @param string $billing_email Order's billing email.
 * @return array{0:string,1:string,2:string} State ('yes'|'no'|'unknown'), level name, note.
 */
function qhta_revenue_member_flag( $user_id, $billing_email ) {
	static $cache = array();

	$user_id       = (int) $user_id;
	$billing_email = is_string( $billing_email ) ? strtolower( trim( $billing_email ) ) : '';
	$key           = $user_id . '|' . $billing_email;

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$cache[ $key ] = qhta_revenue_resolve_member_flag( $user_id, $billing_email );

	return $cache[ $key ];
}

/**
 * Uncached body of qhta_revenue_member_flag().
 *
 * @param int    $user_id       Customer ID.
 * @param string $billing_email Billing email, already lowercased and trimmed.
 * @return array{0:string,1:string,2:string}
 */
function qhta_revenue_resolve_member_flag( $user_id, $billing_email ) {
	if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
		// PMPro inactive: every answer would be "no", which is indistinguishable
		// from a genuine no. Say unknown instead, so an unnoticed deactivation
		// cannot be read as "none of these buyers are members".
		return array( 'unknown', '', __( 'PMPro inactive', 'qhta-revenue' ) );
	}

	if ( $user_id > 0 ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array( 'unknown', '', __( 'deleted user', 'qhta-revenue' ) );
		}

		return qhta_revenue_level_for_user( $user_id );
	}

	if ( '' === $billing_email ) {
		return array( 'unknown', '', __( 'no account or email on the order', 'qhta-revenue' ) );
	}

	$user = get_user_by( 'email', $billing_email );

	if ( ! $user ) {
		// A membership cannot exist without an account, so this is a real No.
		return array( 'no', '', __( 'guest — no account', 'qhta-revenue' ) );
	}

	$flag = qhta_revenue_level_for_user( $user->ID );

	if ( '' === $flag[2] ) {
		$flag[2] = __( 'matched on billing email', 'qhta-revenue' );
	}

	return $flag;
}

/**
 * Ask PMPro for a user's current level.
 *
 * pmpro_getMembershipLevelForUser() returns false, null or an object with no id
 * depending on version and state, so the truthiness test is on the id rather
 * than on the return value. "Active" is PMPro's own definition and excludes
 * expired and cancelled memberships, which is what "financial member" means
 * here.
 *
 * @param int $user_id User to test.
 * @return array{0:string,1:string,2:string}
 */
function qhta_revenue_level_for_user( $user_id ) {
	$level = pmpro_getMembershipLevelForUser( (int) $user_id );

	if ( empty( $level ) || empty( $level->id ) ) {
		return array( 'no', '', '' );
	}

	return array( 'yes', (string) $level->name, '' );
}

/**
 * Display text for a resolved member flag.
 *
 * @param string $state State ('yes'|'no'|'unknown').
 * @param string $level Level name.
 * @param string $note  Explanatory note.
 * @return string
 */
function qhta_revenue_member_display( $state, $level, $note ) {
	if ( 'yes' === $state ) {
		return $level ? sprintf( /* translators: %s: membership level name. */ __( 'Yes — %s', 'qhta-revenue' ), $level ) : __( 'Yes', 'qhta-revenue' );
	}

	if ( 'unknown' === $state ) {
		return $note ? sprintf( /* translators: %s: reason the answer is unknown. */ __( 'Unknown (%s)', 'qhta-revenue' ), $note ) : __( 'Unknown', 'qhta-revenue' );
	}

	return __( 'No', 'qhta-revenue' );
}
