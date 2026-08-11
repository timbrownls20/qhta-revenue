<?php
/**
 * CSV export of exactly what the screen is showing.
 *
 * Handled on admin_post rather than inside the page render. By the time the
 * report screen runs, WordPress has already sent the admin header and a good
 * deal of HTML, so a download started there could not set its own Content-Type
 * — it would arrive as a CSV-shaped web page. admin_post fires before any
 * output, which is the only place a file download can legitimately begin.
 *
 * This is the one request in the plugin that leaves the page, so it is treated
 * like a form submission — capability *and* nonce — even though it still only
 * reads.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The export URL for a set of filters.
 *
 * Carries the whole filter state, so the download is the current view rather
 * than a default one, and is nonced.
 *
 * @param array $filters Active filters.
 * @return string
 */
function qhta_revenue_export_url( $filters ) {
	$args = array(
		'action'      => 'qhta_revenue_export',
		'preset'      => $filters['preset'],
		'from'        => $filters['from'],
		'to'          => $filters['to'],
		'source'      => $filters['source'],
		'status'      => $filters['status'],
		's'           => $filters['search'],
		'member_only' => $filters['member_only'] ? '1' : '',
		'orderby'     => $filters['orderby'],
		'order'       => $filters['order'],
	);

	// add_query_arg() encodes the values itself — encoding them here as well
	// would send a doubly-escaped search term back to the handler.
	$args = array_filter(
		$args,
		static function ( $value ) {
			return '' !== $value;
		}
	);

	return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'qhta_revenue_export' );
}

/**
 * The export columns, in order.
 *
 * One unified schema across both sources — the point of the export is that a
 * membership row and a store row are the same shape and can be summed in a
 * spreadsheet without reconciling two layouts first.
 *
 * @return array<string,string> Column key => header.
 */
function qhta_revenue_export_columns() {
	return array(
		'source'       => __( 'source', 'qhta-revenue' ),
		'date'         => __( 'date', 'qhta-revenue' ),
		'ref'          => __( 'ref', 'qhta-revenue' ),
		'customer'     => __( 'customer', 'qhta-revenue' ),
		'email'        => __( 'email', 'qhta-revenue' ),
		'item'         => __( 'item', 'qhta-revenue' ),
		'amount'       => __( 'amount', 'qhta-revenue' ),
		'fee'          => __( 'fee', 'qhta-revenue' ),
		'net'          => __( 'net', 'qhta-revenue' ),
		'status'       => __( 'status', 'qhta-revenue' ),
		'gateway'      => __( 'gateway', 'qhta-revenue' ),
		'member'       => __( 'member', 'qhta-revenue' ),
		'member_level' => __( 'member_level', 'qhta-revenue' ),
		'txn_ids'      => __( 'txn_ids', 'qhta-revenue' ),
	);
}

/**
 * Stream the filtered set as CSV.
 *
 * @return void
 */
function qhta_revenue_handle_export() {
	if ( ! current_user_can( qhta_revenue_capability() ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to export the income report.', 'qhta-revenue' ),
			esc_html__( 'Not allowed', 'qhta-revenue' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( 'qhta_revenue_export' );

	$filters = qhta_revenue_current_filters();
	$rows    = qhta_revenue_get_rows( $filters );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . qhta_revenue_export_filename( $filters ) . '"' );

	$out = fopen( 'php://output', 'w' );

	if ( false === $out ) {
		wp_die( esc_html__( 'Could not open the output stream for the export.', 'qhta-revenue' ) );
	}

	// UTF-8 BOM. Without it Excel on Windows reads the file as the system
	// codepage and mangles any name with an accent in it.
	fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

	fputcsv( $out, array_values( qhta_revenue_export_columns() ) );

	foreach ( $rows as $row ) {
		fputcsv( $out, qhta_revenue_export_row( $row ) );
	}

	fclose( $out );
	exit;
}
add_action( 'admin_post_qhta_revenue_export', 'qhta_revenue_handle_export' );

/**
 * One row, flattened for CSV.
 *
 * Money is written as a bare number with a dot decimal and no thousands
 * separator or currency symbol, so the column arrives as a number a spreadsheet
 * can sum. An unknown fee is written as the empty string rather than 0 — a
 * blank cell sums to nothing and reads as absent, which is exactly right;
 * a zero would be added up as a fact.
 *
 * @param array $row Normalised row.
 * @return array
 */
function qhta_revenue_export_row( $row ) {
	$values = array(
		'source'       => $row['source_label'],
		'date'         => qhta_revenue_date_display( $row['timestamp'] ),
		'ref'          => $row['ref'],
		'customer'     => $row['customer'],
		'email'        => $row['email'],
		'item'         => $row['item'],
		'amount'       => number_format( (float) $row['amount'], 2, '.', '' ),
		'fee'          => ( null === $row['fee'] ) ? '' : number_format( (float) $row['fee'], 2, '.', '' ),
		'net'          => ( null === $row['net'] ) ? '' : number_format( (float) $row['net'], 2, '.', '' ),
		'status'       => $row['status_label'],
		'gateway'      => $row['gateway'],
		'member'       => qhta_revenue_member_display( $row['member'], '', $row['member_note'] ),
		'member_level' => $row['member_level'],
		'txn_ids'      => $row['txn_ids'],
	);

	return array_values( $values );
}

/**
 * Filename for the download.
 *
 * Names the range so a folder of exports stays self-describing.
 *
 * @param array $filters Active filters.
 * @return string
 */
function qhta_revenue_export_filename( $filters ) {
	if ( '' !== $filters['from'] && '' !== $filters['to'] ) {
		$range = $filters['from'] . '_to_' . $filters['to'];
	} elseif ( '' !== $filters['from'] ) {
		$range = 'from_' . $filters['from'];
	} elseif ( '' !== $filters['to'] ) {
		$range = 'to_' . $filters['to'];
	} else {
		$range = 'all-dates';
	}

	if ( 'both' !== $filters['source'] ) {
		$range .= '_' . $filters['source'];
	}

	return sanitize_file_name( 'qhta-revenue_' . $range . '.csv' );
}
