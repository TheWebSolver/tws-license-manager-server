<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server.
 *
 * @package TheWebSolver\License_Manager\Server
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

namespace TheWebSolver\License_Manager;

use TheWebSolver\Core\Setting\Component\Container;
use TheWebSolver\Core\Setting\Plugin;
use TheWebSolver\License_Manager\API\Manager;
use TheWebSolver\License_Manager\API\S3;
use TheWebSolver\License_Manager\Component\Checkout;
use TheWebSolver\License_Manager\Component\Order;
use TheWebSolver\License_Manager\Component\Product;
use LicenseManagerForWooCommerce\Models\Resources\License;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TheWebSolver\License_Manager\Server class.
 */
final class Server {
	use Single_Instance;

	/**
	 * TheWebSolver\License_Manager\API\Manager Instance.
	 *
	 * @var Manager
	 */
	public $manager;

	/**
	 * TheWebSolver\License_Manager\API\S3 Instance.
	 *
	 * @var S3
	 */
	public $s3;

	/**
	 * TheWebSolver\License_Manager\Components\Product instance.
	 *
	 * @var Product
	 */
	public $product;

	/**
	 * Plugin prefixer.
	 *
	 * @var string
	 */
	const PREFIX = 'tws_license_manager_server';

	/**
	 * Setting Container.
	 *
	 * @var Container
	 */
	public $container;

	/**
	 * TheWebSolver\License_Manager\Components\Checkout instance.
	 *
	 * @var Checkout
	 */
	public $checkout;

	/**
	 * TheWebSolver\License_Manager\Components\Order instance.
	 *
	 * @var Order
	 */
	public $order;

	/**
	 * Server instance.
	 */
	public function instance() {
		Plugin::boot();
		$this->container = new Container( self::PREFIX, HZFEX_SETTING_MENU );
		$this->manager   = Manager::load();
		$this->s3        = S3::load();
		$this->product   = Product::load();
		$this->checkout  = Checkout::load();
		$this->order     = Order::load();

		$this->init_instances();

		add_action( 'after_setup_theme', array( $this, 'add_admin_page' ) );

		add_filter( 'hzfex_license_manager_server_pre_response_dispatch', array( $this, 'dispatch_product_details' ) );
		add_filter( 'hzfex_license_manager_server_pre_response_validate', array( $this, 'validate_license' ), 10, 5 );
	}

	/**
	 * Adds options page sections and fields to the container.
	 */
	private function init_instances() {
		$this->manager->instance()->add_page_section( 10 )->process();
		$this->s3->instance()->add_page_section( 15 );
		$this->checkout->instance()->add_page_section( 20 );
		$this->product->instance();
		$this->order->instance();
	}

	/**
	 * Adds WordPress admin page.
	 */
	public function add_admin_page() {
		$this->container->set_page(
			array(
				'page_title' => __( 'Server Options', 'tws-license-manager-server' ),
				'menu_title' => __( 'Server Setup', 'tws-license-manager-server' ),
				'position'   => 99,
			),
		)
		->set_capability( 'manage_options' )
		->set_menu();
	}

	/**
	 * Sends product meta details along with response.
	 *
	 * @param array $data The response data.
	 *
	 * @return array The modified response data.
	 */
	public function dispatch_product_details( array $data ): array {
		$product_id   = isset( $data['productId'] ) ? $data['productId'] : 0;
		$product_data = $this->product->get_data( $product_id );

		if ( ! empty( $product_data ) ) {
			$data['product_meta'] = $product_data;
		}

		return $data;
	}

	/**
	 * Validates response data before sending back.
	 *
	 * @param array        $data       The response data.
	 * @param string       $key        The license meta key.
	 * @param array        $metadata   The saved metadata.
	 * @param array        $parameters The request query parameters.
	 * @param bool|License $license    The license.
	 *
	 * @return array
	 */
	public function validate_license( array $data, string $key, array $metadata, array $parameters, $license ): array {
		// License can't be generated from request parameters, $data => error.
		if ( ! $license ) {
			return array(
				'error' => __( 'License can not be verified.', 'tws-license-manager-server' ),
				'code'  => 400,
			);
		}

		// No license status meta saved, $data => error.
		if ( ! isset( $metadata['status'] ) ) {
			return array(
				'error' => __( 'License status can not be verified.', 'tws-license-manager-server' ),
				'code'  => 401,
			);
		}

		// The license state.
		$state = (string) $data['state'];

		// Make license expire when the time comes.
		if (
			'expired' === $state &&
			( ! isset( $metadata['expired'] ) || 'yes' !== $metadata['expired'] )
		) {
			$metadata['expired'] = 'yes';
			$this->manager->update_meta( $license->getId(), $key, $metadata );
		}

		$flag        = isset( $parameters['flag'] ) ? (string) $parameters['flag'] : '';
		$is_schedule = $flag && 'cron' === $flag;
		$is_update   = $flag && ( 'update_themes' === $flag || 'update_plugins' === $flag );
		$meta        = $this->product->get_data( $license->getProductId() );

		// Request is a scheduled (cron job) event, $data => valid.
		if ( $is_schedule ) {
			return $this->manager->send_scheduled_response( $license, $key, $metadata, $meta, $state );
		}

		// Request is not made for product updates, stop further processing and send data & meta.
		// This is the stage where it is assumed that validation is performed without any flag.
		// Nothing happens on server side. License data and product meta are sent back as response.
		if ( ! $is_update ) {
			$data['code'] = 200;
			$data['meta'] = $meta; // "meta" key instead of "product_meta" to prevent update trigger.

			return $data;
		}

		// License is not active, $data => error.
		if ( 'active' !== $metadata['status'] ) {
			$data['error'] = __( 'License is not active.', 'tws-license-manager-server' );
			$data['code']  = 402;

			return $data;
		}

		// License has expired, $data => error.
		if ( 'expired' === $state ) {
			$data['error'] = __( 'License has expired.', 'tws-license-manager-server' );
			$data['code']  = 403;

			return $data;
		}

		// Send product info along with response.
		$data['product_meta'] = $meta;

		// Initialize download package URL.
		$package = '';

		// Make Amazon S3 request and get presigned URL.
		if ( 'on' === $this->s3->get_option( 'use_amazon_s3' ) ) {
			$package = $this->s3->get_presigned_url_for( $license );

			if ( is_wp_error( $package ) ) {
				$data['error'] = $package->get_error_message();
				$data['code']  = $package->get_error_data();

				return $data;
			}
		}

		/**
		 * WPHOOK: Filter -> package URL to send as response data.
		 *
		 * Use this hook to hijack and modify package URL before sending as response.
		 * Best used in case where Amazon S3 is not used for storing the product zip file.
		 *
		 * @param string $package The package URL.
		 * @var   string
		 */
		$url = apply_filters( 'hzfex_license_manager_server_product_package_url', $package );

		// Set the package URL as response.
		$data['package'] = $url;

		return $data;
	}
}
