<?php
/**
 * The merged income table.
 *
 * WP_List_Table for the native admin look, sorting and pagination — but fed a
 * set of rows that is already complete, because the merge has to happen before
 * anything can be sorted or paged. The class therefore does no querying at all:
 * it slices a page out of what report.php produced.
 *
 * Deliberately no bulk actions and no row actions beyond the link out to the
 * order. There is nothing here to act on — this plugin does not write.
 *
 * This file is only ever loaded while rendering the report screen, because
 * WP_List_Table lives in wp-admin and does not exist on a front-end request.
 *
 * @package QHTA_Revenue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Combined PMPro + WooCommerce income table.
 */
class QHTA_Revenue_List_Table extends WP_List_Table {

	/**
	 * Every row matching the filters, before pagination.
	 *
	 * @var array[]
	 */
	protected $all_rows = array();

	/**
	 * Active filter state.
	 *
	 * @var array
	 */
	protected $filters = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'qhta-revenue-row',
				'plural'   => 'qhta-revenue-rows',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * The Member? header names the caveat rather than hiding it in the README:
	 * a reader who assumed it meant "was a member when they bought this" would
	 * misread every lapsed member's old order.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'source'   => __( 'Source', 'qhta-revenue' ),
			'date'     => __( 'Date', 'qhta-revenue' ),
			'ref'      => __( 'Reference', 'qhta-revenue' ),
			'customer' => __( 'Customer', 'qhta-revenue' ),
			'item'     => __( 'Item', 'qhta-revenue' ),
			'amount'   => __( 'Gross', 'qhta-revenue' ),
			'fee'      => __( 'Stripe fee', 'qhta-revenue' ),
			'net'      => __( 'Net', 'qhta-revenue' ),
			'status'   => __( 'Status', 'qhta-revenue' ),
			'gateway'  => __( 'Gateway', 'qhta-revenue' ),
			'member'   => __( 'Member? (now)', 'qhta-revenue' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'source'   => array( 'source', false ),
			'date'     => array( 'date', true ),
			'customer' => array( 'customer', false ),
			'item'     => array( 'item', false ),
			'amount'   => array( 'amount', false ),
			'fee'      => array( 'fee', false ),
			'net'      => array( 'net', false ),
			'status'   => array( 'status', false ),
		);
	}

	/**
	 * Feed the table its rows.
	 *
	 * @param array[] $rows    All filtered rows, already sorted.
	 * @param array   $filters Active filters.
	 * @return void
	 */
	public function set_rows( $rows, $filters ) {
		$this->all_rows = $rows;
		$this->filters  = $filters;
	}

	/**
	 * Paginate.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page = qhta_revenue_per_page();
		$total    = count( $this->all_rows );
		$paged    = max( 1, (int) $this->get_pagenum() );

		$this->items = array_slice( $this->all_rows, ( $paged - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Empty state.
	 *
	 * Says which dependency is missing when one is, because "no orders found"
	 * and "half this report cannot run" look identical otherwise.
	 *
	 * @return void
	 */
	public function no_items() {
		$missing = array();

		if ( ! qhta_revenue_pmpro_active() ) {
			$missing[] = __( 'Paid Memberships Pro', 'qhta-revenue' );
		}

		if ( ! qhta_revenue_woo_active() ) {
			$missing[] = __( 'WooCommerce', 'qhta-revenue' );
		}

		if ( $missing ) {
			printf(
				/* translators: %s: list of inactive plugins. */
				esc_html__( 'No orders in this range. Note that %s is not active, so that income is not being reported.', 'qhta-revenue' ),
				esc_html( implode( __( ' and ', 'qhta-revenue' ), $missing ) )
			);
			return;
		}

		esc_html_e( 'No orders match these filters.', 'qhta-revenue' );
	}

	/**
	 * Fallback cell renderer.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return isset( $item[ $column ] ) ? esc_html( (string) $item[ $column ] ) : '';
	}

	/**
	 * Source cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_source( $item ) {
		return sprintf(
			'<span class="qhta-revenue-source qhta-revenue-source--%1$s">%2$s</span>',
			esc_attr( $item['source'] ),
			esc_html( $item['source_label'] )
		);
	}

	/**
	 * Date cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_date( $item ) {
		return esc_html( qhta_revenue_date_display( $item['timestamp'] ) );
	}

	/**
	 * Reference cell — links through to the order on its own system's screen.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_ref( $item ) {
		if ( ! $item['edit_url'] ) {
			return esc_html( $item['ref'] );
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $item['edit_url'] ),
			esc_html( $item['ref'] )
		);
	}

	/**
	 * Customer cell — name over email.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_customer( $item ) {
		$name = $item['customer'] ? $item['customer'] : __( '(no name on the order)', 'qhta-revenue' );

		$out = '<strong>' . esc_html( $name ) . '</strong>';

		if ( $item['email'] ) {
			$out .= '<br><span class="qhta-revenue-muted">' . esc_html( $item['email'] ) . '</span>';
		}

		return $out;
	}

	/**
	 * Gross cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_amount( $item ) {
		$amount = esc_html( qhta_revenue_money( $item['amount'] ) );

		if ( empty( $item['refunded'] ) ) {
			return $amount;
		}

		// This row's gross has already had a partial refund taken off it, and
		// the status still reads Paid — so without saying so here, the figure
		// would silently disagree with the same order in WooCommerce.
		return sprintf(
			'<span class="qhta-revenue-refunded" title="%1$s">%2$s</span>',
			esc_attr(
				sprintf(
					/* translators: 1: original order total, 2: amount refunded. */
					__( '%1$s less %2$s refunded', 'qhta-revenue' ),
					qhta_revenue_money( $item['amount_before_refund'] ),
					qhta_revenue_money( $item['refunded'] )
				)
			),
			$amount
		);
	}

	/**
	 * Fee cell.
	 *
	 * An unknown fee is called Unknown and styled as such, never rendered as
	 * $0.00 — see the note on qhta_revenue_totals().
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_fee( $item ) {
		if ( null === $item['fee'] ) {
			return '<span class="qhta-revenue-unknown" title="' . esc_attr__( 'No fee recorded against this order and none retrievable from Stripe — it is excluded from the fee and net totals.', 'qhta-revenue' ) . '">' . esc_html__( 'Unknown', 'qhta-revenue' ) . '</span>';
		}

		$amount = esc_html( qhta_revenue_money( $item['fee'] ) );

		// When Stripe itemised the deduction, show the components on hover. On
		// this site that is usually Stripe's own charge plus PMPro's platform
		// cut, which is worth being able to see without doing the subtraction.
		$breakdown = function_exists( 'qhta_revenue_fee_breakdown_label' )
			? qhta_revenue_fee_breakdown_label( $item['fee_breakdown'] )
			: '';

		if ( '' === $breakdown ) {
			return $amount;
		}

		return sprintf(
			'<span class="qhta-revenue-fee--itemised" title="%1$s">%2$s</span>',
			esc_attr( $breakdown ),
			$amount
		);
	}

	/**
	 * Net cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_net( $item ) {
		if ( null === $item['net'] ) {
			return '<span class="qhta-revenue-unknown">' . esc_html__( 'Unknown', 'qhta-revenue' ) . '</span>';
		}

		return esc_html( qhta_revenue_money( $item['net'] ) );
	}

	/**
	 * Status cell — normalised label, raw status on hover.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		return sprintf(
			'<span class="qhta-revenue-status qhta-revenue-status--%1$s" title="%2$s">%3$s</span>',
			esc_attr( $item['status'] ),
			esc_attr(
				sprintf(
					/* translators: %s: the raw status as the source system records it. */
					__( 'Raw status: %s', 'qhta-revenue' ),
					$item['status_raw']
				)
			),
			esc_html( $item['status_label'] )
		);
	}

	/**
	 * Member? cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_member( $item ) {
		$text = qhta_revenue_member_display( $item['member'], $item['member_level'], $item['member_note'] );

		return sprintf(
			'<span class="qhta-revenue-member qhta-revenue-member--%1$s">%2$s</span>',
			esc_attr( $item['member'] ),
			esc_html( $text )
		);
	}
}
