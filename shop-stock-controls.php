<?php
/**
 * Plugin Name: Shop Stock Controls
 * Plugin URI:  https://github.com/islandboymv/shop-stock-controls
 * Description: Two inventory guardrails for WooCommerce: a "Reorder needed" dashboard widget that lists
 *              every product at or below its low-stock threshold, and a per-order purchase limit
 *              (store-wide default, overridable per product). Self-updates from GitHub Releases.
 * Author:      Islandboy
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.8
 * Text Domain: shop-stock-controls
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSC_VERSION', '0.1.0' );
define( 'SSC_TELEMETRY_URL', 'https://plugin-telemetry.islandboy.workers.dev/ping' );

const SSC_MAX_QTY_META      = '_ssc_max_qty';
const SSC_OPTION_MAX_QTY    = 'ssc_default_max_qty';
const SSC_REORDER_TRANSIENT = 'ssc_reorder_list';

/**
 * Declare WooCommerce feature compatibility. This plugin only reads stock and
 * validates cart contents (never touches orders), so it is fully compatible
 * with HPOS and the Cart/Checkout blocks.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

/**
 * GitHub-powered automatic updates.
 *
 * To ship an update: bump the Version header, commit, and publish a GitHub
 * Release (e.g. tag v0.2.0).
 */
add_action( 'plugins_loaded', function () {
	require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/islandboymv/shop-stock-controls/',
		__FILE__,
		'shop-stock-controls'
	);
} );

/* ---------------------------------------------------------------------------
 * Anonymous active-install telemetry.
 *
 * Sends a daily ping containing ONLY the plugin slug, a one-way SHA-256 hash of
 * the site URL (never the URL itself), and the version — so the central server
 * can show an "active installs" count. It cannot identify your site.
 *
 * Opt out entirely with:
 *   add_filter( 'ssc_telemetry_enabled', '__return_false' );
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ssc_telemetry_ping' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ssc_telemetry_ping' );
	}
} );

add_action( 'ssc_telemetry_ping', 'ssc_send_telemetry' );

function ssc_send_telemetry() {
	if ( ! apply_filters( 'ssc_telemetry_enabled', true ) ) {
		return;
	}
	$home = home_url();
	foreach ( array( 'localhost', '127.0.0.1', '.test', '.local', '.localhost', '.example' ) as $needle ) {
		if ( false !== strpos( $home, $needle ) ) {
			return; // don't count local/dev sites
		}
	}
	wp_remote_post( SSC_TELEMETRY_URL, array(
		'timeout'  => 5,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json' ),
		'body'     => wp_json_encode( array(
			'slug'    => 'shop-stock-controls',
			'site'    => hash( 'sha256', $home ),
			'version' => SSC_VERSION,
		) ),
	) );
}

register_activation_hook( __FILE__, function () {
	wp_schedule_single_event( time() + 30, 'ssc_telemetry_ping' );
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'ssc_telemetry_ping' );
	delete_transient( SSC_REORDER_TRANSIENT );
} );

/* ===========================================================================
 * PART 1 — "Reorder needed" dashboard widget
 *
 * A product needs reordering when its stock quantity is at or below its
 * low-stock threshold. The threshold is WooCommerce's own: the per-product
 * "Low stock threshold" field (Product data → Inventory), falling back to the
 * store-wide value at WooCommerce → Settings → Products → Inventory.
 * ========================================================================= */

/**
 * Build the list of products at/below their reorder point.
 *
 * One query against the product meta lookup table (which holds stock for every
 * stock-managed product/variation) plus one for the per-product thresholds —
 * no WC_Product objects are instantiated, so this stays cheap even with
 * hundreds of items. Result is cached in a transient.
 *
 * @return array[] rows: id, parent, title, sku, stock, threshold
 */
function ssc_get_reorder_list() {
	$cached = get_transient( SSC_REORDER_TRANSIENT );
	if ( false !== $cached ) {
		return $cached;
	}

	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_parent, p.post_title, p.post_type, l.sku, l.stock_quantity
		 FROM {$wpdb->wc_product_meta_lookup} l
		 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
		 WHERE l.stock_quantity IS NOT NULL
		   AND p.post_status = 'publish'",
		ARRAY_A
	);

	// Per-product thresholds in one query: id => threshold ('' when unset).
	$thresholds = array();
	$metas      = $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_low_stock_amount' AND meta_value != ''",
		ARRAY_A
	);
	foreach ( $metas as $m ) {
		$thresholds[ (int) $m['post_id'] ] = (int) $m['meta_value'];
	}

	$global = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );

	$list       = array();
	$variations = array(); // parent IDs that have a listed variation, to avoid double-listing the parent
	foreach ( $rows as $row ) {
		$id     = (int) $row['ID'];
		$parent = (int) $row['post_parent'];

		// Threshold fallback chain: own meta → parent's meta (variations) → store-wide.
		if ( isset( $thresholds[ $id ] ) ) {
			$threshold = $thresholds[ $id ];
		} elseif ( $parent && isset( $thresholds[ $parent ] ) ) {
			$threshold = $thresholds[ $parent ];
		} else {
			$threshold = $global;
		}

		$stock = (int) $row['stock_quantity'];
		if ( $stock > $threshold ) {
			continue;
		}

		$list[] = array(
			'id'        => $id,
			'parent'    => 'product_variation' === $row['post_type'] ? $parent : 0,
			'title'     => $row['post_title'],
			'sku'       => (string) $row['sku'],
			'stock'     => $stock,
			'threshold' => $threshold,
		);
		if ( 'product_variation' === $row['post_type'] ) {
			$variations[ $parent ] = true;
		}
	}

	// If a variable parent and its variations both carry lookup rows, keep the variations only.
	$list = array_values( array_filter( $list, function ( $item ) use ( $variations ) {
		return $item['parent'] || empty( $variations[ $item['id'] ] );
	} ) );

	usort( $list, function ( $a, $b ) {
		return $a['stock'] <=> $b['stock'];
	} );

	set_transient( SSC_REORDER_TRANSIENT, $list, 15 * MINUTE_IN_SECONDS );

	return $list;
}

/** Recompute the list promptly after any stock movement or product edit. */
add_action( 'woocommerce_product_set_stock', 'ssc_flush_reorder_cache' );
add_action( 'woocommerce_variation_set_stock', 'ssc_flush_reorder_cache' );
add_action( 'woocommerce_update_product', 'ssc_flush_reorder_cache' );
function ssc_flush_reorder_cache() {
	delete_transient( SSC_REORDER_TRANSIENT );
}

add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$count = count( ssc_get_reorder_list() );
	$title = __( 'Reorder needed', 'shop-stock-controls' );
	if ( $count ) {
		$title .= ' <span class="awaiting-mod count-' . $count . '" style="display:inline-block;vertical-align:top;margin-left:4px;box-sizing:border-box;min-width:18px;height:18px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;padding:0 5px;">' . $count . '</span>';
	}

	wp_add_dashboard_widget( 'ssc_reorder_widget', $title, 'ssc_render_reorder_widget' );

	// Float the widget to the top of the main column so it isn't buried.
	global $wp_meta_boxes;
	$widget = $wp_meta_boxes['dashboard']['normal']['core']['ssc_reorder_widget'];
	unset( $wp_meta_boxes['dashboard']['normal']['core']['ssc_reorder_widget'] );
	$wp_meta_boxes['dashboard']['normal']['core'] = array_merge(
		array( 'ssc_reorder_widget' => $widget ),
		$wp_meta_boxes['dashboard']['normal']['core']
	);
} );

function ssc_render_reorder_widget() {
	$list = ssc_get_reorder_list();

	if ( ! $list ) {
		echo '<p style="margin:8px 0;">✅ ' . esc_html__( 'All stocked items are above their reorder point.', 'shop-stock-controls' ) . '</p>';
		return;
	}

	$shown = array_slice( $list, 0, 20 );
	?>
	<table class="widefat striped" style="border:0;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'shop-stock-controls' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'shop-stock-controls' ); ?></th>
				<th style="text-align:right;"><?php esc_html_e( 'In stock', 'shop-stock-controls' ); ?></th>
				<th style="text-align:right;"><?php esc_html_e( 'Reorder at', 'shop-stock-controls' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $shown as $item ) :
			$edit_id = $item['parent'] ? $item['parent'] : $item['id'];
			?>
			<tr>
				<td><a href="<?php echo esc_url( get_edit_post_link( $edit_id ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></td>
				<td><?php echo esc_html( $item['sku'] ?: '—' ); ?></td>
				<td style="text-align:right;<?php echo $item['stock'] <= 0 ? 'color:#d63638;font-weight:600;' : ''; ?>"><?php echo esc_html( $item['stock'] ); ?></td>
				<td style="text-align:right;"><?php echo esc_html( $item['threshold'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	if ( count( $list ) > count( $shown ) ) {
		printf(
			'<p style="margin:10px 0 4px;">%s</p>',
			esc_html( sprintf(
				/* translators: %d: number of additional low-stock products */
				__( '…and %d more.', 'shop-stock-controls' ),
				count( $list ) - count( $shown )
			) )
		);
	}
	printf(
		'<p style="margin:10px 0 4px;"><a href="%s">%s</a></p>',
		esc_url( admin_url( 'admin.php?page=wc-admin&path=%2Fanalytics%2Fstock&filter=lowstock' ) ),
		esc_html__( 'Open the full stock report →', 'shop-stock-controls' )
	);
}

/* ===========================================================================
 * PART 2 — Per-order purchase limit
 *
 * A store-wide default cap on how many units of any one product a customer can
 * buy in a single order, overridable per product:
 *
 *   • blank per-product field → the store-wide default applies
 *   • a number → that product's own cap
 *   • 0        → no limit for that product
 *
 * For variable products the cap applies to the product as a whole (all
 * variations combined), which matches how shoppers think about "one item".
 * ========================================================================= */

/**
 * Resolve the max purchasable quantity per order for a product.
 *
 * @param  int $product_id Parent product ID (pass the variation's parent).
 * @return int 0 = unlimited.
 */
function ssc_max_qty_for( $product_id ) {
	$meta = get_post_meta( $product_id, SSC_MAX_QTY_META, true );
	if ( '' !== $meta ) {
		return max( 0, (int) $meta );
	}
	$default = get_option( SSC_OPTION_MAX_QTY, '' );
	return '' === $default || null === $default ? 0 : max( 0, (int) $default );
}

/** Parent product ID for any product/variation object. */
function ssc_parent_id( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return 0;
	}
	return $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
}

/**
 * Units of a product already in the cart (all variations combined),
 * optionally excluding one cart line (used when validating a qty update).
 */
function ssc_qty_in_cart( $product_id, $exclude_key = null ) {
	$qty = 0;
	if ( ! WC()->cart ) {
		return 0;
	}
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( $key !== $exclude_key && (int) $item['product_id'] === (int) $product_id ) {
			$qty += (int) $item['quantity'];
		}
	}
	return $qty;
}

/** The error message shown when a limit is hit. */
function ssc_limit_message( $product_id, $limit ) {
	return sprintf(
		/* translators: 1: quantity limit, 2: product name */
		__( 'Only %1$d of “%2$s” can be purchased per order.', 'shop-stock-controls' ),
		$limit,
		get_the_title( $product_id )
	);
}

/** Block adding to cart beyond the limit (covers ?add-to-cart= URLs too). */
add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id, $quantity ) {
	$limit = ssc_max_qty_for( $product_id );
	if ( $limit > 0 && ssc_qty_in_cart( $product_id ) + max( 1, (int) $quantity ) > $limit ) {
		wc_add_notice( ssc_limit_message( $product_id, $limit ), 'error' );
		return false;
	}
	return $passed;
}, 10, 3 );

/** Block raising a cart line's quantity beyond the limit. */
add_filter( 'woocommerce_update_cart_validation', function ( $passed, $cart_item_key, $values, $quantity ) {
	$product_id = (int) $values['product_id'];
	$limit      = ssc_max_qty_for( $product_id );
	if ( $limit > 0 && ssc_qty_in_cart( $product_id, $cart_item_key ) + (int) $quantity > $limit ) {
		wc_add_notice( ssc_limit_message( $product_id, $limit ), 'error' );
		return false;
	}
	return $passed;
}, 10, 4 );

/** Safety net at cart/checkout: nothing over-limit can slip through to payment. */
add_action( 'woocommerce_check_cart_items', function () {
	if ( ! WC()->cart ) {
		return;
	}
	$totals = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$pid = (int) $item['product_id'];
		$totals[ $pid ] = ( $totals[ $pid ] ?? 0 ) + (int) $item['quantity'];
	}
	foreach ( $totals as $pid => $qty ) {
		$limit = ssc_max_qty_for( $pid );
		if ( $limit > 0 && $qty > $limit ) {
			wc_add_notice( ssc_limit_message( $pid, $limit ), 'error' );
		}
	}
} );

/** Cap the quantity selector on product and cart pages. */
add_filter( 'woocommerce_quantity_input_args', function ( $args, $product ) {
	$limit = ssc_max_qty_for( ssc_parent_id( $product ) );
	if ( $limit > 0 && ( $args['max_value'] <= 0 || $args['max_value'] > $limit ) ) {
		$args['max_value'] = $limit;
	}
	return $args;
}, 10, 2 );

/** Cap the quantity selector for each variation of a variable product. */
add_filter( 'woocommerce_available_variation', function ( $data, $variable, $variation ) {
	$limit = ssc_max_qty_for( ssc_parent_id( $variation ) );
	if ( $limit > 0 && ( $data['max_qty'] <= 0 || $data['max_qty'] > $limit ) ) {
		$data['max_qty'] = $limit;
	}
	return $data;
}, 10, 3 );

/** Store API (Cart/Checkout blocks) add-to-cart parity with the classic filter. */
add_action( 'woocommerce_store_api_validate_add_to_cart', function ( $product, $request ) {
	$parent_id = ssc_parent_id( $product );
	$limit     = ssc_max_qty_for( $parent_id );
	$quantity  = isset( $request['quantity'] ) ? (int) $request['quantity'] : 1;
	if ( $limit > 0 && ssc_qty_in_cart( $parent_id ) + max( 1, $quantity ) > $limit ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'ssc_purchase_limit',
			esc_html( ssc_limit_message( $parent_id, $limit ) ),
			400
		);
	}
}, 10, 2 );

/* ---------------------------------------------------------------------------
 * Admin: per-product override field (Product data → Inventory)
 * ------------------------------------------------------------------------- */
add_action( 'woocommerce_product_options_inventory_product_data', function () {
	$default = get_option( SSC_OPTION_MAX_QTY, '' );

	echo '<div class="options_group">';
	woocommerce_wp_text_input( array(
		'id'                => SSC_MAX_QTY_META,
		'label'             => __( 'Max quantity per order', 'shop-stock-controls' ),
		'type'              => 'number',
		'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
		'placeholder'       => '' !== $default
			/* translators: %d: the store-wide default limit */
			? sprintf( __( 'Store default (%d)', 'shop-stock-controls' ), (int) $default )
			: __( 'No limit', 'shop-stock-controls' ),
		'desc_tip'          => true,
		'description'       => __( 'Most a customer can buy of this product in one order. Leave blank to use the store-wide default; enter 0 for no limit on this product.', 'shop-stock-controls' ),
	) );
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( ! isset( $_POST[ SSC_MAX_QTY_META ] ) ) {
		return;
	}
	$raw = trim( wp_unslash( $_POST[ SSC_MAX_QTY_META ] ) );
	$product->update_meta_data( SSC_MAX_QTY_META, '' === $raw ? '' : (string) max( 0, (int) $raw ) );
} );

/* ---------------------------------------------------------------------------
 * Admin: store-wide default (WooCommerce → Settings → Products → Inventory)
 * ------------------------------------------------------------------------- */
add_filter( 'woocommerce_inventory_settings', function ( $settings ) {
	$field = array(
		'title'             => __( 'Max quantity per order', 'shop-stock-controls' ),
		'desc'              => __( 'Store-wide default for how many units of any one product a customer can buy in a single order. Leave blank for no limit. Override per product under Product data → Inventory.', 'shop-stock-controls' ),
		'id'                => SSC_OPTION_MAX_QTY,
		'type'              => 'number',
		'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
		'css'               => 'width:80px;',
		'default'           => '',
		'autoload'          => false,
		'desc_tip'          => true,
	);

	// Insert right after the low-stock threshold field so the two live together.
	$out = array();
	foreach ( $settings as $setting ) {
		$out[] = $setting;
		if ( isset( $setting['id'] ) && 'woocommerce_notify_low_stock_amount' === $setting['id'] ) {
			$out[] = $field;
		}
	}
	return $out;
} );
