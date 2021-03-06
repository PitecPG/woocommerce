<?php
/**
 * WooCommerce Order Functions
 *
 * Functions for order specific things.
 *
 * @author 		WooThemes
 * @category 	Core
 * @package 	WooCommerce/Functions
 * @version     2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Wrapper for get_posts specific to orders.
 *
 * This function should be used for order retrieval so that when we move to
 * custom tables, functions still work.
 *
 * Args:
 * 		status array|string List of order statuses to find
 * 		type array|string Order type, e.g. shop_order or shop_order_refund
 * 		parent int post/order parent
 * 		customer int|string|array User ID or billing email to limit orders to a
 * 			particular user. Accepts array of values. Array of values is OR'ed. If array of array is passed, each array will be AND'ed.
 * 			e.g. test@test.com, 1, array( 1, 2, 3 ), array( array( 1, 'test@test.com' ), 2, 3 )
 * 		limit int Maximum of orders to retrieve.
 * 		offset int Offset of orders to retrieve.
 * 		page int Page of orders to retrieve. Ignored when using the 'offset' arg.
 * 		exclude array Order IDs to exclude from the query.
 * 		orderby string Order by date, title, id, modified, rand etc
 * 		order string ASC or DESC
 * 		return string Type of data to return. Allowed values:
 * 			ids array of order ids
 * 			objects array of order objects (default)
 * 		paginate bool If true, the return value will be an array with values:
 * 			'orders'        => array of data (return value above),
 * 			'total'         => total number of orders matching the query
 * 			'max_num_pages' => max number of pages found
 *
 * @since  2.6.0
 * @param  array $args Array of args (above)
 * @return array|stdClass Number of pages and an array of order objects if
 *                             paginate is true, or just an array of values.
 */
function wc_get_orders( $args ) {
	$args = wp_parse_args( $args, array(
		'status'   => array_keys( wc_get_order_statuses() ),
		'type'     => wc_get_order_types( 'view-orders' ),
		'parent'   => null,
		'customer' => null,
		'email'    => '',
		'limit'    => get_option( 'posts_per_page' ),
		'offset'   => null,
		'page'     => 1,
		'exclude'  => array(),
		'orderby'  => 'date',
		'order'    => 'DESC',
		'return'   => 'objects',
		'paginate' => false,
	) );

	// Handle some BW compatibility arg names where wp_query args differ in naming.
	$map_legacy = array(
		'numberposts'    => 'limit',
		'post_type'      => 'type',
		'post_status'    => 'status',
		'post_parent'    => 'parent',
		'author'         => 'customer',
		'posts_per_page' => 'limit',
		'paged'          => 'page',
	);

	foreach ( $map_legacy as $from => $to ) {
		if ( isset( $args[ $from ] ) ) {
			$args[ $to ] = $args[ $from ];
		}
	}

	/**
	 * Generate WP_Query args. This logic will change if orders are moved to
	 * custom tables in the future.
	 */
	$wp_query_args = array(
		'post_type'      => $args['type'] ? $args['type'] : 'shop_order',
		'post_status'    => $args['status'],
		'posts_per_page' => $args['limit'],
		'meta_query'     => array(),
		'fields'         => 'ids',
		'orderby'        => $args['orderby'],
		'order'          => $args['order'],
	);

	if ( ! is_null( $args['parent'] ) ) {
		$wp_query_args['post_parent'] = absint( $args['parent'] );
	}

	if ( ! is_null( $args['offset'] ) ) {
		$wp_query_args['offset'] = absint( $args['offset'] );
	} else {
		$wp_query_args['paged'] = absint( $args['page'] );
	}

	if ( ! empty( $args['customer'] ) ) {
		$values = is_array( $args['customer'] ) ? $args['customer'] : array( $args['customer'] );
		$wp_query_args['meta_query'][] = _wc_get_orders_generate_customer_meta_query( $values );
	}

	if ( ! empty( $args['exclude'] ) ) {
		$wp_query_args['post__not_in'] = array_map( 'absint', $args['exclude'] );
	}

	if ( ! $args['paginate' ] ) {
		$wp_query_args['no_found_rows'] = true;
	}

	// Get results.
	$orders = new WP_Query( $wp_query_args );

	if ( 'objects' === $args['return'] ) {
		$return = array_map( 'wc_get_order', $orders->posts );
	} else {
		$return = $orders->posts;
	}

	if ( $args['paginate' ] ) {
		return (object) array(
			'orders'        => $return,
			'total'         => $orders->found_posts,
			'max_num_pages' => $orders->max_num_pages,
		);
	} else {
		return $return;
	}
}

/**
 * Generate meta query for wc_get_orders. Used internally only.
 * @since  2.6.0
 * @param  array $values
 * @param  string $relation
 * @return array
 */
function _wc_get_orders_generate_customer_meta_query( $values, $relation = 'or' ) {
	$meta_query = array(
		'relation' => strtoupper( $relation ),
		'customer_emails' => array(
			'key'     => '_billing_email',
			'value'   => array(),
			'compare' => 'IN',
		),
		'customer_ids' => array(
			'key'     => '_customer_user',
			'value'   => array(),
			'compare' => 'IN',
		)
	);
	foreach ( $values as $value ) {
		if ( is_array( $value ) ) {
			$meta_query[] = _wc_get_orders_generate_customer_meta_query( $value, 'and' );
		} elseif ( is_email( $value ) ) {
			$meta_query['customer_emails']['value'][] = sanitize_email( $value );
		} else {
			$meta_query['customer_ids']['value'][] = strval( absint( $value ) );
		}
	}

	if ( empty( $meta_query['customer_emails']['value'] ) ) {
		unset( $meta_query['customer_emails'] );
		unset( $meta_query['relation'] );
	}

	if ( empty( $meta_query['customer_ids']['value'] ) ) {
		unset( $meta_query['customer_ids'] );
		unset( $meta_query['relation'] );
	}

	return $meta_query;
}

/**
 * Get all order statuses.
 *
 * @since 2.2
 * @return array
 */
function wc_get_order_statuses() {
	$order_statuses = array(
		'wc-pending'    => _x( 'Pending Payment', 'Order status', 'woocommerce' ),
		'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
		'wc-on-hold'    => _x( 'On Hold', 'Order status', 'woocommerce' ),
		'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' ),
		'wc-cancelled'  => _x( 'Cancelled', 'Order status', 'woocommerce' ),
		'wc-refunded'   => _x( 'Refunded', 'Order status', 'woocommerce' ),
		'wc-failed'     => _x( 'Failed', 'Order status', 'woocommerce' ),
	);
	return apply_filters( 'wc_order_statuses', $order_statuses );
}

/**
 * See if a string is an order status.
 * @param  string $maybe_status Status, including any wc- prefix
 * @return bool
 */
function wc_is_order_status( $maybe_status ) {
	$order_statuses = wc_get_order_statuses();
	return isset( $order_statuses[ $maybe_status ] );
}

/**
 * Main function for returning orders, uses the WC_Order_Factory class.
 *
 * @since  2.2
 * @param  mixed $the_order Post object or post ID of the order.
 * @return WC_Order|WC_Refund
 */
function wc_get_order( $the_order = false ) {
	if ( ! did_action( 'woocommerce_init' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'wc_get_order should not be called before the woocommerce_init action.', 'woocommerce' ), '2.5' );
		return false;
	}
	return WC()->order_factory->get_order( $the_order );
}

/**
 * Get the nice name for an order status.
 *
 * @since  2.2
 * @param  string $status
 * @return string
 */
function wc_get_order_status_name( $status ) {
	$statuses = wc_get_order_statuses();
	$status   = 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;
	$status   = isset( $statuses[ 'wc-' . $status ] ) ? $statuses[ 'wc-' . $status ] : $status;

	return $status;
}

/**
 * Finds an Order ID based on an order key.
 *
 * @access public
 * @param string $order_key An order key has generated by
 * @return int The ID of an order, or 0 if the order could not be found
 */
function wc_get_order_id_by_order_key( $order_key ) {
	global $wpdb;

	// Faster than get_posts()
	$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_order_key' AND meta_value = %s", $order_key ) );

	return $order_id;
}

/**
 * Get all registered order types.
 *
 * $for optionally define what you are getting order types for so only relevent types are returned.
 *
 * e.g. for 'order-meta-boxes', 'order-count'
 *
 * @since  2.2
 * @param  string $for
 * @return array
 */
function wc_get_order_types( $for = '' ) {
	global $wc_order_types;

	if ( ! is_array( $wc_order_types ) ) {
		$wc_order_types = array();
	}

	$order_types = array();

	switch ( $for ) {
		case 'order-count' :
			foreach ( $wc_order_types as $type => $args ) {
				if ( ! $args['exclude_from_order_count'] ) {
					$order_types[] = $type;
				}
			}
		break;
		case 'order-meta-boxes' :
			foreach ( $wc_order_types as $type => $args ) {
				if ( $args['add_order_meta_boxes'] ) {
					$order_types[] = $type;
				}
			}
		break;
		case 'view-orders' :
			foreach ( $wc_order_types as $type => $args ) {
				if ( ! $args['exclude_from_order_views'] ) {
					$order_types[] = $type;
				}
			}
		break;
		case 'reports' :
			foreach ( $wc_order_types as $type => $args ) {
				if ( ! $args['exclude_from_order_reports'] ) {
					$order_types[] = $type;
				}
			}
		break;
		case 'sales-reports' :
			foreach ( $wc_order_types as $type => $args ) {
				if ( ! $args['exclude_from_order_sales_reports'] ) {
					$order_types[] = $type;
				}
			}
		break;
		case 'order-webhooks' :
			foreach ( $wc_order_types as $type => $args ) {
				if ( ! $args['exclude_from_order_webhooks'] ) {
					$order_types[] = $type;
				}
			}
		break;
		default :
			$order_types = array_keys( $wc_order_types );
		break;
	}

	return apply_filters( 'wc_order_types', $order_types, $for );
}

/**
 * Get an order type by post type name.
 * @param  string post type name
 * @return bool|array of datails about the order type
 */
function wc_get_order_type( $type ) {
	global $wc_order_types;

	if ( isset( $wc_order_types[ $type ] ) ) {
		return $wc_order_types[ $type ];
	} else {
		return false;
	}
}

/**
 * Register order type. Do not use before init.
 *
 * Wrapper for register post type, as well as a method of telling WC which.
 * post types are types of orders, and having them treated as such.
 *
 * $args are passed to register_post_type, but there are a few specific to this function:
 * 		- exclude_from_orders_screen (bool) Whether or not this order type also get shown in the main.
 * 		orders screen.
 * 		- add_order_meta_boxes (bool) Whether or not the order type gets shop_order meta boxes.
 * 		- exclude_from_order_count (bool) Whether or not this order type is excluded from counts.
 * 		- exclude_from_order_views (bool) Whether or not this order type is visible by customers when.
 * 		viewing orders e.g. on the my account page.
 * 		- exclude_from_order_reports (bool) Whether or not to exclude this type from core reports.
 * 		- exclude_from_order_sales_reports (bool) Whether or not to exclude this type from core sales reports.
 *
 * @since  2.2
 * @see    register_post_type for $args used in that function
 * @param  string $type Post type. (max. 20 characters, can not contain capital letters or spaces)
 * @param  array $args An array of arguments.
 * @return bool Success or failure
 */
function wc_register_order_type( $type, $args = array() ) {
	if ( post_type_exists( $type ) ) {
		return false;
	}

	global $wc_order_types;

	if ( ! is_array( $wc_order_types ) ) {
		$wc_order_types = array();
	}

	// Register as a post type
	if ( is_wp_error( register_post_type( $type, $args ) ) ) {
		return false;
	}

	// Register for WC usage
	$order_type_args = array(
		'exclude_from_orders_screen'       => false,
		'add_order_meta_boxes'             => true,
		'exclude_from_order_count'         => false,
		'exclude_from_order_views'         => false,
		'exclude_from_order_webhooks'      => false,
		'exclude_from_order_reports'       => false,
		'exclude_from_order_sales_reports' => false,
		'class_name'                       => 'WC_Order'
	);

	$args                    = array_intersect_key( $args, $order_type_args );
	$args                    = wp_parse_args( $args, $order_type_args );
	$wc_order_types[ $type ] = $args;

	return true;
}

/**
 * Grant downloadable product access to the file identified by $download_id.
 *
 * @access public
 * @param string $download_id file identifier
 * @param int $product_id product identifier
 * @param WC_Order $order the order
 * @param  int $qty purchased
 * @return int|bool insert id or false on failure
 */
function wc_downloadable_file_permission( $download_id, $product_id, $order, $qty = 1 ) {
	global $wpdb;

	$user_email = sanitize_email( $order->get_billing_email() );
	$limit      = trim( get_post_meta( $product_id, '_download_limit', true ) );
	$expiry     = trim( get_post_meta( $product_id, '_download_expiry', true ) );

	$limit      = empty( $limit ) ? '' : absint( $limit ) * $qty;

	// Default value is NULL in the table schema
	$expiry     = empty( $expiry ) ? null : absint( $expiry );

	if ( $expiry ) {
		$order_completed_date = date_i18n( "Y-m-d", strtotime( $order->completed_date ) );
		$expiry = date_i18n( "Y-m-d", strtotime( $order_completed_date . ' + ' . $expiry . ' DAY' ) );
	}

	$data = apply_filters( 'woocommerce_downloadable_file_permission_data', array(
		'download_id'			=> $download_id,
		'product_id' 			=> $product_id,
		'user_id' 				=> absint( $order->get_user_id() ),
		'user_email' 			=> $user_email,
		'order_id' 				=> $order->get_id(),
		'order_key' 			=> $order->get_order_key(),
		'downloads_remaining' 	=> $limit,
		'access_granted'		=> current_time( 'mysql' ),
		'download_count'		=> 0
	));

	$format = apply_filters( 'woocommerce_downloadable_file_permission_format', array(
		'%s',
		'%s',
		'%s',
		'%s',
		'%s',
		'%s',
		'%s',
		'%s',
		'%d'
	), $data);

	if ( ! is_null( $expiry ) ) {
			$data['access_expires'] = $expiry;
			$format[] = '%s';
	}

	// Downloadable product - give access to the customer
	$result = $wpdb->insert( $wpdb->prefix . 'woocommerce_downloadable_product_permissions',
		$data,
		$format
	);

	do_action( 'woocommerce_grant_product_download_access', $data );

	return $result ? $wpdb->insert_id : false;
}

/**
 * Order Status completed - GIVE DOWNLOADABLE PRODUCT ACCESS TO CUSTOMER.
 *
 * @access public
 * @param int $order_id
 */
function wc_downloadable_product_permissions( $order_id ) {
	if ( get_post_meta( $order_id, '_download_permissions_granted', true ) == 1 ) {
		return; // Only do this once
	}

	$order = wc_get_order( $order_id );

	if ( $order && $order->has_status( 'processing' ) && get_option( 'woocommerce_downloads_grant_access_after_payment' ) == 'no' ) {
		return;
	}

	if ( sizeof( $order->get_items() ) > 0 ) {
		foreach ( $order->get_items() as $item ) {
			$_product = $item->get_product();

			if ( $_product && $_product->exists() && $_product->is_downloadable() ) {
				$downloads = $_product->get_files();

				foreach ( array_keys( $downloads ) as $download_id ) {
					wc_downloadable_file_permission( $download_id, $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'], $order, $item['qty'] );
				}
			}
		}
	}

	update_post_meta( $order_id, '_download_permissions_granted', 1 );

	do_action( 'woocommerce_grant_product_download_permissions', $order_id );
}
add_action( 'woocommerce_order_status_completed', 'wc_downloadable_product_permissions' );
add_action( 'woocommerce_order_status_processing', 'wc_downloadable_product_permissions' );


/**
 * Add a item to an order (for example a line item).
 *
 * @access public
 * @param int $order_id
 * @return mixed
 */
function wc_add_order_item( $order_id, $item ) {
	global $wpdb;

	$order_id = absint( $order_id );

	if ( ! $order_id )
		return false;

	$defaults = array(
		'order_item_name' 		=> '',
		'order_item_type' 		=> 'line_item',
	);

	$item = wp_parse_args( $item, $defaults );

	$wpdb->insert(
		$wpdb->prefix . "woocommerce_order_items",
		array(
			'order_item_name' 		=> $item['order_item_name'],
			'order_item_type' 		=> $item['order_item_type'],
			'order_id'				=> $order_id
		),
		array(
			'%s', '%s', '%d'
		)
	);

	$item_id = absint( $wpdb->insert_id );

	do_action( 'woocommerce_new_order_item', $item_id, $item, $order_id );

	return $item_id;
}

/**
 * Update an item for an order.
 *
 * @since 2.2
 * @param int $item_id
 * @param array $args either `order_item_type` or `order_item_name`
 * @return bool true if successfully updated, false otherwise
 */
function wc_update_order_item( $item_id, $args ) {
	global $wpdb;

	$update = $wpdb->update( $wpdb->prefix . 'woocommerce_order_items', $args, array( 'order_item_id' => $item_id ) );

	if ( false === $update ) {
		return false;
	}

	do_action( 'woocommerce_update_order_item', $item_id, $args );

	return true;
}

/**
 * Delete an item from the order it belongs to based on item id.
 *
 * @access public
 * @param int $item_id
 * @return bool
 */
function wc_delete_order_item( $item_id ) {
	global $wpdb;

	$item_id = absint( $item_id );

	if ( ! $item_id )
		return false;

	do_action( 'woocommerce_before_delete_order_item', $item_id );

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d", $item_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d", $item_id ) );

	do_action( 'woocommerce_delete_order_item', $item_id );

	return true;
}

/**
 * WooCommerce Order Item Meta API - Update term meta.
 *
 * @access public
 * @param mixed $item_id
 * @param mixed $meta_key
 * @param mixed $meta_value
 * @param string $prev_value (default: '')
 * @return bool
 */
function wc_update_order_item_meta( $item_id, $meta_key, $meta_value, $prev_value = '' ) {
	if ( update_metadata( 'order_item', $item_id, $meta_key, $meta_value, $prev_value ) ) {
		$cache_key = WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'item_meta_array_' . $item_id;
		wp_cache_delete( $cache_key, 'orders' );
		return true;
	}
	return false;
}

/**
 * WooCommerce Order Item Meta API - Add term meta.
 *
 * @access public
 * @param mixed $item_id
 * @param mixed $meta_key
 * @param mixed $meta_value
 * @param bool $unique (default: false)
 * @return int New row ID or 0
 */
function wc_add_order_item_meta( $item_id, $meta_key, $meta_value, $unique = false ) {
	if ( $meta_id = add_metadata( 'order_item', $item_id, $meta_key, $meta_value, $unique ) ) {
		$cache_key = WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'item_meta_array_' . $item_id;
		wp_cache_delete( $cache_key, 'orders' );
		return $meta_id;
	}
	return 0;
}

/**
 * WooCommerce Order Item Meta API - Delete term meta.
 *
 * @access public
 * @param mixed $item_id
 * @param mixed $meta_key
 * @param string $meta_value (default: '')
 * @param bool $delete_all (default: false)
 * @return bool
 */
function wc_delete_order_item_meta( $item_id, $meta_key, $meta_value = '', $delete_all = false ) {
	if ( delete_metadata( 'order_item', $item_id, $meta_key, $meta_value, $delete_all ) ) {
		$cache_key = WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'item_meta_array_' . $item_id;
		wp_cache_delete( $cache_key, 'orders' );
		return true;
	}
	return false;
}

/**
 * WooCommerce Order Item Meta API - Get term meta.
 *
 * @access public
 * @param mixed $item_id
 * @param mixed $key
 * @param bool $single (default: true)
 * @return mixed
 */
function wc_get_order_item_meta( $item_id, $key, $single = true ) {
	return get_metadata( 'order_item', $item_id, $key, $single );
}

/**
 * Cancel all unpaid orders after held duration to prevent stock lock for those products.
 *
 * @access public
 */
function wc_cancel_unpaid_orders() {
	global $wpdb;

	$held_duration = get_option( 'woocommerce_hold_stock_minutes' );

	if ( $held_duration < 1 || get_option( 'woocommerce_manage_stock' ) != 'yes' )
		return;

	$date = date( "Y-m-d H:i:s", strtotime( '-' . absint( $held_duration ) . ' MINUTES', current_time( 'timestamp' ) ) );

	$unpaid_orders = $wpdb->get_col( $wpdb->prepare( "
		SELECT posts.ID
		FROM {$wpdb->posts} AS posts
		WHERE 	posts.post_type   IN ('" . implode( "','", wc_get_order_types() ) . "')
		AND 	posts.post_status = 'wc-pending'
		AND 	posts.post_modified < %s
	", $date ) );

	if ( $unpaid_orders ) {
		foreach ( $unpaid_orders as $unpaid_order ) {
			$order = wc_get_order( $unpaid_order );

			if ( apply_filters( 'woocommerce_cancel_unpaid_order', 'checkout' === get_post_meta( $unpaid_order, '_created_via', true ), $order ) ) {
				$order->update_status( 'cancelled', __( 'Unpaid order cancelled - time limit reached.', 'woocommerce' ) );
			}
		}
	}

	wp_clear_scheduled_hook( 'woocommerce_cancel_unpaid_orders' );
	wp_schedule_single_event( time() + ( absint( $held_duration ) * 60 ), 'woocommerce_cancel_unpaid_orders' );
}
add_action( 'woocommerce_cancel_unpaid_orders', 'wc_cancel_unpaid_orders' );

/**
 * Return the count of processing orders.
 *
 * @access public
 * @return int
 */
function wc_processing_order_count() {
	return wc_orders_count( 'processing' );
}

/**
 * Return the orders count of a specific order status.
 *
 * @access public
 * @param string $status
 * @return int
 */
function wc_orders_count( $status ) {
	global $wpdb;

	$count = 0;
	$status = 'wc-' . $status;
	$order_statuses = array_keys( wc_get_order_statuses() );

	if ( ! in_array( $status, $order_statuses ) ) {
		return 0;
	}

	$cache_key = WC_Cache_Helper::get_cache_prefix( 'orders' ) . $status;
	$cached_count = wp_cache_get( $cache_key, 'counts' );

	if ( false !== $cached_count ) {
		return $cached_count;
	}

	foreach ( wc_get_order_types( 'order-count' ) as $type ) {
		$query = "SELECT COUNT( * ) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s";
		$count += $wpdb->get_var( $wpdb->prepare( $query, $type, $status ) );
	}

	wp_cache_set( $cache_key, $count, 'counts' );

	return $count;
}

/**
 * Clear all transients cache for order data.
 *
 * @param int $post_id (default: 0)
 */
function wc_delete_shop_order_transients( $post_id = 0 ) {
	$post_id             = absint( $post_id );
	$transients_to_clear = array();

	// Clear report transients
	$reports = WC_Admin_Reports::get_reports();

	foreach ( $reports as $report_group ) {
		foreach ( $report_group['reports'] as $report_key => $report ) {
			$transients_to_clear[] = 'wc_report_' . $report_key;
		}
	}

	// clear API report transient
	$transients_to_clear[] = 'wc_admin_report';

	// Clear transients where we have names
	foreach( $transients_to_clear as $transient ) {
		delete_transient( $transient );
	}

	// Clear money spent for user associated with order
	if ( $post_id && ( $user_id = get_post_meta( $post_id, '_customer_user', true ) ) ) {
		delete_user_meta( $user_id, '_money_spent' );
		delete_user_meta( $user_id, '_order_count' );
	}

	// Increments the transient version to invalidate cache
	WC_Cache_Helper::get_transient_version( 'orders', true );

	// Do the same for regular cache
	WC_Cache_Helper::incr_cache_prefix( 'orders' );

	do_action( 'woocommerce_delete_shop_order_transients', $post_id );
}

/**
 * See if we only ship to billing addresses.
 * @return bool
 */
function wc_ship_to_billing_address_only() {
	return 'billing_only' === get_option( 'woocommerce_ship_to_destination' );
}

/**
 * Create a new order refund programmatically.
 *
 * Returns a new refund object on success which can then be used to add additional data.
 *
 * @since 2.2
 * @param array $args
 * @return WC_Order_Refund|WP_Error
 */
function wc_create_refund( $args = array() ) {
	$default_args = array(
		'amount'     => 0,
		'reason'     => null,
		'order_id'   => 0,
		'refund_id'  => 0,
		'line_items' => array(),
	);

	try {
		$args   = wp_parse_args( $args, $default_args );
		$order  = wc_get_order( $args['order_id'] );
		$refund = new WC_Order_Refund( $args['refund_id'] );

		if ( ! $order ) {
			throw new Exception( __( 'Invalid order ID.', 'woocommerce' ) );
		}

		// prevent negative refunds
		if ( 0 > $args['amount'] ) {
			$args['amount'] = 0;
		}
		$refund->set_amount( $args['amount'] );
		$refund->set_parent_id( absint( $args['order_id'] ) );
		$refund->set_refunded_by( get_current_user_id() ? get_current_user_id() : 1 );

		if ( ! is_null( $args['reason'] ) ) {
			$refund->set_reason( $args['reason'] );
		}

		// Negative line items
		if ( sizeof( $args['line_items'] ) > 0 ) {
			$items = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );

			foreach ( $items as $item_id => $item ) {
				if ( ! isset( $args['line_items'][ $item_id ] ) || ( empty( $args['line_items'][ $item_id ]['qty'] ) && empty( $args['line_items'][ $item_id ]['refund_total'] ) && empty( $args['line_items'][ $item_id ]['refund_tax'] ) ) ) {
					continue;
				}

				if ( ! isset( $args['line_items'][ $item_id ]['refund_tax'] ) ) {
					$args['line_items'][ $item_id ]['refund_tax'] = array();
				}

				$class         = get_class( $item );
				$refunded_item = new $class( $item );

				$refunded_item->set_id( 0 );
				$refunded_item->add_meta_data( '_refunded_item_id', $item_id, true );
				$refunded_item->set_total( wc_format_refund_total( $args['line_items'][ $item_id ]['refund_total'] ) );
				$refunded_item->set_total_tax( wc_format_refund_total( array_sum( $args['line_items'][ $item_id ]['refund_tax'] ) ) );
				$refunded_item->set_taxes( array( 'total' => array_map( 'wc_format_refund_total', $args['line_items'][ $item_id ]['refund_tax'] ), 'subtotal' => array_map( 'wc_format_refund_total', $args['line_items'][ $item_id ]['refund_tax'] ) ) );

				if ( is_callable( array( $refunded_item, 'set_subtotal' ) ) ) {
					$refunded_item->set_subtotal( wc_format_refund_total( $args['line_items'][ $item_id ]['refund_total'] ) );
					$refunded_item->set_subtotal_tax( wc_format_refund_total( array_sum( $args['line_items'][ $item_id ]['refund_tax'] ) ) );
				}

				$refund->add_item( $refunded_item );
			}
		}

		$refund->update_taxes();
		$refund->calculate_totals( false );
		$refund->set_total( $args['amount'] * -1 );
		$refund->save();

	} catch ( Exception $e ) {
		return new WP_Error( 'error', $e->getMessage() );
	}

	return $refund;
}

/**
 * Get tax class by tax id.
 *
 * @since 2.2
 * @param int $tax_id
 * @return string
 */
function wc_get_tax_class_by_tax_id( $tax_id ) {
	global $wpdb;

	$tax_class = $wpdb->get_var( $wpdb->prepare( "SELECT tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", $tax_id ) );

	return wc_clean( $tax_class );
}

/**
 * Get payment gateway class by order data.
 *
 * @since 2.2
 * @param int|WC_Order $order
 * @return WC_Payment_Gateway|bool
 */
function wc_get_payment_gateway_by_order( $order ) {
	if ( WC()->payment_gateways() ) {
		$payment_gateways = WC()->payment_gateways->payment_gateways();
	} else {
		$payment_gateways = array();
	}

	if ( ! is_object( $order ) ) {
		$order_id = absint( $order );
		$order    = wc_get_order( $order_id );
	}

	return isset( $payment_gateways[ $order->get_payment_method() ] ) ? $payment_gateways[ $order->get_payment_method() ] : false;
}

/**
 * When refunding an order, create a refund line item if the partial refunds do not match order total.
 *
 * This is manual; no gateway refund will be performed.
 *
 * @since 2.4
 * @param int $order_id
 */
function wc_order_fully_refunded( $order_id ) {
	$order       = wc_get_order( $order_id );
	$max_refund  = wc_format_decimal( $order->get_total() - $order->get_total_refunded() );

	if ( ! $max_refund ) {
		return;
	}

	// Create the refund object
	wc_create_refund( array(
		'amount'     => $max_refund,
		'reason'     => __( 'Order Fully Refunded', 'woocommerce' ),
		'order_id'   => $order_id,
		'line_items' => array()
	) );

	wc_delete_shop_order_transients( $order_id );
}
add_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );

/**
 * Search in orders.
 *
 * @since  2.6.0
 * @param  string $term Term to search.
 * @return array List of orders ID.
 */
function wc_order_search( $term ) {
	global $wpdb;

	$term     = str_replace( 'Order #', '', wc_clean( $term ) );
	$post_ids = array();

	// Search fields.
	$search_fields = array_map( 'wc_clean', apply_filters( 'woocommerce_shop_order_search_fields', array(
		'_order_key',
		'_billing_company',
		'_billing_address_1',
		'_billing_address_2',
		'_billing_city',
		'_billing_postcode',
		'_billing_country',
		'_billing_state',
		'_billing_email',
		'_billing_phone',
		'_shipping_address_1',
		'_shipping_address_2',
		'_shipping_city',
		'_shipping_postcode',
		'_shipping_country',
		'_shipping_state'
	) ) );

	// Search orders.
	if ( is_numeric( $term ) ) {
		$post_ids = array_unique( array_merge(
			$wpdb->get_col(
				$wpdb->prepare( "SELECT DISTINCT p1.post_id FROM {$wpdb->postmeta} p1 WHERE p1.meta_key IN ('" . implode( "','", array_map( 'esc_sql', $search_fields ) ) . "') AND p1.meta_value LIKE '%%%s%%';", wc_clean( $term ) )
			),
			array( absint( $term ) )
		) );
	} elseif ( ! empty( $search_fields ) ) {
		$post_ids = array_unique( array_merge(
			$wpdb->get_col(
				$wpdb->prepare( "
					SELECT DISTINCT p1.post_id
					FROM {$wpdb->postmeta} p1
					INNER JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id
					WHERE
						( p1.meta_key = '_billing_first_name' AND p2.meta_key = '_billing_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
					OR
						( p1.meta_key = '_shipping_first_name' AND p2.meta_key = '_shipping_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
					OR
						( p1.meta_key IN ('" . implode( "','", array_map( 'esc_sql', $search_fields ) ) . "') AND p1.meta_value LIKE '%%%s%%' )
					",
					$term, $term, $term
				)
			),
			$wpdb->get_col(
				$wpdb->prepare( "
					SELECT order_id
					FROM {$wpdb->prefix}woocommerce_order_items as order_items
					WHERE order_item_name LIKE '%%%s%%'
					",
					$term
				)
			)
		) );
	}

	return $post_ids;
}


/**
 * Update total sales amount for each product within a paid order.
 *
 * @since 2.7.0
 * @param int $order_id
 */
function wc_update_total_sales_counts( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || 'yes' === get_post_meta( $order_id, '_recorded_sales', true ) ) {
		return;
	}

	if ( sizeof( $order->get_items() ) > 0 ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item['product_id'] > 0 ) {
				update_post_meta( $item['product_id'], 'total_sales', absint( get_post_meta( $item['product_id'], 'total_sales', true ) ) + absint( $item['qty'] ) );
			}
		}
	}

	update_post_meta( $order_id, '_recorded_sales', 'yes' );

	/**
	 * Called when sales for an order are recorded
	 *
	 * @param int $order_id order id
	 */
	do_action( 'woocommerce_recorded_sales', $order_id );
}
add_action( 'woocommerce_order_status_completed', 'wc_update_total_sales_counts' );
add_action( 'woocommerce_order_status_processing', 'wc_update_total_sales_counts' );
add_action( 'woocommerce_order_status_on-hold', 'wc_update_total_sales_counts' );

/**
 * Update used coupon amount for each coupon within an order.
 *
 * @since 2.7.0
 * @param int $order_id
 */
function wc_update_coupon_usage_counts( $order_id ) {
	$order        = wc_get_order( $order_id );
	$has_recorded = get_post_meta( $order_id, '_recorded_coupon_usage_counts', true );

	if ( ! $order ) {
		return;
	}

	if ( $order->has_status( 'cancelled' ) && 'yes' === $has_recorded ) {
		$action = 'reduce';
		delete_post_meta( $order_id, '_recorded_coupon_usage_counts' );
	} elseif ( ! $order->has_status( 'cancelled' ) && 'yes' !== $has_recorded ) {
		$action = 'increase';
		update_post_meta( $order_id, '_recorded_coupon_usage_counts', 'yes' );
	} else {
		return;
	}

	if ( sizeof( $order->get_used_coupons() ) > 0 ) {
		foreach ( $order->get_used_coupons() as $code ) {
			if ( ! $code ) {
				continue;
			}

			$coupon = new WC_Coupon( $code );

			if ( ! $used_by = $order->get_user_id() ) {
				$used_by = $order->get_billing_email();
			}

			switch ( $action ) {
				case 'reduce' :
					$coupon->dcr_usage_count( $used_by );
				break;
				case 'increase' :
					$coupon->inc_usage_count( $used_by );
				break;
			}
		}
	}
}
add_action( 'woocommerce_order_status_completed', 'wc_update_total_sales_counts' );
add_action( 'woocommerce_order_status_processing', 'wc_update_total_sales_counts' );
add_action( 'woocommerce_order_status_on-hold', 'wc_update_total_sales_counts' );
add_action( 'woocommerce_order_status_cancelled', 'wc_update_total_sales_counts' );

/**
 * When a payment is complete, we can reduce stock levels for items within an order.
 * @since 2.7.0
 * @param int $order_id
 */
function wc_maybe_reduce_stock_levels( $order_id ) {
	if ( apply_filters( 'woocommerce_payment_complete_reduce_order_stock', ! get_post_meta( $order_id, '_order_stock_reduced', true ), $order_id ) ) {
		wc_reduce_stock_levels( $order_id );
		add_post_meta( $order_id, '_order_stock_reduced', '1', true );
	}
}
add_action( 'woocommerce_payment_complete', 'wc_maybe_reduce_stock_levels' );

/**
 * Reduce stock levels for items within an order.
 * @since 2.7.0
 * @param int $order_id
 */
function wc_reduce_stock_levels( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( 'yes' === get_option( 'woocommerce_manage_stock' ) && $order && apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ) && sizeof( $order->get_items() ) > 0 ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item->is_type( 'line_item' ) && ( $product = $item->get_product() ) && $product->managing_stock() ) {
				$qty       = apply_filters( 'woocommerce_order_item_quantity', $item['qty'], $order, $item );
				$new_stock = $product->reduce_stock( $qty );
				$item_name = $product->get_sku() ? $product->get_sku(): $item['product_id'];

				if ( ! empty( $item['variation_id'] ) ) {
					$order->add_order_note( sprintf( __( 'Item %s variation #%s stock reduced from %s to %s.', 'woocommerce' ), $item_name, $item['variation_id'], $new_stock + $qty, $new_stock ) );
				} else {
					$order->add_order_note( sprintf( __( 'Item %s stock reduced from %s to %s.', 'woocommerce' ), $item_name, $new_stock + $qty, $new_stock ) );
				}

				if ( $new_stock < 0 ) {
		            do_action( 'woocommerce_product_on_backorder', array( 'product' => $product, 'order_id' => $order_id, 'quantity' => $qty_ordered ) );
		        }
			}
		}

		do_action( 'woocommerce_reduce_order_stock', $order );
	}
}
