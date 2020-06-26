<?php

/**
* Jetpack_Google_Analytics_Universal hooks and and enqueues support for analytics.js
* https://developers.google.com/analytics/devguides/collection/analyticsjs/
* https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-ecommerce
*
* @author allendav
*/

/**
* Bail if accessed directly
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jetpack_Google_Analytics_Universal {
	public function __construct() {
		add_filter( 'jetpack_wga_universal_commands', array( $this, 'maybe_anonymize_ip' ) );
		add_filter( 'jetpack_wga_universal_commands', array( $this, 'maybe_track_purchases' ) );

		add_action( 'wp_head', array( $this, 'wp_head' ), 999999 );

		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'add_to_cart' ) );
		add_action( 'wp_footer', array( $this, 'loop_add_to_cart' ) );
		add_action( 'woocommerce_after_cart', array( $this, 'remove_from_cart' ) );
		add_action( 'woocommerce_after_mini_cart', array( $this, 'remove_from_cart' ) );
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'remove_from_cart_attributes' ), 10, 2 );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'listing_impression' ) );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'listing_click' ) );
		add_action( 'woocommerce_after_single_product', array( $this, 'product_detail' ) );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'checkout_process' ) );

		add_action( 'woocommerce_add_to_cart', array( $this, 'amp_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'amp_remove_from_cart' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( $this, 'amp_after_purchase' ), 10, 1 );
		add_action( 'wp_footer', array( $this, 'amp_send_ga_events' ) );

		// we need to send a pageview command last - so we use priority 24 to add
		// this command's JavaScript just before wc_print_js is called (pri 25)
		add_action( 'wp_footer', array( $this, 'send_pageview_in_footer' ), 24 );
	}

	/**
	 * Generate a GA event when adding an item to the cart.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param string $product_id Product ID.
	 * @param int    $quantity Product quantity.
	 * @param int    $variation_id Product variation ID.
	 * @param object $variation Product variation.
	 * @param object $cart_item_data Cart item data.
	 */
	public function amp_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		$is_amp_request = class_exists( 'Jetpack_AMP_Support' ) && Jetpack_AMP_Support::is_amp_request();
		if ( ! $is_amp_request ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product_sku  = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );
			$product_name = $product->get_name();

			$events   = WC()->session->get( 'wc_ga_events' );
			$events[] = array(
				'type'      => 'add',
				'ga_params' => array(
					'pa'    => 'add',
					'pr1id' => $product_sku,
					'pr1nm' => $product_name,
					'pr1qt' => $quantity,
				),
			);
			WC()->session->set( 'wc_ga_events', $events );
		}
	}

	/**
	 * Generate a GA event when removing an item to the cart.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param object $cart The Cart Object.
	 */
	public function amp_remove_from_cart( $cart_item_key, $cart ) {
		$is_amp_request = class_exists( 'Jetpack_AMP_Support' ) && Jetpack_AMP_Support::is_amp_request();
		if ( ! $is_amp_request ) {
			return;
		}

		$product_id = $cart->cart_contents[ $cart_item_key ]['product_id'];
		$product    = wc_get_product( $product_id );
		if ( $product ) {
			$product_sku  = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );
			$product_name = $product->get_name();

			$events   = WC()->session->get( 'wc_ga_events' );
			$events[] = array(
				'type'      => 'remove',
				'ga_params' => array(
					'pa'    => 'remove',
					'pr1id' => $product_sku,
					'pr1nm' => $product_name,
				),
			);
			WC()->session->set( 'wc_ga_events', $events );
		}
	}

	/**
	 * Generate a GA event when removing an item to the cart.
	 *
	 * @param int $order_id The Order ID.
	 */
	public function amp_after_purchase( $order_id ) {
		$is_amp_request = class_exists( 'Jetpack_AMP_Support' ) && Jetpack_AMP_Support::is_amp_request();
		if ( ! $is_amp_request ) {
			return;
		}

		$events      = WC()->session->get( 'wc_ga_events' );
		$order       = wc_get_order( $order_id );
		$order_total = $order->get_total();
		$order_tax   = $order->get_total_tax();

		$i     = 1;
		$event = array(
			'type'      => 'purchase',
			'ga_params' => array(
				'pa' => 'purchase',
				'ti' => $order_id,
				'tr' => $order_total,
				'tt' => $order_tax,
			),
		);
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$event['ga_params'][ 'pr' . $i . 'id' ] = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );
				$event['ga_params'][ 'pr' . $i . 'nm' ] = $item->get_name();
				$event['ga_params'][ 'pr' . $i . 'qt' ] = $item->get_quantity();
				$i++;
			}
		}

		$events[] = $event;
		WC()->session->set( 'wc_ga_events', $events );
	}

	/**
	 * Send the stored events to GA.
	 */
	public function amp_send_ga_events() {
		$is_amp_request = class_exists( 'Jetpack_AMP_Support' ) && Jetpack_AMP_Support::is_amp_request();
		if ( ! $is_amp_request ) {
			return;
		}

		if ( wp_doing_ajax() ) {
			return;
		}

		if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		$events = WC()->session->get( 'wc_ga_events' );
		if ( ! is_array( $events ) ) {
			return;
		}

		foreach ( $events as $i => $event ) {
			?>
			<amp-analytics type='googleanalytics'>
				<script type='application/json'>
				{
					"vars": {
						"account": "<?php echo esc_html( Jetpack_Google_Analytics_Options::get_tracking_code() ); ?>"
					},
					"triggers": {
						"trackPageview": {
							"on": "visible",
							"request": "pageview",
							"extraUrlParams": <?php echo wp_json_encode( $event['ga_params'] ); ?>
						}
					}
				}
				</script>
			</amp-analytics>
			<?php

			array_shift( $events );
		}
		WC()->session->set( 'wc_ga_events', $events );
	}

	public function wp_head() {
		$tracking_code = Jetpack_Google_Analytics_Options::get_tracking_code();
		if ( empty( $tracking_code ) ) {
			echo "<!-- No tracking ID configured for Jetpack Google Analytics -->\r\n";
			return;
		}

		// If we're in the admin_area, return without inserting code.
		if ( is_admin() ) {
			return;
		}

		if ( Jetpack_AMP_Support::is_amp_request() ) {
			// For Reader mode — legacy.
			add_filter( 'amp_post_template_analytics', 'Jetpack_Google_Analytics::amp_analytics_entries', 1000 );
			// For Standard and Transitional modes.
			add_filter( 'amp_analytics_entries', 'Jetpack_Google_Analytics::amp_analytics_entries', 1000 );
			return;
		}

		/**
		 * Allow for additional elements to be added to the universal Google Analytics queue (ga) array
		 *
		 * @since 5.6.0
		 *
		 * @param array $custom_vars Array of universal Google Analytics queue elements
		 */
		$universal_commands = apply_filters( 'jetpack_wga_universal_commands', array() );

		$async_code = "
			<!-- Jetpack Google Analytics -->
			<script>
				window.ga = window.ga || function(){ ( ga.q = ga.q || [] ).push( arguments ) }; ga.l=+new Date;
				ga( 'create', '%tracking_id%', 'auto' );
				ga( 'require', 'ec' );
				%universal_commands%
			</script>
			<script async src='https://www.google-analytics.com/analytics.js'></script>
			<!-- End Jetpack Google Analytics -->
		";
		$async_code = str_replace( '%tracking_id%', $tracking_code, $async_code );

		$universal_commands_string = implode( "\r\n", $universal_commands );
		$async_code = str_replace( '%universal_commands%', $universal_commands_string, $async_code );

		echo "$async_code\r\n";
	}

	public function maybe_anonymize_ip( $command_array ) {
		if ( Jetpack_Google_Analytics_Options::anonymize_ip_is_enabled() ) {
			array_push( $command_array, "ga( 'set', 'anonymizeIp', true );" );
		}

		return $command_array;
	}

	public function maybe_track_purchases( $command_array ) {
		global $wp;

		if ( ! Jetpack_Google_Analytics_Options::track_purchases_is_enabled() ) {
			return $command_array;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $command_array;
		}

		$minimum_woocommerce_active = class_exists( 'WooCommerce' ) && version_compare( WC_VERSION, '3.0', '>=' );
		if ( ! $minimum_woocommerce_active ) {
			return $command_array;
		}

		if ( ! is_order_received_page() ) {
			return $command_array;
		}

		$order_id = isset( $wp->query_vars['order-received'] ) ? $wp->query_vars['order-received'] : 0;
		if ( 0 == $order_id ) {
			return $command_array;
		}

		// A 1 indicates we've already tracked this order - don't do it again
		if ( 1 == get_post_meta( $order_id, '_ga_tracked', true ) ) {
			return $command_array;
		}

		$order = new WC_Order( $order_id );
		$order_currency = $order->get_currency();
		$command = "ga( 'set', '&cu', '" . esc_js( $order_currency ) . "' );";
		array_push( $command_array, $command );

		// Order items
		if ( $order->get_items() ) {
			foreach ( $order->get_items() as $item ) {
				$product = $order->get_product_from_item( $item );
				$product_sku_or_id = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );

				$item_details = array(
					'id' => $product_sku_or_id,
					'name' => $item['name'],
					'category' => Jetpack_Google_Analytics_Utils::get_product_categories_concatenated( $product ),
					'price' => $order->get_item_total( $item ),
					'quantity' => $item['qty'],
				);
				$command = "ga( 'ec:addProduct', " . wp_json_encode( $item_details ) . " );";
				array_push( $command_array, $command );
			}
		}

		// Order summary
		$summary = array(
			'id' => $order->get_order_number(),
			'affiliation' => get_bloginfo( 'name' ),
			'revenue' => $order->get_total(),
			'tax' => $order->get_total_tax(),
			'shipping' => $order->get_total_shipping()
		);
		$command = "ga( 'ec:setAction', 'purchase', " . wp_json_encode( $summary ) . " );";
		array_push( $command_array, $command );

		update_post_meta( $order_id, '_ga_tracked', 1 );

		return $command_array;
	}

	public function add_to_cart() {
		if ( ! Jetpack_Google_Analytics_Options::track_add_to_cart_is_enabled() ) {
			return;
		}

		if ( ! is_single() ) {
			return;
		}

		global $product;

		$product_sku_or_id = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );
		$selector = ".single_add_to_cart_button";

		wc_enqueue_js(
			"$( '" . esc_js( $selector ) . "' ).click( function() {
				var productDetails = {
					'id': '" . esc_js( $product_sku_or_id ) . "',
					'name' : '" . esc_js( $product->get_title() ) . "',
					'quantity': $( 'input.qty' ).val() ? $( 'input.qty' ).val() : '1',
				};
				ga( 'ec:addProduct', productDetails );
				ga( 'ec:setAction', 'add' );
				ga( 'send', 'event', 'UX', 'click', 'add to cart' );
			} );"
		);
	}

	public function loop_add_to_cart() {
		if ( ! Jetpack_Google_Analytics_Options::track_add_to_cart_is_enabled() ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$minimum_woocommerce_active = class_exists( 'WooCommerce' ) && version_compare( WC_VERSION, '3.0', '>=' );
		if ( ! $minimum_woocommerce_active ) {
			return;
		}

		$selector = ".add_to_cart_button:not(.product_type_variable, .product_type_grouped)";

		wc_enqueue_js(
			"$( '" . esc_js( $selector ) . "' ).click( function() {
				var productSku = $( this ).data( 'product_sku' );
				var productID = $( this ).data( 'product_id' );
				var productDetails = {
					'id': productSku ? productSku : '#' + productID,
					'quantity': $( this ).data( 'quantity' ),
				};
				ga( 'ec:addProduct', productDetails );
				ga( 'ec:setAction', 'add' );
				ga( 'send', 'event', 'UX', 'click', 'add to cart' );
			} );"
		);
	}

	public function remove_from_cart() {
		if ( ! Jetpack_Google_Analytics_Options::enhanced_ecommerce_tracking_is_enabled() ) {
			return;
		}

		if ( ! Jetpack_Google_Analytics_Options::track_remove_from_cart_is_enabled() ) {
			return;
		}

		// We listen at div.woocommerce because the cart 'form' contents get forcibly
		// updated and subsequent removals from cart would then not have this click
		// handler attached
		wc_enqueue_js(
			"$( 'div.woocommerce' ).on( 'click', 'a.remove', function() {
				var productSku = $( this ).data( 'product_sku' );
				var productID = $( this ).data( 'product_id' );
				var quantity = $( this ).parent().parent().find( '.qty' ).val()
				var productDetails = {
					'id': productSku ? productSku : '#' + productID,
					'quantity': quantity ? quantity : '1',
				};
				ga( 'ec:addProduct', productDetails );
				ga( 'ec:setAction', 'remove' );
				ga( 'send', 'event', 'UX', 'click', 'remove from cart' );
			} );"
		);
	}

	/**
	 * Adds the product ID and SKU to the remove product link (for use by remove_from_cart above) if not present
	 *
	 * @param string $url Full HTML a tag of the link to remove an item from the cart.
	 * @param string $key Unique Key ID for a cart item.
	 */
	public function remove_from_cart_attributes( $url, $key ) {
		if ( false !== strpos( $url, 'data-product_id' ) ) {
			return $url;
		}

		$item    = WC()->cart->get_cart_item( $key );
		$product = $item['data'];

		$new_attributes = sprintf(
			'" data-product_id="%1$s" data-product_sku="%2$s">',
			esc_attr( $product->get_id() ),
			esc_attr( $product->get_sku() )
		);

		$url = str_replace( '">', $new_attributes, $url );
		return $url;
	}

	public function listing_impression() {
		if ( ! Jetpack_Google_Analytics_Options::enhanced_ecommerce_tracking_is_enabled() ) {
			return;
		}

		if ( ! Jetpack_Google_Analytics_Options::track_product_impressions_is_enabled() ) {
			return;
		}

		if ( isset( $_GET['s'] ) ) {
			$list = "Search Results";
		} else {
			$list = "Product List";
		}

		global $product, $woocommerce_loop;
		$product_sku_or_id = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );

		$item_details = array(
			'id' => $product_sku_or_id,
			'name' => $product->get_title(),
			'category' => Jetpack_Google_Analytics_Utils::get_product_categories_concatenated( $product ),
			'list' => $list,
			'position' => $woocommerce_loop['loop']
		);
		wc_enqueue_js( "ga( 'ec:addImpression', " . wp_json_encode( $item_details ) . " );" );
	}

	public function listing_click() {
		if ( ! Jetpack_Google_Analytics_Options::enhanced_ecommerce_tracking_is_enabled() ) {
			return;
		}

		if ( ! Jetpack_Google_Analytics_Options::track_product_clicks_is_enabled() ) {
			return;
		}

		if ( isset( $_GET['s'] ) ) {
			$list = "Search Results";
		} else {
			$list = "Product List";
		}

		global $product, $woocommerce_loop;
		$product_sku_or_id = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );

		$selector = ".products .post-" . esc_js( $product->get_id() ) . " a";

		$item_details = array(
			'id' => $product_sku_or_id,
			'name' => $product->get_title(),
			'category' => Jetpack_Google_Analytics_Utils::get_product_categories_concatenated( $product ),
			'position' => $woocommerce_loop['loop']
		);

		wc_enqueue_js(
			"$( '" . esc_js( $selector ) . "' ).click( function() {
				if ( true === $( this ).hasClass( 'add_to_cart_button' ) ) {
					return;
				}

				ga( 'ec:addProduct', " . wp_json_encode( $item_details ) . " );
				ga( 'ec:setAction', 'click', { list: '" . esc_js( $list ) . "' } );
				ga( 'send', 'event', 'UX', 'click', { list: '" . esc_js( $list ) . "' } );
			} );"
		);
	}

	public function product_detail() {
		if ( ! Jetpack_Google_Analytics_Options::enhanced_ecommerce_tracking_is_enabled() ) {
			return;
		}

		if ( ! Jetpack_Google_Analytics_Options::track_product_detail_view_is_enabled() ) {
			return;
		}

		global $product;
		$product_sku_or_id = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );

		$item_details = array(
			'id' => $product_sku_or_id,
			'name' => $product->get_title(),
			'category' => Jetpack_Google_Analytics_Utils::get_product_categories_concatenated( $product ),
			'price' => $product->get_price()
		);
		wc_enqueue_js(
			"ga( 'ec:addProduct', " . wp_json_encode( $item_details ) . " );" .
			"ga( 'ec:setAction', 'detail' );"
		);
	}

	public function checkout_process() {
		if ( ! Jetpack_Google_Analytics_Options::enhanced_ecommerce_tracking_is_enabled() ) {
			return;
		}

		if ( ! Jetpack_Google_Analytics_Options::track_checkout_started_is_enabled() ) {
			return;
		}

		$universal_commands = array();
		$cart = WC()->cart->get_cart();

		foreach ( $cart as $cart_item_key => $cart_item ) {
			/**
			* This filter is already documented in woocommerce/templates/cart/cart.php
			*/
			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$product_sku_or_id = Jetpack_Google_Analytics_Utils::get_product_sku_or_id( $product );

			$item_details = array(
				'id' => $product_sku_or_id,
				'name' => $product->get_title(),
				'category' => Jetpack_Google_Analytics_Utils::get_product_categories_concatenated( $product ),
				'price' => $product->get_price(),
				'quantity' => $cart_item[ 'quantity' ]
			);

			array_push( $universal_commands, "ga( 'ec:addProduct', " . wp_json_encode( $item_details ) . " );" );
		}

		array_push( $universal_commands, "ga( 'ec:setAction','checkout' );" );

		wc_enqueue_js( implode( "\r\n", $universal_commands ) );
	}

	public function send_pageview_in_footer() {
		if ( ! Jetpack_Google_Analytics_Options::has_tracking_code() ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wc_enqueue_js( "ga( 'send', 'pageview' );" );
	}
}
