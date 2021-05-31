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
		// Cryptographic scerets must be defined in config, otherwise do not start anything.
		if ( is_wp_error( $this->secret_key() ) ) {
			add_action( 'admin_notices', array( $this, 'show_notice' ) );

			return;
		}

		Plugin::boot();
		$this->container = new Container( self::PREFIX, HZFEX_SETTING_MENU );
		$this->manager   = Manager::load();
		$this->s3        = S3::load();
		$this->product   = Product::load();
		$this->checkout  = Checkout::load();
		$this->order     = Order::load();

		$this->init_instances();

		add_action( 'after_setup_theme', array( $this, 'add_admin_page' ) );
		add_action( 'admin_notices', array( $this, 'show_notice' ) );

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
	 * Shows admin notice.
	 */
	public function show_notice() {
		$key = $this->secret_key();
		if ( is_wp_error( $key ) ) {
			echo '<div class="notice notice-error"><p>' . wp_kses_post( $key->get_error_message() ) . '</p></div>';

			return;
		}

		global $pagenow;

		if ( 'post-new.php' === $pagenow && 'product' === get_post_type() && current_user_can( 'manage_options' ) ) {
			$msg = __( 'The same crypto secret key defined by constant <b>LMFWC_PLUGIN_SECRET</b> must be used when initializing license manager client.', 'tws-liense-manager-server' );

			echo '<div class="notice notice-success"><p>' . wp_kses_post( $msg ) . '</p><p><b><em><code>TheWebSolver\License_Manager\API\Manager::hash_with(' . esc_html( LMFWC_PLUGIN_SECRET ) . ')</code></em></b></p></div>';
		}
	}

	/**
	 * Get the product secret key to be set on client.
	 *
	 * @return string|\WP_Error
	 */
	public function secret_key() {
		if ( defined( 'LMFWC_PLUGIN_SECRET' ) ) {
			return LMFWC_PLUGIN_SECRET;
		}

		$upload_dir = wp_upload_dir( null, false );
		$crypto_dir = $upload_dir['basedir'] . '/lmfwc-files/';
		$details    = __( 'get cryptographic secrets', 'tws-license-manager-server' );
		$setup      = __( 'setup constant with cryptographics secrets', 'tws-license-manager-server' );

		return new \WP_Error(
			'crypto_not_defined',
			sprintf(
				/* Translators: %1$s - The folder name where crypto files are saved, %2$s - defuse crypto content filename, %3$s - The contant name where defuse to be set, %4$s - secret crypto content filename, %5$s - The constant name where secret to be set, %6$s - The license manager link for crypto details, %7$s - The license manager link on how to set contant and it's value */
				__( 'Define cryptographic secrets on wp-config.php file to use the license manager server. The crypto files are located inside %1$s directory. Copy the contents of %2$s and set it as the value of constant %3$s. Also, copy the contents of %4$s and set it as the value of constant %5$s. <br>Learn more about how to %6$s and %7$s.', 'tws-license-manager-server' ),
				"<b>{$crypto_dir}</b>",
				'<b>defuse.txt</b>',
				'<b>LMFWC_PLUGIN_DEFUSE</b>',
				'<b>secret.txt</b>',
				'<b>LMFWC_PLUGIN_SECRET</b>',
				'<a href="https://www.licensemanager.at/docs/handbook/setup/cryptographic-secrets/" target="_blank">' . $details . '</a>',
				'<a href="https://www.licensemanager.at/docs/handbook/setup/security/" target="_blank">' . $setup . '</a>'
			)
		);
	}
}
