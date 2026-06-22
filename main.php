<?php
/**
 * Plugin Name: WooCommerce Auto Order Status Scheduler
 * Plugin URI: https://github.com/amirrezashf/woocommerce-auto-order-status-scheduler
 * Description: Automatically schedule WooCommerce order status changes with configurable delays, status restrictions, admin controls, and scheduled task management.
 * Version: 1.0.0
 * Author: Amirreza Shayesteh Far
 * Author URI: https://amirrezaa.ir/
 * License: GPL v2 or later
 * Text Domain: woocommerce-auto-order-status-scheduler
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/*
|--------------------------------------------------------------------------
| WooCommerce Auto Order Status Scheduler
|--------------------------------------------------------------------------
| - متاباکس تغییر وضعیت خودکار سفارش
| - صفحه لیست سفارشات زمان‌بندی‌شده
| - صفحه تنظیمات محدودیت وضعیت‌ها
|--------------------------------------------------------------------------
*/

define( 'WAOSS_META_ENABLED',      '_waoss_enabled' );
define( 'WAOSS_META_QTY',          '_waoss_quantity' );
define( 'WAOSS_META_UNIT',         '_waoss_unit' );
define( 'WAOSS_META_TARGET',       '_waoss_target' );

define( 'WAOSS_META_RUN_AT_UTC',   '_waoss_run_at_utc' );
define( 'WAOSS_META_RUN_AT_LOCAL', '_waoss_run_at_local' );

define( 'WAOSS_META_SET_BY',       '_waoss_set_by' );
define( 'WAOSS_META_SET_BY_NAME',  '_waoss_set_by_name' );
define( 'WAOSS_META_SET_AT',       '_waoss_set_at' );

define( 'WAOSS_GROUP',             'waoss_order_auto_status' );
define( 'WAOSS_ACTION',            'waoss_run_order_status_change' );
define( 'WAOSS_ACTION_FALLBACK',   'waoss_run_order_status_change_fallback' );

define( 'WAOSS_SETTINGS_KEY',      'waoss_settings' );
define( 'WAOSS_CATCHUP_LOCK',      'waoss_catchup_lock' );

/**
 * ---------------------------------------------------
 * تنظیمات
 * ---------------------------------------------------
 */
function waoss_get_settings() {
	$settings = get_option(
		WAOSS_SETTINGS_KEY,
		array(
			'allowed_from_statuses' => array(),
			'blocked_to_statuses'   => array(),
		)
	);

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$settings = wp_parse_args(
		$settings,
		array(
			'allowed_from_statuses' => array(),
			'blocked_to_statuses'   => array(),
		)
	);

	$settings['allowed_from_statuses'] = is_array( $settings['allowed_from_statuses'] ) ? array_values( array_unique( array_filter( $settings['allowed_from_statuses'] ) ) ) : array();
	$settings['blocked_to_statuses']   = is_array( $settings['blocked_to_statuses'] ) ? array_values( array_unique( array_filter( $settings['blocked_to_statuses'] ) ) ) : array();

	return $settings;
}

function waoss_get_allowed_from_statuses() {
	$settings = waoss_get_settings();
	return $settings['allowed_from_statuses'];
}

function waoss_get_blocked_to_statuses() {
	$settings = waoss_get_settings();
	return $settings['blocked_to_statuses'];
}

/**
 * ---------------------------------------------------
 * توابع کمکی
 * ---------------------------------------------------
 */
function waoss_get_order_statuses() {
	return wc_get_order_statuses();
}

function waoss_get_status_label( $status_key ) {
	$statuses = waoss_get_order_statuses();
	return isset( $statuses[ $status_key ] ) ? $statuses[ $status_key ] : $status_key;
}

function waoss_local_now() {
	return current_time( 'timestamp' );
}

function waoss_utc_now() {
	return time();
}

function waoss_local_offset() {
	return current_time( 'timestamp' ) - time();
}

function waoss_format_datetime( $local_timestamp ) {
	$local_timestamp = absint( $local_timestamp );

	if ( ! $local_timestamp ) {
		return '—';
	}

	return date_i18n( 'j F Y و ساعت H:i', $local_timestamp );
}

function waoss_get_user_display_name( $user_id ) {
	$user_id = absint( $user_id );

	if ( ! $user_id ) {
		return '—';
	}

	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return 'کاربر حذف‌شده';
	}

	return $user->display_name ? $user->display_name : $user->user_login;
}

function waoss_get_interval_label( $qty, $unit ) {
	$qty  = absint( $qty );
	$unit = sanitize_key( $unit );

	if ( $qty < 1 ) {
		return '—';
	}

	switch ( $unit ) {
		case 'minute':
			return $qty . ' دقیقه';

		case 'day':
			return $qty . ' روز';

		case 'week':
			return $qty . ' هفته';

		case 'month':
			return $qty . ' ماه';
	}

	return '—';
}

function waoss_build_schedule_note_text( $qty, $unit, $target_status ) {
	$interval     = waoss_get_interval_label( $qty, $unit );
	$target_label = waoss_get_status_label( $target_status );

	return sprintf(
		'%1$s زمان‌بندی جهت تغییر به وضعیت %2$s اعمال شد',
		$interval,
		$target_label
	);
}

function waoss_get_run_timestamps( $quantity, $unit ) {
	$quantity  = max( 1, absint( $quantity ) );
	$local_now = waoss_local_now();

	switch ( $unit ) {
		case 'minute':
			$local_run = $local_now + ( $quantity * MINUTE_IN_SECONDS );
			break;

		case 'day':
			$local_run = $local_now + ( $quantity * DAY_IN_SECONDS );
			break;

		case 'week':
			$local_run = $local_now + ( $quantity * WEEK_IN_SECONDS );
			break;

		case 'month':
			$local_run = strtotime( '+' . $quantity . ' month', $local_now );
			if ( ! $local_run ) {
				$local_run = $local_now + DAY_IN_SECONDS;
			}
			break;

		default:
			$local_run = $local_now + DAY_IN_SECONDS;
			break;
	}

	$utc_run = $local_run - waoss_local_offset();

	return array(
		'local' => absint( $local_run ),
		'utc'   => absint( $utc_run ),
	);
}

function waoss_get_order( $post_or_order_object = null ) {
	if ( $post_or_order_object instanceof WC_Order ) {
		return $post_or_order_object;
	}

	if ( is_numeric( $post_or_order_object ) ) {
		$order = wc_get_order( absint( $post_or_order_object ) );
		if ( $order ) {
			return $order;
		}
	}

	global $post, $theorder;

	if ( $theorder instanceof WC_Order ) {
		return $theorder;
	}

	if ( $post instanceof WP_Post && 'shop_order' === $post->post_type ) {
		$order = wc_get_order( $post->ID );
		if ( $order ) {
			return $order;
		}
	}

	if ( isset( $_GET['id'] ) ) {
		$order = wc_get_order( absint( $_GET['id'] ) );
		if ( $order ) {
			return $order;
		}
	}

	if ( isset( $_GET['post'] ) ) {
		$order = wc_get_order( absint( $_GET['post'] ) );
		if ( $order ) {
			return $order;
		}
	}

	return false;
}

function waoss_has_valid_schedule( WC_Order $order ) {
	$enabled = $order->get_meta( WAOSS_META_ENABLED, true );
	$run_at  = $order->get_meta( WAOSS_META_RUN_AT_UTC, true );
	$target  = $order->get_meta( WAOSS_META_TARGET, true );

	return ( 'yes' === $enabled && '' !== $run_at && '' !== $target );
}

function waoss_delete_schedule_meta( WC_Order $order ) {
	$order->delete_meta_data( WAOSS_META_ENABLED );
	$order->delete_meta_data( WAOSS_META_QTY );
	$order->delete_meta_data( WAOSS_META_UNIT );
	$order->delete_meta_data( WAOSS_META_TARGET );
	$order->delete_meta_data( WAOSS_META_RUN_AT_UTC );
	$order->delete_meta_data( WAOSS_META_RUN_AT_LOCAL );
	$order->delete_meta_data( WAOSS_META_SET_BY );
	$order->delete_meta_data( WAOSS_META_SET_BY_NAME );
	$order->delete_meta_data( WAOSS_META_SET_AT );
}

function waoss_add_note( WC_Order $order, $message ) {
	$order->add_order_note( $message, 0, true );
}

function waoss_clear_scheduled_actions( $order_id ) {
	$order_id = absint( $order_id );

	if ( ! $order_id ) {
		return;
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions(
			WAOSS_ACTION,
			array(
				'order_id' => $order_id,
			),
			WAOSS_GROUP
		);
	}

	wp_clear_scheduled_hook( WAOSS_ACTION_FALLBACK, array( $order_id ) );
}

function waoss_schedule_action( $order_id, $run_at_utc ) {
	$order_id   = absint( $order_id );
	$run_at_utc = absint( $run_at_utc );

	if ( ! $order_id || ! $run_at_utc ) {
		return;
	}

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action(
			$run_at_utc,
			WAOSS_ACTION,
			array(
				'order_id' => $order_id,
			),
			WAOSS_GROUP
		);
	}

	wp_schedule_single_event(
		$run_at_utc,
		WAOSS_ACTION_FALLBACK,
		array( $order_id )
	);
}

function waoss_is_current_status_allowed( WC_Order $order ) {
	$allowed = waoss_get_allowed_from_statuses();

	if ( empty( $allowed ) ) {
		return true;
	}

	$current_status = 'wc-' . $order->get_status();

	return in_array( $current_status, $allowed, true );
}

function waoss_is_target_status_allowed( $target_status ) {
	$blocked = waoss_get_blocked_to_statuses();

	if ( empty( $blocked ) ) {
		return true;
	}

	return ! in_array( $target_status, $blocked, true );
}

function waoss_cancel_schedule( WC_Order $order, $log_user_id = 0 ) {
	$order_id         = $order->get_id();
	$old_target       = $order->get_meta( WAOSS_META_TARGET, true );
	$old_qty          = $order->get_meta( WAOSS_META_QTY, true );
	$old_unit         = $order->get_meta( WAOSS_META_UNIT, true );
	$old_run_at_local = $order->get_meta( WAOSS_META_RUN_AT_LOCAL, true );
	$old_target_label = waoss_get_status_label( $old_target );

	waoss_clear_scheduled_actions( $order_id );
	waoss_delete_schedule_meta( $order );
	$order->save();

	if ( $log_user_id ) {
		$user_name = waoss_get_user_display_name( $log_user_id );

		$note = sprintf(
			'زمان‌بندی تغییر وضعیت خودکار سفارش توسط %1$s در تاریخ %2$s لغو شد. وضعیت مقصد قبلی: %3$s | بازه قبلی: %4$s | زمان اجرای قبلی: %5$s',
			$user_name,
			waoss_format_datetime( waoss_local_now() ),
			$old_target_label ? $old_target_label : '—',
			waoss_get_interval_label( $old_qty, $old_unit ),
			waoss_format_datetime( $old_run_at_local )
		);

		waoss_add_note( $order, $note );
	}
}

/**
 * ---------------------------------------------------
 * کوئری دقیق برای لیست سفارشات زمان‌بندی‌شده
 * سازگار با HPOS و ساختار کلاسیک
 * ---------------------------------------------------
 */
function waoss_is_hpos_enabled() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
	return false;
}

function waoss_get_scheduled_order_ids_paginated( $paged = 1, $per_page = 30 ) {
	global $wpdb;

	$paged    = max( 1, absint( $paged ) );
	$per_page = max( 1, absint( $per_page ) );
	$offset   = ( $paged - 1 ) * $per_page;

	$enabled_key = WAOSS_META_ENABLED;
	$run_at_key  = WAOSS_META_RUN_AT_UTC;
	$target_key  = WAOSS_META_TARGET;

	if ( waoss_is_hpos_enabled() ) {
		$orders_table = $wpdb->prefix . 'wc_orders';
		$meta_table   = $wpdb->prefix . 'wc_orders_meta';

		$total_sql = $wpdb->prepare(
			"
			SELECT COUNT(DISTINCT o.id)
			FROM {$orders_table} o
			INNER JOIN {$meta_table} m1
				ON o.id = m1.order_id
				AND m1.meta_key = %s
				AND m1.meta_value = 'yes'
			INNER JOIN {$meta_table} m2
				ON o.id = m2.order_id
				AND m2.meta_key = %s
				AND m2.meta_value <> ''
			INNER JOIN {$meta_table} m3
				ON o.id = m3.order_id
				AND m3.meta_key = %s
				AND m3.meta_value <> ''
			WHERE o.type = 'shop_order'
			",
			$enabled_key,
			$run_at_key,
			$target_key
		);

		$ids_sql = $wpdb->prepare(
			"
			SELECT DISTINCT o.id
			FROM {$orders_table} o
			INNER JOIN {$meta_table} m1
				ON o.id = m1.order_id
				AND m1.meta_key = %s
				AND m1.meta_value = 'yes'
			INNER JOIN {$meta_table} m2
				ON o.id = m2.order_id
				AND m2.meta_key = %s
				AND m2.meta_value <> ''
			INNER JOIN {$meta_table} m3
				ON o.id = m3.order_id
				AND m3.meta_key = %s
				AND m3.meta_value <> ''
			WHERE o.type = 'shop_order'
			ORDER BY o.id DESC
			LIMIT %d OFFSET %d
			",
			$enabled_key,
			$run_at_key,
			$target_key,
			$per_page,
			$offset
		);
	} else {
		$posts_table = $wpdb->posts;
		$meta_table  = $wpdb->postmeta;

		$total_sql = $wpdb->prepare(
			"
			SELECT COUNT(DISTINCT p.ID)
			FROM {$posts_table} p
			INNER JOIN {$meta_table} m1
				ON p.ID = m1.post_id
				AND m1.meta_key = %s
				AND m1.meta_value = 'yes'
			INNER JOIN {$meta_table} m2
				ON p.ID = m2.post_id
				AND m2.meta_key = %s
				AND m2.meta_value <> ''
			INNER JOIN {$meta_table} m3
				ON p.ID = m3.post_id
				AND m3.meta_key = %s
				AND m3.meta_value <> ''
			WHERE p.post_type = 'shop_order'
			AND p.post_status NOT IN ('trash','auto-draft')
			",
			$enabled_key,
			$run_at_key,
			$target_key
		);

		$ids_sql = $wpdb->prepare(
			"
			SELECT DISTINCT p.ID
			FROM {$posts_table} p
			INNER JOIN {$meta_table} m1
				ON p.ID = m1.post_id
				AND m1.meta_key = %s
				AND m1.meta_value = 'yes'
			INNER JOIN {$meta_table} m2
				ON p.ID = m2.post_id
				AND m2.meta_key = %s
				AND m2.meta_value <> ''
			INNER JOIN {$meta_table} m3
				ON p.ID = m3.post_id
				AND m3.meta_key = %s
				AND m3.meta_value <> ''
			WHERE p.post_type = 'shop_order'
			AND p.post_status NOT IN ('trash','auto-draft')
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d
			",
			$enabled_key,
			$run_at_key,
			$target_key,
			$per_page,
			$offset
		);
	}

	$total = (int) $wpdb->get_var( $total_sql );
	$ids   = $wpdb->get_col( $ids_sql );

	if ( ! is_array( $ids ) ) {
		$ids = array();
	}

	return array(
		'ids'           => array_map( 'absint', $ids ),
		'total'         => $total,
		'pages'         => max( 1, (int) ceil( $total / $per_page ) ),
		'current_page'  => $paged,
		'per_page'      => $per_page,
	);
}

/**
 * ---------------------------------------------------
 * پردازشگر جبرانی
 * ---------------------------------------------------
 */
add_action( 'init', 'waoss_maybe_process_due_orders', 20 );

function waoss_maybe_process_due_orders() {
	if ( wp_doing_ajax() ) {
		return;
	}

	if ( get_transient( WAOSS_CATCHUP_LOCK ) ) {
		return;
	}

	set_transient( WAOSS_CATCHUP_LOCK, 1, 30 );

	$args = array(
		'limit'      => 10,
		'type'       => 'shop_order',
		'orderby'    => 'meta_value_num',
		'order'      => 'ASC',
		'meta_key'   => WAOSS_META_RUN_AT_UTC,
		'meta_query' => array(
			'relation' => 'AND',
			array(
				'key'   => WAOSS_META_ENABLED,
				'value' => 'yes',
			),
			array(
				'key'     => WAOSS_META_TARGET,
				'compare' => 'EXISTS',
			),
			array(
				'key'     => WAOSS_META_RUN_AT_UTC,
				'value'   => waoss_utc_now(),
				'compare' => '<=',
				'type'    => 'NUMERIC',
			),
		),
	);

	$orders = wc_get_orders( $args );

	if ( ! empty( $orders ) && is_array( $orders ) ) {
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				waoss_process_change( $order->get_id() );
			}
		}
	}
}

/**
 * ---------------------------------------------------
 * فلگ داخلی برای جلوگیری از لغو اشتباهی
 * ---------------------------------------------------
 */
function waoss_set_internal_change_flag( $state ) {
	$GLOBALS['waoss_internal_change'] = (bool) $state;
}

function waoss_get_internal_change_flag() {
	return ! empty( $GLOBALS['waoss_internal_change'] );
}

/**
 * ---------------------------------------------------
 * اگر وضعیت سفارش تغییر کرد، زمان‌بندی قبلی لغو شود
 * ---------------------------------------------------
 */
add_action( 'woocommerce_order_status_changed', 'waoss_cancel_on_status_change', 20, 4 );

function waoss_cancel_on_status_change( $order_id, $old_status, $new_status, $order ) {
	if ( waoss_get_internal_change_flag() ) {
		return;
	}

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order ) {
		return;
	}

	if ( ! waoss_has_valid_schedule( $order ) ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		$user_id = 0;
	}

	waoss_cancel_schedule( $order, $user_id );

	waoss_add_note(
		$order,
		'به دلیل تغییر وضعیت سفارش، زمان‌بندی قبلی به صورت خودکار لغو شد.'
	);
}

/**
 * ---------------------------------------------------
 * استایل و اسکریپت ادمین
 * ---------------------------------------------------
 */
add_action( 'admin_enqueue_scripts', 'waoss_admin_assets' );

function waoss_admin_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen ) {
		return;
	}

	$is_order_screen    = in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
	$is_list_screen     = ( 'woocommerce_page_fa-scheduled-order-statuses' === $screen->id );
	$is_settings_screen = ( 'woocommerce_page_fa-auto-status-settings' === $screen->id );

	if ( ! $is_order_screen && ! $is_list_screen && ! $is_settings_screen ) {
		return;
	}

	$css = '
	.fa-auto-status-grid{
		display:grid;
		grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
		gap:16px;
		margin-top:16px;
	}
	.fa-auto-status-card{
		background:#fff;
		border:1px solid #dcdcde;
		border-radius:10px;
		padding:14px;
	}
	.fa-auto-status-card h2{
		margin:0 0 12px;
		font-size:14px;
	}
	.fa-auto-status-checklist{
		max-height:360px;
		overflow:auto;
		border:1px solid #e0e0e0;
		border-radius:8px;
		padding:10px;
		background:#fafafa;
	}
	.fa-auto-status-checklist label{
		display:flex;
		align-items:flex-start;
		gap:8px;
		margin:0 0 10px;
		line-height:1.8;
	}
	.fa-auto-status-nav{
		margin-top:18px;
		display:flex;
		gap:8px;
		align-items:center;
	}
	.fa-auto-status-nav .button{
		min-width:100px;
		text-align:center;
		border-radius:8px;
	}
	.fa-auto-status-note{
		margin-top:14px;
		padding:12px 14px;
		background:#fff;
		border:1px solid #dcdcde;
		border-radius:10px;
		color:#50575e;
		line-height:1.9;
	}
	';

	wp_register_style( 'fa-auto-status-inline-style', false );
	wp_enqueue_style( 'fa-auto-status-inline-style' );
	wp_add_inline_style( 'fa-auto-status-inline-style', $css );

	if ( $is_order_screen ) {
		$js = "
		document.addEventListener('DOMContentLoaded', function(){
			var qty = document.getElementById('waoss_quantity');

			if(qty){
				var sanitizeQty = function(){
					var val = qty.value || '';
					val = val.replace(/[^0-9]/g, '');
					qty.value = val;
				};

				qty.addEventListener('input', sanitizeQty);
				qty.addEventListener('keyup', sanitizeQty);
				qty.addEventListener('change', sanitizeQty);
				qty.addEventListener('paste', function(){
					setTimeout(sanitizeQty, 0);
				});
				qty.addEventListener('wheel', function(e){
					e.preventDefault();
				}, {passive:false});

				sanitizeQty();
			}
		});
		";

		wp_register_script( 'fa-auto-status-inline-script', '', array(), false, true );
		wp_enqueue_script( 'fa-auto-status-inline-script' );
		wp_add_inline_script( 'fa-auto-status-inline-script', $js );
	}
}

/**
 * ---------------------------------------------------
 * متاباکس
 * ---------------------------------------------------
 */
add_action( 'add_meta_boxes', 'waoss_register_metabox', 30 );
add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'waoss_register_metabox_hpos', 30 );

function waoss_register_metabox() {
	add_meta_box(
		'fa_auto_order_status_box',
		'تغییر وضعیت خودکار سفارش',
		'waoss_render_metabox',
		'shop_order',
		'side',
		'default'
	);
}

function waoss_register_metabox_hpos() {
	add_meta_box(
		'fa_auto_order_status_box',
		'تغییر وضعیت خودکار سفارش',
		'waoss_render_metabox',
		'woocommerce_page_wc-orders',
		'side',
		'default'
	);
}

function waoss_render_metabox( $post_or_order_object ) {
	$order = waoss_get_order( $post_or_order_object );

	if ( ! $order ) {
		echo '<p>سفارش پیدا نشد.</p>';
		return;
	}

	$statuses               = waoss_get_order_statuses();
	$current_status_key     = 'wc-' . $order->get_status();
	$current_status_label   = isset( $statuses[ $current_status_key ] ) ? $statuses[ $current_status_key ] : wc_get_order_status_name( $order->get_status() );
	$from_status_is_allowed = waoss_is_current_status_allowed( $order );

	wp_nonce_field( 'waoss_save_meta', 'waoss_nonce' );

	echo '<div class="fa-auto-status-box" style="display:flex;flex-direction:column;gap:10px;">';

	if ( ! $from_status_is_allowed && ! waoss_has_valid_schedule( $order ) ) {
		echo '<div style="padding:10px;border:1px solid #b32d2e;border-radius:8px;background:#fff2f2;color:#8a2424;font-size:12px;line-height:1.9;">';
		echo '<strong>هشدار:</strong> با تنظیمات فعلی، ثبت زمان‌بندی از وضعیت فعلی این سفارش مجاز نیست.';
		echo '<br>وضعیت فعلی سفارش: ' . esc_html( $current_status_label );
		echo '</div>';
	}

	if ( waoss_has_valid_schedule( $order ) ) {
		$target       = $order->get_meta( WAOSS_META_TARGET, true );
		$run_at_local = $order->get_meta( WAOSS_META_RUN_AT_LOCAL, true );
		$set_by       = $order->get_meta( WAOSS_META_SET_BY, true );
		$set_by_name  = $order->get_meta( WAOSS_META_SET_BY_NAME, true );
		$set_at       = $order->get_meta( WAOSS_META_SET_AT, true );
		$qty          = $order->get_meta( WAOSS_META_QTY, true );
		$unit         = $order->get_meta( WAOSS_META_UNIT, true );

		echo '<div style="padding:10px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;font-size:12px;line-height:1.9;">';
		echo '<div><strong>وضعیت مقصد:</strong> ' . esc_html( waoss_get_status_label( $target ) ) . '</div>';
		echo '<div><strong>نوع زمان‌بندی:</strong> ' . esc_html( waoss_get_interval_label( $qty, $unit ) ) . '</div>';
		echo '<div><strong>زمان اجرای نهایی:</strong> ' . esc_html( waoss_format_datetime( $run_at_local ) ) . '</div>';

		if ( $set_by || $set_by_name ) {
			$display_name = $set_by_name ? $set_by_name : waoss_get_user_display_name( $set_by );
			echo '<div><strong>ثبت توسط:</strong> ' . esc_html( $display_name ) . '</div>';
		}

		if ( $set_at ) {
			echo '<div><strong>زمان ثبت:</strong> ' . esc_html( waoss_format_datetime( $set_at ) ) . '</div>';
		}

		echo '</div>';

		echo '<p style="margin:0;">';
		echo '<button type="submit" name="waoss_cancel_schedule" value="1" class="button" style="width:100%;border-color:#b32d2e;color:#b32d2e;">لغو زمان‌بندی فعلی</button>';
		echo '</p>';

		echo '</div>';
		return;
	}

	$blocked_to_statuses = waoss_get_blocked_to_statuses();

	echo '<p style="margin:0;">';
	echo '<label style="display:flex;align-items:center;gap:8px;">';
	echo '<input type="checkbox" id="waoss_enabled" name="waoss_enabled" value="1">';
	echo '<span>فعال‌سازی زمان‌بندی</span>';
	echo '</label>';
	echo '</p>';

	echo '<p style="margin:0;">';
	echo '<label for="waoss_quantity" style="display:block;margin-bottom:6px;">بعد از</label>';
	echo '<input type="text" inputmode="numeric" autocomplete="off" id="waoss_quantity" name="waoss_quantity" value="1" style="width:100%;">';
	echo '</p>';

	echo '<p style="margin:0;">';
	echo '<label for="waoss_unit" style="display:block;margin-bottom:6px;">بازه زمانی</label>';
	echo '<select id="waoss_unit" name="waoss_unit" style="width:100%;">';
	echo '<option value="minute">دقیقه</option>';
	echo '<option value="day" selected>روز</option>';
	echo '<option value="week">هفته</option>';
	echo '<option value="month">ماه</option>';
	echo '</select>';
	echo '</p>';

	echo '<p style="margin:0;">';
	echo '<label for="waoss_target" style="display:block;margin-bottom:6px;">تغییر به وضعیت</label>';
	echo '<select id="waoss_target" name="waoss_target" style="width:100%;">';
	echo '<option value="">— انتخاب وضعیت —</option>';

	foreach ( $statuses as $status_key => $status_label ) {
		if ( in_array( $status_key, $blocked_to_statuses, true ) ) {
			continue;
		}

		if ( $status_key === $current_status_key ) {
			continue;
		}

		echo '<option value="' . esc_attr( $status_key ) . '">' . esc_html( $status_label ) . '</option>';
	}

	echo '</select>';
	echo '</p>';

	echo '</div>';
}

/**
 * ---------------------------------------------------
 * ذخیره متاباکس
 * ---------------------------------------------------
 */
add_action( 'woocommerce_process_shop_order_meta', 'waoss_save_metabox_classic', 30 );
add_action( 'woocommerce_admin_process_order_object', 'waoss_save_metabox_hpos', 30 );

function waoss_save_metabox_classic( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( $order ) {
		waoss_save_metabox_common( $order );
	}
}

function waoss_save_metabox_hpos( $order ) {
	if ( $order instanceof WC_Order ) {
		waoss_save_metabox_common( $order );
	}
}

function waoss_save_metabox_common( WC_Order $order ) {
	if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if (
		! isset( $_POST['waoss_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['waoss_nonce'] ) ), 'waoss_save_meta' )
	) {
		return;
	}

	$order_id = $order->get_id();
	$user_id  = get_current_user_id();

	if ( isset( $_POST['waoss_cancel_schedule'] ) ) {
		if ( waoss_has_valid_schedule( $order ) ) {
			waoss_cancel_schedule( $order, $user_id );
		}
		return;
	}

	if ( waoss_has_valid_schedule( $order ) ) {
		return;
	}

	$enabled = isset( $_POST['waoss_enabled'] ) ? 'yes' : 'no';

	if ( 'yes' !== $enabled ) {
		return;
	}

	$quantity_raw = isset( $_POST['waoss_quantity'] ) ? wp_unslash( $_POST['waoss_quantity'] ) : '';
	$quantity_raw = is_string( $quantity_raw ) ? trim( $quantity_raw ) : '';
	$quantity_raw = preg_replace( '/[^0-9]/', '', $quantity_raw );
	$quantity     = ( '' !== $quantity_raw ) ? absint( $quantity_raw ) : 0;

	$unit          = isset( $_POST['waoss_unit'] ) ? sanitize_key( wp_unslash( $_POST['waoss_unit'] ) ) : '';
	$target_status = isset( $_POST['waoss_target'] ) ? sanitize_text_field( wp_unslash( $_POST['waoss_target'] ) ) : '';

	$allowed_units    = array( 'minute', 'day', 'week', 'month' );
	$allowed_statuses = array_keys( waoss_get_order_statuses() );

	if ( ! in_array( $unit, $allowed_units, true ) ) {
		$unit = 'day';
	}

	if ( ! in_array( $target_status, $allowed_statuses, true ) ) {
		$target_status = '';
	}

	if ( $quantity < 1 || ! $target_status ) {
		return;
	}

	$current_status = 'wc-' . $order->get_status();

	if ( $current_status === $target_status ) {
		waoss_add_note(
			$order,
			'زمان‌بندی تغییر وضعیت خودکار ثبت نشد، زیرا وضعیت مقصد با وضعیت فعلی سفارش یکسان است.'
		);
		return;
	}

	if ( ! waoss_is_current_status_allowed( $order ) ) {
		waoss_add_note(
			$order,
			'تلاش برای ثبت زمان‌بندی تغییر وضعیت خودکار انجام شد، اما وضعیت فعلی سفارش در لیست وضعیت‌های مجاز برای شروع قرار ندارد.'
		);
		return;
	}

	if ( ! waoss_is_target_status_allowed( $target_status ) ) {
		waoss_add_note(
			$order,
			'تلاش برای ثبت زمان‌بندی تغییر وضعیت خودکار انجام شد، اما وضعیت مقصد انتخاب‌شده در لیست وضعیت‌های ممنوع قرار دارد.'
		);
		return;
	}

	$timestamps    = waoss_get_run_timestamps( $quantity, $unit );
	$run_at_utc    = $timestamps['utc'];
	$run_at_local  = $timestamps['local'];
	$user_name     = waoss_get_user_display_name( $user_id );
	$set_at        = waoss_local_now();

	$order->update_meta_data( WAOSS_META_ENABLED, 'yes' );
	$order->update_meta_data( WAOSS_META_QTY, $quantity );
	$order->update_meta_data( WAOSS_META_UNIT, $unit );
	$order->update_meta_data( WAOSS_META_TARGET, $target_status );
	$order->update_meta_data( WAOSS_META_RUN_AT_UTC, $run_at_utc );
	$order->update_meta_data( WAOSS_META_RUN_AT_LOCAL, $run_at_local );
	$order->update_meta_data( WAOSS_META_SET_BY, $user_id );
	$order->update_meta_data( WAOSS_META_SET_BY_NAME, $user_name );
	$order->update_meta_data( WAOSS_META_SET_AT, $set_at );
	$order->save();

	waoss_schedule_action( $order_id, $run_at_utc );

	$timing_text = waoss_build_schedule_note_text( $quantity, $unit, $target_status );

	$note = sprintf(
		'%1$s توسط %2$s در تاریخ %3$s ثبت شد. زمان اجرای نهایی: %4$s',
		$timing_text,
		$user_name,
		waoss_format_datetime( $set_at ),
		waoss_format_datetime( $run_at_local )
	);

	waoss_add_note( $order, $note );
}

/**
 * ---------------------------------------------------
 * اجرای زمان‌بندی
 * ---------------------------------------------------
 */
add_action( WAOSS_ACTION, 'waoss_run_scheduled_change', 10, 1 );
add_action( WAOSS_ACTION_FALLBACK, 'waoss_run_scheduled_change_fallback', 10, 1 );

function waoss_run_scheduled_change( $args ) {
	$order_id = 0;

	if ( is_array( $args ) && isset( $args['order_id'] ) ) {
		$order_id = absint( $args['order_id'] );
	} elseif ( is_numeric( $args ) ) {
		$order_id = absint( $args );
	}

	if ( $order_id ) {
		waoss_process_change( $order_id );
	}
}

function waoss_run_scheduled_change_fallback( $order_id ) {
	$order_id = absint( $order_id );

	if ( $order_id ) {
		waoss_process_change( $order_id );
	}
}

function waoss_process_change( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || ! waoss_has_valid_schedule( $order ) ) {
		return;
	}

	$target      = $order->get_meta( WAOSS_META_TARGET, true );
	$run_at_utc  = absint( $order->get_meta( WAOSS_META_RUN_AT_UTC, true ) );
	$set_by_name = $order->get_meta( WAOSS_META_SET_BY_NAME, true );

	if ( ! $target || ! $run_at_utc ) {
		return;
	}

	if ( waoss_utc_now() < $run_at_utc ) {
		return;
	}

	if ( ! waoss_is_target_status_allowed( $target ) ) {
		waoss_add_note(
			$order,
			'اجرای زمان‌بندی تغییر وضعیت خودکار متوقف شد، زیرا وضعیت مقصد در تنظیمات به‌عنوان وضعیت ممنوع ثبت شده است.'
		);

		waoss_delete_schedule_meta( $order );
		$order->save();
		return;
	}

	$current_status_key = 'wc-' . $order->get_status();

	if ( $current_status_key === $target ) {
		waoss_add_note(
			$order,
			'زمان‌بندی تغییر وضعیت خودکار به پایان رسید، اما سفارش از قبل در همان وضعیت مقصد قرار داشت.'
		);

		waoss_delete_schedule_meta( $order );
		$order->save();
		return;
	}

	$target_label = waoss_get_status_label( $target );

	waoss_set_internal_change_flag( true );

	// روش استاندارد ووکامرس برای تغییر وضعیت
	$order->update_status(
		str_replace( 'wc-', '', $target ),
		sprintf(
			'تغییر وضعیت خودکار سفارش اجرا شد. وضعیت جدید: %1$s | زمان‌بندی اولیه توسط: %2$s',
			$target_label,
			$set_by_name ? $set_by_name : 'نامشخص'
		),
		true
	);

	waoss_set_internal_change_flag( false );

	waoss_delete_schedule_meta( $order );
	$order->save();
}

/**
 * ---------------------------------------------------
 * منوی مدیریت
 * ---------------------------------------------------
 */
add_action( 'admin_menu', 'waoss_admin_menu', 99 );

function waoss_admin_menu() {
	add_submenu_page(
		'woocommerce',
		'سفارشات زمان‌بندی‌شده',
		'سفارشات زمان‌بندی‌شده',
		'manage_woocommerce',
		'fa-scheduled-order-statuses',
		'waoss_render_admin_page'
	);

	add_submenu_page(
		'woocommerce',
		'تنظیمات تغییر وضعیت خودکار',
		'تنظیمات تغییر وضعیت خودکار',
		'manage_options',
		'fa-auto-status-settings',
		'waoss_render_settings_page'
	);
}

/**
 * ---------------------------------------------------
 * صفحه تنظیمات
 * ---------------------------------------------------
 */
function waoss_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'شما دسترسی لازم را ندارید.' );
	}

	$statuses = waoss_get_order_statuses();

	if ( isset( $_POST['waoss_settings_submit'] ) ) {
		check_admin_referer( 'waoss_settings_save', 'waoss_settings_nonce' );

		$allowed_from = isset( $_POST['fa_allowed_from_statuses'] ) ? (array) wp_unslash( $_POST['fa_allowed_from_statuses'] ) : array();
		$blocked_to   = isset( $_POST['fa_blocked_to_statuses'] ) ? (array) wp_unslash( $_POST['fa_blocked_to_statuses'] ) : array();

		$allowed_from = array_map( 'sanitize_text_field', $allowed_from );
		$blocked_to   = array_map( 'sanitize_text_field', $blocked_to );

		$valid_statuses = array_keys( $statuses );

		$allowed_from = array_values( array_intersect( $allowed_from, $valid_statuses ) );
		$blocked_to   = array_values( array_intersect( $blocked_to, $valid_statuses ) );

		update_option(
			WAOSS_SETTINGS_KEY,
			array(
				'allowed_from_statuses' => $allowed_from,
				'blocked_to_statuses'   => $blocked_to,
			),
			false
		);

		echo '<div class="notice notice-success is-dismissible"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
	}

	$settings     = waoss_get_settings();
	$allowed_from = $settings['allowed_from_statuses'];
	$blocked_to   = $settings['blocked_to_statuses'];

	echo '<div class="wrap">';
	echo '<h1 style="margin-bottom:16px;">تنظیمات تغییر وضعیت خودکار</h1>';
	echo '<p style="margin-bottom:18px;">در این صفحه می‌توانید مشخص کنید زمان‌بندی تغییر وضعیت خودکار فقط از چه وضعیت‌هایی مجاز باشد و رفتن به چه وضعیت‌هایی ممنوع باشد.</p>';

	echo '<form method="post">';
	wp_nonce_field( 'waoss_settings_save', 'waoss_settings_nonce' );

	echo '<div class="fa-auto-status-grid">';

	echo '<div class="fa-auto-status-card">';
	echo '<h2>وضعیت‌های مجاز برای شروع</h2>';
	echo '<p style="margin-top:0;color:#666;line-height:1.9;">اگر هیچ موردی را انتخاب نکنید، شروع زمان‌بندی از همه وضعیت‌ها مجاز خواهد بود.</p>';
	echo '<div class="fa-auto-status-checklist">';
	foreach ( $statuses as $status_key => $status_label ) {
		echo '<label>';
		echo '<input type="checkbox" name="fa_allowed_from_statuses[]" value="' . esc_attr( $status_key ) . '" ' . checked( in_array( $status_key, $allowed_from, true ), true, false ) . '>';
		echo '<span>' . esc_html( $status_label ) . '</span>';
		echo '</label>';
	}
	echo '</div>';
	echo '</div>';

	echo '<div class="fa-auto-status-card">';
	echo '<h2>وضعیت‌های ممنوع برای مقصد</h2>';
	echo '<p style="margin-top:0;color:#666;line-height:1.9;">هر وضعیتی که اینجا انتخاب شود، در فرم سفارش اصلا نمایش داده نمی‌شود و ذخیره زمان‌بندی به آن وضعیت هم مجاز نخواهد بود.</p>';
	echo '<div class="fa-auto-status-checklist">';
	foreach ( $statuses as $status_key => $status_label ) {
		echo '<label>';
		echo '<input type="checkbox" name="fa_blocked_to_statuses[]" value="' . esc_attr( $status_key ) . '" ' . checked( in_array( $status_key, $blocked_to, true ), true, false ) . '>';
		echo '<span>' . esc_html( $status_label ) . '</span>';
		echo '</label>';
	}
	echo '</div>';
	echo '</div>';

	echo '</div>';

	echo '<p style="margin-top:18px;">';
	echo '<button type="submit" name="waoss_settings_submit" class="button button-primary">ذخیره تنظیمات</button>';
	echo '</p>';

	echo '<div class="fa-auto-status-note">';
	echo 'نکته: اگر وضعیت فعلی سفارش در لیست وضعیت‌های مجاز برای شروع نباشد، ثبت زمان‌بندی جدید انجام نمی‌شود. همچنین اگر وضعیت مقصد در لیست وضعیت‌های ممنوع باشد، در فرم سفارش نمایش داده نمی‌شود و از سمت سرور هم ذخیره نخواهد شد.';
	echo '</div>';

	echo '</form>';
	echo '</div>';
}

/**
 * ---------------------------------------------------
 * صفحه لیست سفارشات زمان‌بندی‌شده
 * ---------------------------------------------------
 */
function waoss_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'شما دسترسی لازم را ندارید.' );
	}

	$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page = 30;

	$query_data = waoss_get_scheduled_order_ids_paginated( $paged, $per_page );
	$order_ids  = ! empty( $query_data['ids'] ) ? $query_data['ids'] : array();
	$pages      = ! empty( $query_data['pages'] ) ? absint( $query_data['pages'] ) : 1;

	$orders = array();

	if ( ! empty( $order_ids ) ) {
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( ! waoss_has_valid_schedule( $order ) ) {
				continue;
			}

			$orders[] = $order;
		}
	}

	echo '<div class="wrap">';
	echo '<h1 style="margin-bottom:16px;">سفارشات زمان‌بندی‌شده</h1>';
	echo '<p style="margin-bottom:18px;">در این صفحه فقط سفارش‌هایی نمایش داده می‌شوند که هم‌اکنون زمان‌بندی فعال دارند.</p>';

	echo '<table class="widefat striped" style="max-width:100%;">';
	echo '<thead>';
	echo '<tr>';
	echo '<th style="width:90px;">سفارش</th>';
	echo '<th>مشتری</th>';
	echo '<th>وضعیت فعلی</th>';
	echo '<th>وضعیت مقصد</th>';
	echo '<th>بازه</th>';
	echo '<th>زمان اجرا</th>';
	echo '<th>ثبت توسط</th>';
	echo '<th>زمان ثبت</th>';
	echo '<th style="width:160px;">عملیات</th>';
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';

	if ( ! empty( $orders ) ) {
		foreach ( $orders as $order ) {
			$order_id      = $order->get_id();
			$customer_name = $order->get_formatted_billing_full_name();
			$current_label = wc_get_order_status_name( $order->get_status() );
			$target        = $order->get_meta( WAOSS_META_TARGET, true );
			$qty           = $order->get_meta( WAOSS_META_QTY, true );
			$unit          = $order->get_meta( WAOSS_META_UNIT, true );
			$run_at_local  = $order->get_meta( WAOSS_META_RUN_AT_LOCAL, true );
			$set_by_name   = $order->get_meta( WAOSS_META_SET_BY_NAME, true );
			$set_at        = $order->get_meta( WAOSS_META_SET_AT, true );
			$view_url      = $order->get_edit_order_url();

			echo '<tr>';
			echo '<td><a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer">#' . esc_html( $order_id ) . '</a></td>';
			echo '<td>' . esc_html( $customer_name ? $customer_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $current_label ) . '</td>';
			echo '<td>' . esc_html( waoss_get_status_label( $target ) ) . '</td>';
			echo '<td>' . esc_html( waoss_get_interval_label( $qty, $unit ) ) . '</td>';
			echo '<td>' . esc_html( waoss_format_datetime( $run_at_local ) ) . '</td>';
			echo '<td>' . esc_html( $set_by_name ? $set_by_name : '—' ) . '</td>';
			echo '<td>' . esc_html( waoss_format_datetime( $set_at ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer">مشاهده سفارش</a></td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="9">هیچ سفارش زمان‌بندی‌شده‌ای پیدا نشد.</td></tr>';
	}

	echo '</tbody>';
	echo '</table>';

	if ( $pages > 1 ) {
		$prev_url = '';
		$next_url = '';

		if ( $paged > 1 ) {
			$prev_url = add_query_arg(
				array(
					'page'  => 'fa-scheduled-order-statuses',
					'paged' => $paged - 1,
				),
				admin_url( 'admin.php' )
			);
		}

		if ( $paged < $pages ) {
			$next_url = add_query_arg(
				array(
					'page'  => 'fa-scheduled-order-statuses',
					'paged' => $paged + 1,
				),
				admin_url( 'admin.php' )
			);
		}

		echo '<div class="fa-auto-status-nav">';

		if ( $prev_url ) {
			echo '<a class="button" href="' . esc_url( $prev_url ) . '">صفحه قبلی</a>';
		}

		if ( $next_url ) {
			echo '<a class="button button-primary" href="' . esc_url( $next_url ) . '">صفحه بعدی</a>';
		}

		echo '</div>';
	}

	echo '<p style="margin-top:18px;color:#666;">تعداد رکوردهای نمایش داده‌شده در این صفحه: ' . esc_html( count( $orders ) ) . '</p>';
	echo '</div>';
}
