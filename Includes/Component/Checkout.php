<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server WooCommerce Checkout handler.
 *
 * @package TheWebSolver\License_Manager\Server\WooCommerce
 *
 * -----------------------------------
 * DEVELOPED-MAINTAINED-SUPPPORTED BY
 * -----------------------------------
 * ███║     ███╗   ████████████████
 * ███║     ███║   ═════════██████╗
 * ███║     ███║        ╔══█████═╝
 *  ████████████║      ╚═█████
 * ███║═════███║      █████╗
 * ███║     ███║    █████═╝
 * ███║     ███║   ████████████████╗
 * ╚═╝      ╚═╝    ═══════════════╝
 */

namespace TheWebSolver\License_Manager\Component;

use TheWebSolver\License_Manager\Options_Interface;
use TheWebSolver\License_Manager\Server;
use TheWebSolver\License_Manager\Single_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TheWebSolver\License_Manager\Components\Checkout class.
 *
 * Handles WooCommerce checkout.
 */
class Checkout implements Options_Interface {
	use Single_Instance;

	/**
	 * Control get checkout behaviour.
	 *
	 * @var bool
	 */
	private $disable_guest = true;

	/**
	 * Control existing license field showing up at checkout.
	 *
	 * @var bool
	 */
	private $check_license = true;

	/**
	 * Limit to only allow one qty of the product to be bought in a single order.
	 *
	 * @var bool
	 */
	private $limit_item = true;

	/**
	 * Redirect to checkout after add to cart.
	 *
	 * @var bool
	 */
	private $redirect = true;

	/**
	 * The options section priority.
	 *
	 * @var int
	 */
	private $option_priority = 10;

	/**
	 * The options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * The checkout license input field key.
	 *
	 * @var string
	 */
	const META_KEY = 'tws_license_manager_user_license';

	/**
	 * Options key.
	 *
	 * @var string
	 */
	const OPTION = 'tws_license_manager_checkout_config';

	/**
	 * Parent order meta key to save for the current checkout order.
	 *
	 * @var string
	 */
	const PARENT_ORDER_KEY = 'tws_license_manager_parent_order';

	/**
	 * Renewal license key passsed from client as URL parameter.
	 *
	 * @var string
	 */
	const CLIENT_LICENSE = 'tws_license_manager_client_license';

	/**
	 * Default options value.
	 *
	 * @var array
	 */
	private $defaults = array(
		'disable_guest'          => 'on',
		'license_field'          => 'on',
		'license_field_position' => 'checkout_billing',
		'limit_item'             => 'on',
		'redirect_add_to_cart'   => 'on',
	);

	/**
	 * Sets up WooCommerce checkout.
	 *
	 * @return Checkout
	 */
	public function instance() {
		$options                = wp_parse_args( (array) get_option( self::OPTION, array() ), $this->defaults );
		$this->options          = $options;
		$this->disable_guest    = isset( $options['disable_guest'] ) && 'on' === $options['disable_guest'] ? true : false;
		$this->check_license    = isset( $options['license_field'] ) && 'on' === $options['license_field'] ? true : false;
		$this->section_position = isset( $options['license_field_position'] ) && ! empty( $options['license_field_position'] ) ? $options['license_field_position'] : 'billing';
		$this->limit_item       = isset( $options['limit_item'] ) && 'on' === $options['limit_item'] ? true : false;
		$this->redirect         = isset( $options['redirect_add_to_cart'] ) && 'on' === $options['redirect_add_to_cart'] ? true : false;

		$this->init_hooks();

		return $this;
	}

	/**
	 * Sets section priority.
	 *
	 * @param int $priority The `admin_init` hook priority.
	 *
	 * @inheritDoc
	 */
	public function set_section_priority( int $priority ) {
		$this->option_priority = $priority;

		return $this;
	}

	/**
	 * Adds Checkout options.
	 *
	 * @inheritDoc
	 */
	public function add_page_section() {
		add_action( 'admin_init', array( $this, 'add_section' ), $this->option_priority );
	}

	/**
	 * Gets saved options.
	 *
	 * @inheritDoc
	 */
	public function get_option() {
		return $this->options;
	}

	/**
	 * Inits WooCommerce Checkout hooks.
	 */
	private function init_hooks() {
		// Save the renewal license key for 10 minutes when link clicked after license expiry on client.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['tws_license_key'] ) ) {
			setcookie( self::CLIENT_LICENSE, sanitize_text_field( wp_unslash( $_REQUEST['tws_license_key'] ) ), time() + ( MINUTE_IN_SECONDS * 10 ) );
		}
		// phpcs:enable

		add_filter( 'woocommerce_get_script_data', array( $this, 'filter_script' ), 10, 2 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'require_new_account' ), -1 );
		add_action( 'woocommerce_checkout_fields', array( $this, 'manage_fields' ), 10 );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'restore_account' ), 999 );
		add_action( 'woocommerce_before_checkout_process', array( $this, 'process' ), 10 );

		if ( $this->check_license ) {
			add_action( "woocommerce_{$this->section_position}", array( $this, 'add_license_field' ) );
		}

		if ( $this->limit_item ) {
			add_filter( 'woocommerce_is_sold_individually', '__return_true', 9999 );
			add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'set_url' ), 10, 2 );
		}

		if ( $this->redirect ) {
			add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'added_to_cart_redirect' ) );

			// Remove added to cart message showing up at checkout after redirection.
			add_filter( 'wc_add_to_cart_message_html', function( $message ) { return ''; } ); //phpcs:ignore
		}
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 10, 2 );
	}

	/**
	 * Validates checkout form fields.
	 *
	 * @param array     $data   The checkout fields data.
	 * @param \WP_Error $errors The error handler.
	 */
	public function validate( $data, $errors ) {
		$checkout_email = isset( $data['billing_email'] ) ? $data['billing_email'] : '';
		$license_key    = isset( $_POST[ self::META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// phpcs:disable -- Test codes to check all checkout fields posted data key/value.
		// $errors->add( 'post', maybe_serialize( $_POST ) );
		// foreach ( $data as $key => $value ) {
		// 	$errors->add( 'data_key', $key );
		// 	$errors->add( 'data_value', $value );
		// }
		// phpcs:enable

		// License key field is empty, $data => valid.
		if ( ! $license_key ) {
			return;
		}

		// License key set but user not logged in, $data => error.
		if ( ! is_user_logged_in() ) {
			/**
			 * WPHOOK: Filter -> message if user not logged in but license key is entered.
			 *
			 * @param string $message The error message to display after "Place Order" btn is clicked.
			 * @param string $context The context where message triggers. Possible values are:
			 * * `string` `not_logged_in`   - When user is not logged in.
			 * * `string` `order_count`     - When order has more than one product.
			 * * `string` `invalid_license` - License key is invalid.
			 * @var   string
			 */
			$message = apply_filters(
				'hzfex_license_manager_server_checkout_license_check_error',
				__( 'Please login first to validate your license key.', 'tws-license-manager-server' ),
				'not_logged_in'
			);

			$errors->add( 'unauthenticated_user', $message );

			return;
		}

		// Get license object from the field input.
		$license = lmfwc_get_license( $license_key );

		if ( $license ) {
			$user_id = $license->getUserId();
			$user    = get_user_by( 'id', $user_id );

			// License for more than one item in cart can't be verified, $data => error.
			if ( 1 < WC()->cart->get_cart_contents_count() ) {
				/**
				 * WPHOOK: Filter -> message if order has more than one product.
				 *
				 * @param string $message The error message to display after "Place Order" btn is clicked.
				 * @param string $context The context where message triggers.
				 * @var   string
				 */
				$message = apply_filters(
					'hzfex_license_manager_server_checkout_license_check_error',
					__( 'License key can not be verified when order has more than one product. Please order only the product previously purchased for which license key was generated. Else, leave the license key field blank and get new license for all products in order.', 'tws-license-manager-server' ),
					'order_count'
				);

				$errors->add( 'cart_contents_count_exceed', $message );

				return;
			}

			$items   = WC()->cart->get_cart_contents();
			$items   = (array) array_shift( $items );
			$product = isset( $items['product_id'] ) ? (int) $items['product_id'] : 0;

			// Valid license of the logged in user, $data => valid.
			if (
				( $user instanceof \WP_User )
				&& ( $user->user_email === $checkout_email )
				&& $product
				&& $product === $license->getProductId()
			) {
				// Set transient for making relation between new and old order.
				set_transient(
					sha1( $license_key ),
					array(
						'order_id'   => $license->getOrderId(),
						'license_id' => $license->getId(),
					)
				);

				return;
			}
		}

		/**
		 * WPHOOK: Filter -> message if not a valid license key.
		 *
		 * @param string $message The error message to display after "Place Order" btn is clicked.
		 * @param string $context The context where message triggers.
		 * @var   string
		 */
		$message = apply_filters(
			'hzfex_license_manager_server_checkout_license_check_error',
			__( 'license key does not exist or not yours.', 'tws-license-manager-server' ),
			'invalid_license'
		);

		// License and user still not valid, $data => error.
		$errors->add( 'invalid_license', sprintf( '%1$s - %2$s', '<b>' . $license_key . '</b>', $message ) );
	}

	/**
	 * Saves checkout fields as order meta.
	 *
	 * @param \WC_Order $order The order being created at checkout.
	 * @param array     $data  The checkout fields data.
	 */
	public function save_order_meta( $order, $data ) {
		// License key field empty, no further processing.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::META_KEY ] ) || empty( $_POST[ self::META_KEY ] ) ) {
			return;
		}

		$license_key = sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$transient = get_transient( sha1( $license_key ) );
		$parent_id = isset( $transient['order_id'] ) ? (int) $transient['order_id'] : 0;

		if ( $parent_id ) {
			$parent_order = wc_get_order( $parent_id );

			if ( $parent_order instanceof \WC_Order ) {
				// Old order with this license is parent of this order.
				$order->update_meta_data( self::PARENT_ORDER_KEY, $parent_id );

				// Hack lmfwc order meta to prevent generating license for this order.
				$order->update_meta_data( 'lmfwc_order_complete', 1 );
			}
		}
	}

	/**
	 * Redirects to checkout page after added to cart.
	 *
	 * @return string
	 */
	public function added_to_cart_redirect() {
		return wc_get_checkout_url();
	}

	/**
	 * Sets checkout URL for add to cart button on product page if it is already added to cart.
	 *
	 * @param string      $url     The add to cart URL.
	 * @param \WC_Product $product The current product.
	 *
	 * @return string
	 */
	public function set_url( $url, $product ) {
		if (
			WC()->cart->find_product_in_cart( WC()->cart->generate_cart_id( $product->get_id() ) )
			&& $product->is_purchasable()
			&& $product->is_in_stock()
		) {
			$url = wc_get_checkout_url();
		}

		return $url;
	}

	/**
	 * Also make sure the guest checkout option value passed to the woocommerce.js forces registration.
	 * Otherwise the registration form is hidden by woocommerce.js.
	 *
	 * @param array|false $params WooCommerce default params passed to woocommerce.js.
	 * @param string      $handle Script handle the data will be attached to (deprecated in WC 3.3+).
	 *
	 * @return array
	 */
	public function filter_script( $params, $handle ) {
		if (
			$this->disable_guest &&
			! is_user_logged_in() &&
			is_array( $params ) &&
			isset( $params['option_guest_checkout'] ) &&
			'yes' === (string) $params['option_guest_checkout'] ) {
			$params['option_guest_checkout'] = 'no';
		}

		return $params;
	}

	/**
	 * Makes sure account username and password fields as required "*".
	 *
	 * @param array $fields The checkout fields.
	 */
	public function manage_fields( $fields ) {
		if ( ! is_user_logged_in() && $this->disable_guest ) {
			$account_fields = array( 'account_username', 'account_password', 'account_password-2' );

			foreach ( $account_fields as $account_field ) {
				if ( isset( $fields['account'][ $account_field ] ) ) {
					$fields['account'][ $account_field ]['required'] = true;
				}
			}
		}

		return $fields;
	}

	/**
	 * Force enables new account creation.
	 *
	 * @param \WC_Checkout $checkout The current checkout instance.
	 */
	public function require_new_account( $checkout ) {
		// Nothing to do if user is already logged in or guest checkout is disabled.
		if ( is_user_logged_in() || ! $checkout->enable_guest_checkout || ! $this->disable_guest ) {
			return;
		}

		$checkout->enable_guest_checkout = false;
		$checkout->must_create_account   = true;
	}

	/**
	 * Sets create account fields as required forcefully.
	 */
	public function process() {
		if ( ! is_user_logged_in() && $this->disable_guest ) {
			$_POST['createaccount'] = 1;
		}
	}

	/**
	 * Restore checkout form.
	 *
	 * @param \WC_Checkout $checkout The current checkout instance.
	 */
	public function restore_account( $checkout ) {
		if ( ! $this->disable_guest ) {
			// Don't disable guest checkout.
			$checkout->enable_guest_checkout = true;

			// Don't force creating account.
			if ( ! is_user_logged_in() ) {
				$checkout->must_create_account = false;
			}
		}
	}

	/**
	 * Adds section to WooCommerce checkout form.
	 *
	 * @param \WC_Checkout $checkout The current checkout instance.
	 */
	public function add_license_field( $checkout ) {
		$license_key = isset( $_COOKIE[ self::CLIENT_LICENSE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CLIENT_LICENSE ] ) ) : '';

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ self::META_KEY ] )
		? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) )
		: $license_key;
		// phpcs:enable

		/**
		 * WPHOOK: Filter -> change checkout license key field details.
		 *
		 * @param string[] $field The field args.
		 * @var   string[]
		 */
		$field = apply_filters(
			'hzfex_license_manager_server_checkout_license_key_field',
			array(
				'heading'     => __( 'License Details', 'tws-license-manager-server' ),
				'label'       => __( 'Existing License Key (optional)', 'tws-license-manager-server' ),
				'placeholder' => 'XXXX-XXXX-XXXX-XXXX',
				'desc'        => __( 'Enter the existing license key purchased with the product in the order, if any. The existing license will be renewed with a new expiry date and no new license will be generated with this order.', 'tws-license-manager-server' ),
			)
		);
		?>
		<div id="hz_checkout_license_field">
			<?php if ( isset( $field['heading'] ) ) : ?>
				<h3 class="license_details"><?php echo esc_html( $field['heading'] ); ?></h3>
			<?php endif; ?>
			<p class="form-row " id="license_field">
				<label for="license"><?php echo esc_html( $field['label'] ); ?></label>
				<span class="woocommerce-input-wrapper">
					<input type="text" class="input-text" name="<?php echo esc_attr( self::META_KEY ); ?>" id="license" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" value="<?php echo esc_attr( $value ); ?>">
				</span>
				<?php if ( isset( $field['desc'] ) ) : ?>
					<span class="desc"><small><em><?php echo esc_html( $field['desc'] ); ?></em></small></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Adds admin options section for WC checkout.
	 *
	 * @inheritDoc
	 */
	public function add_section() {
		/**
		 * WPHOOK: Filter -> placement options for license key field.
		 *
		 * @param string[] $options The placement options with action hook as key, label as value.
		 *                          This is for future compatibility if or when hook tagname changes.
		 *                          NOTE: The hook key must ignore `woocommerce_` prefix.
		 * @var   string[]
		 */
		$placement_options = apply_filters(
			'hzfex_license_manager_server_checkout_option_license_field_position',
			array(
				'checkout_billing'                     => __( 'Before Billing Address', 'tws-license-manager-server' ),
				'checkout_shipping'                    => __( 'After Billing Address', 'tws-license-manager-server' ),
				'before_order_notes'                   => __( 'Before Order Notes', 'tws-license-manager-server' ),
				'after_order_notes'                    => __( 'After Order Notes', 'tws-license-manager-server' ),
				'checkout_order_review'                => __( 'Before Order Review', 'tws-license-manager-server' ),
				'review_order_before_payment'          => __( 'Before Order Payment Details', 'tws-license-manager-server' ),
				'checkout_before_terms_and_conditions' => __( 'After Order Payment Details', 'tws-license-manager-server' ),
				'review_order_before_submit'           => __( 'Before Order Submit Button', 'tws-license-manager-server' ),
			)
		);

		Server::load()->container
		->add_section(
			self::OPTION,
			array(
				'tab_title' => __( 'Checkout', 'tws-license-manager-server' ),
				'title'     => __( 'WooCommerce Checkout Configuration', 'tws-license-manager-server' ),
				'desc'      => __( 'Setup checkout page for handling orders, checkout fields, and license validation.', 'tws-license-manager-server' ),
			)
		)
		->add_field(
			'disable_guest',
			self::OPTION,
			array(
				'label'             => __( 'Disable Guest Checkout', 'tws-license-manager-server' ),
				'desc'              => __( 'Override default WooCommerce setting and force disable guest checkout so only registered users can purchase the product that generates license after order completion.', 'tws-license-manager-server' ),
				'type'              => 'checkbox',
				'sanitize_callback' => 'sanitize_key',
				'class'             => 'widefat hz_switcher_control',
				'priority'          => 5,
				'default'           => $this->defaults['disable_guest'],
			)
		)
		->add_field(
			'license_field',
			self::OPTION,
			array(
				'label'             => __( 'Add License Field', 'tws-license-manager-server' ),
				'desc'              => __( 'Create a new section on the checkout page with an input field to enter the previously purchased license key. The license key entered in this checkout field will be validated with the product in the current order. If validation succeeds, the same license key will be renewed with a new expiry date instead of generating a new license.', 'tws-license-manager-server' ),
				'type'              => 'checkbox',
				'sanitize_callback' => 'sanitize_key',
				'class'             => 'widefat hz_switcher_control',
				'priority'          => 10,
				'default'           => $this->defaults['license_field'],
			)
		)
		->add_field(
			'license_field_position',
			self::OPTION,
			array(
				'label'    => __( 'License Field Position', 'tws-license-manager-server' ),
				'desc'     => __( 'Select the placement of the license field created by the above option.', 'tws-license-manager-server' ),
				'type'     => 'select',
				'class'    => 'widefat hz_select_control',
				'priority' => 15,
				'options'  => $placement_options,
				'default'  => $this->defaults['license_field_position'],
			)
		)
		->add_field(
			'limit_item',
			self::OPTION,
			array(
				'label'             => __( 'Limit Product Quantity', 'tws-license-manager-server' ),
				'desc'              => __( 'Only allow purchasing a single quantity of product on each order. This is to prevent generating multiple licenses by the number of quantity the product is purchased on a single order.', 'tws-license-manager-server' ),
				'type'              => 'checkbox',
				'sanitize_callback' => 'sanitize_key',
				'class'             => 'widefat hz_switcher_control',
				'priority'          => 20,
				'default'           => $this->defaults['limit_item'],
			)
		)
		->add_field(
			'redirect_add_to_cart',
			self::OPTION,
			array(
				'label'             => __( 'Redirect to Checkout', 'tws-license-manager-server' ),
				'desc'              => __( 'Redirect users to the checkout page after the "Add to cart" button is clicked on a single product page.', 'tws-license-manager-server' ),
				'type'              => 'checkbox',
				'sanitize_callback' => 'sanitize_key',
				'class'             => 'widefat hz_switcher_control',
				'priority'          => 25,
				'default'           => $this->defaults['redirect_add_to_cart'],
			)
		);
	}
}
