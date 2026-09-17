<?php
/**
 * Plugin Name: Kitmage Add-on Manager
 * Description: Allows WooCommerce Memberships restricted add-on products to be purchased when a matching membership product is already in the cart.
 * Version: 1.0.3
 * Author: Mike@KitMage
 * Author URI: http://kitmage.com
 * Requires Plugins: woocommerce
 * Text Domain: kitmage-addon-manager
 *
 * @package KitmageAddonManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kitmage_Addon_Manager {
	const OPTION_NAME   = 'kitmage_addon_manager_rules';
	const DEBUG_OPTION  = 'kitmage_addon_manager_debug';
	const NONCE_NAME  = 'kitmage_addon_manager_save_rules';
	const NONCE_FIELD = 'kitmage_addon_manager_nonce';

	private static $instance = null;
	private $eligible_cache  = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'allow_qualified_restricted_purchase' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'allow_qualified_restricted_purchase' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_subscription_is_purchasable', array( $this, 'allow_qualified_restricted_purchase' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_subscription_variation_is_purchasable', array( $this, 'allow_qualified_restricted_purchase' ), PHP_INT_MAX, 2 );
		add_filter( 'wc_memberships_user_can', array( $this, 'allow_qualified_memberships_purchase_capability' ), PHP_INT_MAX, 5 );
		add_filter( 'wc_memberships_user_can_purchase', array( $this, 'allow_qualified_memberships_purchase_capability' ), PHP_INT_MAX, 5 );
		add_action( 'woocommerce_before_single_product', array( $this, 'prepare_single_product_add_to_cart' ), 1 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'maybe_render_add_to_cart' ), 35 );
		add_filter( 'the_content', array( $this, 'append_restriction_message' ), 20 );
		add_filter( 'elementor/widget/render_content', array( $this, 'render_elementor_add_to_cart_fallback' ), 20, 2 );
	}

	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Add-on Manager', 'kitmage-addon-manager' ),
			__( 'Add-on Manager', 'kitmage-addon-manager' ),
			'manage_woocommerce',
			'kitmage-addon-manager',
			array( $this, 'render_settings_page' )
		);
	}

	public function handle_settings_save() {
		if ( empty( $_POST['kitmage_addon_manager_action'] ) || 'save_rules' !== $_POST['kitmage_addon_manager_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage add-on rules.', 'kitmage-addon-manager' ) );
		}

		check_admin_referer( self::NONCE_NAME, self::NONCE_FIELD );

		$rules = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
		update_option( self::OPTION_NAME, $this->sanitize_rules( $rules ) );
		update_option( self::DEBUG_OPTION, ! empty( $_POST['debug_enabled'] ) ? 'yes' : 'no' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'kitmage-addon-manager', 'updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_settings_page() {
		$rules         = $this->get_rules();
		$debug_enabled = 'yes' === get_option( self::DEBUG_OPTION, 'no' );
		$tags          = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add-on Manager', 'kitmage-addon-manager' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rules saved.', 'kitmage-addon-manager' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=kitmage-addon-manager' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_NAME, self::NONCE_FIELD ); ?>
				<input type="hidden" name="kitmage_addon_manager_action" value="save_rules" />
				<table class="widefat striped" id="kitmage-addon-manager-rules">
					<thead><tr><th><?php esc_html_e( 'Restricted add-on product tag', 'kitmage-addon-manager' ); ?></th><th><?php esc_html_e( 'Qualifying product IDs', 'kitmage-addon-manager' ); ?></th><th><?php esc_html_e( 'Restriction message HTML', 'kitmage-addon-manager' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( array_values( $rules ? $rules : array( array() ) ) as $index => $rule ) : ?>
						<?php $this->render_rule_row( $index, $rule, $tags ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><label><input type="checkbox" name="debug_enabled" value="1" <?php checked( $debug_enabled ); ?> /> <?php esc_html_e( 'Enable debug logging to WooCommerce > Status > Logs', 'kitmage-addon-manager' ); ?></label></p>
				<p><button type="button" class="button" id="kitmage-add-rule"><?php esc_html_e( 'Add Rule', 'kitmage-addon-manager' ); ?></button></p>
				<?php submit_button( __( 'Save Rules', 'kitmage-addon-manager' ) ); ?>
			</form>
		</div>
		<script>
			(function(){
				const table = document.querySelector('#kitmage-addon-manager-rules tbody');
				document.querySelector('#kitmage-add-rule').addEventListener('click', function(){
					const row = table.querySelector('tr').cloneNode(true);
					const index = table.querySelectorAll('tr').length;
					row.querySelectorAll('select,input,textarea').forEach(function(field){ field.name = field.name.replace(/rules\[[0-9]+\]/, 'rules[' + index + ']'); field.value = ''; });
					table.appendChild(row);
				});
				table.addEventListener('click', function(event){ if (event.target.classList.contains('kitmage-remove-rule') && table.querySelectorAll('tr').length > 1) { event.target.closest('tr').remove(); } });
			}());
		</script>
		<?php
	}

	private function render_rule_row( $index, $rule, $tags ) {
		$tag_id      = isset( $rule['tag_id'] ) ? absint( $rule['tag_id'] ) : 0;
		$product_ids = isset( $rule['product_ids'] ) ? implode( ', ', array_map( 'absint', $rule['product_ids'] ) ) : '';
		$message     = isset( $rule['message'] ) ? $rule['message'] : '';
		?>
		<tr>
			<td><select name="rules[<?php echo esc_attr( $index ); ?>][tag_id]"><option value=""><?php esc_html_e( 'Select a tag', 'kitmage-addon-manager' ); ?></option><?php foreach ( $tags as $tag ) : ?><option value="<?php echo esc_attr( $tag->term_id ); ?>" <?php selected( $tag_id, $tag->term_id ); ?>><?php echo esc_html( $tag->name ); ?></option><?php endforeach; ?></select></td>
			<td><input class="regular-text" type="text" name="rules[<?php echo esc_attr( $index ); ?>][product_ids]" value="<?php echo esc_attr( $product_ids ); ?>" placeholder="123, 456" /></td>
			<td><textarea class="large-text" rows="3" name="rules[<?php echo esc_attr( $index ); ?>][message]"><?php echo esc_textarea( $message ); ?></textarea></td>
			<td><button type="button" class="button kitmage-remove-rule"><?php esc_html_e( 'Remove', 'kitmage-addon-manager' ); ?></button></td>
		</tr>
		<?php
	}

	public function allow_qualified_restricted_purchase( $is_purchasable, $product ) {
		$is_eligible = $this->is_eligible_for_cart_based_access( $product );

		$this->debug_log(
			'Purchasable filter checked.',
			array(
				'product_id'       => is_a( $product, 'WC_Product' ) ? $product->get_id() : absint( $product ),
				'product_type'     => is_a( $product, 'WC_Product' ) ? $product->get_type() : '',
				'incoming_value'   => $is_purchasable,
				'eligible'         => $is_eligible,
				'filtered_value'   => $is_eligible ? true : $is_purchasable,
			)
		);

		return $is_eligible ? true : $is_purchasable;
	}


	public function allow_qualified_memberships_purchase_capability( $user_can, $user_id = 0, $action = '', $target = array(), $when = '' ) {
		if ( false !== $user_can ) {
			return $user_can;
		}

		if ( is_array( $action ) && ! is_array( $target ) ) {
			$target = $action;
			$action = 'purchase';
		}

		$product_id = $this->get_memberships_capability_product_id( $action, $target );
		if ( $product_id && $this->is_eligible_for_cart_based_access( $product_id ) ) {
			return true;
		}

		return $user_can;
	}

	public function prepare_single_product_add_to_cart() {
		global $product;

		if ( is_a( $product, 'WC_Product' ) && $this->is_eligible_for_cart_based_access( $product ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		}
	}

	public function maybe_render_add_to_cart() {
		global $product;

		if ( ! is_a( $product, 'WC_Product' ) || ! $this->is_eligible_for_cart_based_access( $product ) ) {
			return;
		}

		ob_start();
		woocommerce_template_single_add_to_cart();
		$template_output = ob_get_clean();

		if ( trim( $template_output ) ) {
			echo $template_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce template output is escaped by the template.
			return;
		}

		echo $this->get_simple_add_to_cart_fallback_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by render_simple_add_to_cart_fallback().
	}


	public function render_elementor_add_to_cart_fallback( $content, $widget ) {
		if ( ! is_product() || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'woocommerce-product-add-to-cart' !== $widget->get_name() ) {
			return $content;
		}

		global $product;

		if ( ! is_a( $product, 'WC_Product' ) || ! $this->is_eligible_for_cart_based_access( $product ) || false !== strpos( $content, 'single_add_to_cart_button' ) ) {
			$this->debug_log( 'Elementor add-to-cart fallback skipped.', array( 'product_id' => is_a( $product, 'WC_Product' ) ? $product->get_id() : 0 ) );
			return $content;
		}

		$fallback = $this->get_simple_add_to_cart_fallback_html( $product );
		if ( '' === trim( $fallback ) ) {
			$this->debug_log( 'Elementor add-to-cart fallback produced no output.', array( 'product_id' => $product->get_id(), 'type' => $product->get_type(), 'in_stock' => $product->is_in_stock() ) );
			return $content;
		}

		$this->debug_log( 'Elementor add-to-cart fallback injected.', array( 'product_id' => $product->get_id() ) );
		return $content . $fallback;
	}

	public function append_restriction_message( $content ) {
		if ( ! is_product() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$product_id = get_the_ID();
		$is_eligible = $this->is_eligible_for_cart_based_access( $product_id );

		if ( $this->is_memberships_purchase_restricted( $product_id ) && $this->product_has_matching_rule( $product_id ) && ! $is_eligible && ! $this->memberships_allows_purchase( $product_id ) ) {
			$content .= '<div class="kitmage-addon-manager-restriction-message">' . wp_kses_post( $this->get_message_for_product( $product_id ) ) . '</div>';
		}

		return $content;
	}




	private function get_simple_add_to_cart_fallback_html( $product ) {
		ob_start();
		$this->render_simple_add_to_cart_fallback( $product );
		return ob_get_clean();
	}

	private function render_simple_add_to_cart_fallback( $product ) {
		if ( ! $product->is_type( array( 'simple', 'subscription' ) ) || ! $product->is_in_stock() ) {
			return;
		}

		echo '<form class="cart" action="' . esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ) . '" method="post" enctype="multipart/form-data">';

		do_action( 'woocommerce_before_add_to_cart_button' );
		do_action( 'woocommerce_before_add_to_cart_quantity' );

		woocommerce_quantity_input(
			array(
				'min_value'   => apply_filters( 'woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product ),
				'max_value'   => apply_filters( 'woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product ),
				'input_value' => $product->get_min_purchase_quantity(),
			)
		);

		do_action( 'woocommerce_after_add_to_cart_quantity' );

		echo '<button type="submit" name="add-to-cart" value="' . esc_attr( $product->get_id() ) . '" class="single_add_to_cart_button button alt">' . esc_html( $product->single_add_to_cart_text() ) . '</button>';

		do_action( 'woocommerce_after_add_to_cart_button' );

		echo '</form>';
	}

	private function get_memberships_capability_product_id( $action, $target ) {
		if ( 'purchase' !== $action && ! ( is_array( $target ) && isset( $target['product'] ) ) ) {
			return 0;
		}

		if ( is_array( $target ) ) {
			if ( ! empty( $target['product'] ) ) {
				return absint( $target['product'] );
			}

			if ( ! empty( $target['post'] ) && 'purchase' === $action ) {
				return absint( $target['post'] );
			}
		}

		return 0;
	}

	private function is_eligible_for_cart_based_access( $product ) {
		$product_id = is_a( $product, 'WC_Product' ) ? $product->get_id() : absint( $product );
		if ( isset( $this->eligible_cache[ $product_id ] ) ) {
			return $this->eligible_cache[ $product_id ];
		}

		$is_restricted = $this->is_memberships_purchase_restricted( $product_id );
		$has_rule      = $this->product_has_matching_rule( $product_id );
		$cart_matches  = $this->cart_contains_qualifying_product( $product_id );

		$this->eligible_cache[ $product_id ] = $is_restricted && $has_rule && $cart_matches;
		$this->debug_log( 'Eligibility checked.', array( 'product_id' => $product_id, 'restricted' => $is_restricted, 'has_rule' => $has_rule, 'cart_matches' => $cart_matches, 'eligible' => $this->eligible_cache[ $product_id ] ) );
		return $this->eligible_cache[ $product_id ];
	}

	private function is_memberships_purchase_restricted( $product_id ) {
		if ( ! function_exists( 'wc_memberships_is_product_purchasing_restricted' ) ) {
			return false;
		}

		foreach ( $this->get_product_and_parent_ids( $product_id ) as $candidate_id ) {
			if ( wc_memberships_is_product_purchasing_restricted( $candidate_id ) ) {
				return true;
			}
		}

		return false;
	}

	private function memberships_allows_purchase( $product_id ) {
		if ( ! function_exists( 'wc_memberships_user_can' ) ) {
			return false;
		}

		return (bool) wc_memberships_user_can(
			get_current_user_id(),
			'purchase',
			array( 'product' => absint( $product_id ) )
		);
	}

	private function product_has_matching_rule( $product_id ) {
		return ! empty( $this->get_matching_rules( $product_id ) );
	}

	private function cart_contains_qualifying_product( $product_id ) {
		if ( ! WC()->cart ) {
			return false;
		}

		$cart_product_ids = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$cart_product_ids[] = $this->get_cart_item_product_id( $cart_item );
			if ( ! empty( $cart_item['variation_id'] ) ) {
				$cart_product_ids[] = absint( $cart_item['variation_id'] );
			}
		}

		foreach ( $this->get_matching_rules( $product_id ) as $rule ) {
			if ( array_intersect( $rule['product_ids'], $cart_product_ids ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_cart_item_product_id( $cart_item ) {
		return ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
	}

	private function get_message_for_product( $product_id ) {
		foreach ( $this->get_matching_rules( $product_id ) as $rule ) {
			if ( ! empty( $rule['message'] ) ) {
				return $rule['message'];
			}
		}

		return __( 'This add-on requires a qualifying membership product in your cart.', 'kitmage-addon-manager' );
	}

	private function get_matching_rules( $product_id ) {
		$matches = array();
		foreach ( $this->get_rules() as $rule ) {
			foreach ( $this->get_product_and_parent_ids( $product_id ) as $candidate_id ) {
				if ( has_term( $rule['tag_id'], 'product_tag', $candidate_id ) ) {
					$matches[] = $rule;
					break;
				}
			}
		}

		return $matches;
	}

	/**
	 * Gets the product ID and, for a variation, its parent product ID.
	 *
	 * Product tags and purchase restrictions are normally assigned to the
	 * variable parent rather than to each individual variation.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return int[]
	 */
	private function get_product_and_parent_ids( $product_id ) {
		$product_id = absint( $product_id );
		$ids        = $product_id ? array( $product_id ) : array();
		$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if ( is_a( $product, 'WC_Product_Variation' ) && $product->get_parent_id() ) {
			$ids[] = $product->get_parent_id();
		}

		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}


	private function debug_log( $message, $context = array() ) {
		if ( 'yes' !== get_option( self::DEBUG_OPTION, 'no' ) ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message . ' ' . wp_json_encode( $context ), array( 'source' => 'kitmage-addon-manager' ) );
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Kitmage Add-on Manager: ' . $message . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private function get_rules() {
		$rules = get_option( self::OPTION_NAME, array() );
		return is_array( $rules ) ? $rules : array();
	}

	private function sanitize_rules( $rules ) {
		$sanitized = array();
		foreach ( $rules as $rule ) {
			$tag_id      = isset( $rule['tag_id'] ) ? absint( $rule['tag_id'] ) : 0;
			$product_ids = isset( $rule['product_ids'] ) ? array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $rule['product_ids'] ) ) ) : array();
			$message     = isset( $rule['message'] ) ? wp_kses_post( $rule['message'] ) : '';

			if ( $tag_id && $product_ids ) {
				$sanitized[] = array(
					'tag_id'      => $tag_id,
					'product_ids' => array_values( array_unique( $product_ids ) ),
					'message'     => $message,
				);
			}
		}

		return $sanitized;
	}
}

add_action( 'plugins_loaded', array( 'Kitmage_Addon_Manager', 'instance' ) );
