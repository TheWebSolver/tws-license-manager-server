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

use LicenseManagerForWooCommerce\AdminMenus;
use TheWebSolver\Core\Setting\Component\Container;
use TheWebSolver\Core\Setting\Plugin;
use TheWebSolver\License_Manager\API\Manager;
use TheWebSolver\License_Manager\API\S3;
use TheWebSolver\License_Manager\Components\Checkout;
use TheWebSolver\License_Manager\Components\Order;
use TheWebSolver\License_Manager\Components\Product;

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
		$this->container = new Container( self::PREFIX, AdminMenus::LICENSES_PAGE );
		$this->manager   = Manager::load();
		$this->s3        = S3::load();
		$this->product   = Product::load();
		$this->checkout  = Checkout::load();
		$this->order     = Order::load();

		$this->init_instances();

		add_action( 'after_setup_theme', array( $this, 'add_admin_page' ) );

		add_filter( 'hzfex_license_manager_server_pre_response_dispatch', array( $this, 'dispatch_product_details' ) );
	}

	/**
	 * Adds options page sections and fields to the container.
	 */
	private function init_instances() {
		$this->manager->instance()->set_section_priority( 10 )->add_section();
		$this->s3->instance()->set_section_priority( 15 )->add_section();
		$this->checkout->instance()->set_section_priority( 20 )->add_section();
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
	public function dispatch_product_details( array $data ) {
		$id   = isset( $data['productId'] ) ? $data['productId'] : 0;
		$meta = $this->product->get_data( $id );

		// Add meta as response data.
		$data['meta'] = $meta;

		return $data;
	}
}
