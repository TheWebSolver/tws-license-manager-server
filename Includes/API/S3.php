<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server Amazon S3 Handler.
 *
 * @package TheWebSolver\License_Manager\Server\API
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

namespace TheWebSolver\License_Manager\API;

use Aws\S3\S3Client;
use TheWebSolver\License_Manager\Server;
use LicenseManagerForWooCommerce\AdminMenus;
use TheWebSolver\License_Manager\Single_Instance;

/**
 * Amazon S3 Storage Class.
 */
class S3 {
	use Single_Instance;

	/**
	 * Options key.
	 *
	 * @var string
	 */
	const OPTION = 'tws_license_manager_s3_config';

	/**
	 * Default options value.
	 *
	 * @var array
	 */
	private $defaults = array(
		's3_region'  => '',
		's3_version' => '',
		's3_key'     => '',
		's3_secret'  => '',
		's3_bucket'  => '',
	);

	/**
	 * Sets up Amazon S3.
	 *
	 * @return S3
	 */
	public function instance() {
		$options = wp_parse_args( (array) get_option( self::OPTION, array() ), $this->defaults );

		add_action( 'admin_init', array( $this, 'add_page_section' ) );

		return $this;
	}

	/**
	 * Gets S3 Client instance.
	 *
	 * @return S3Client
	 */
	public function get() {
		$config = get_option( Server::PREFIX, array() );

		if ( empty( $config ) ) {
			return null;
		}

		return new S3Client( $config );
	}

	/**
	 * Adds Amazon S3 page.
	 *
	 * @return void
	 */
	public function add_page_section() {
		Server::init()->container
		->add_section(
			self::OPTION,
			array(
				'tab_title' => __( 'Storage', 'tws-license-manager-server' ),
				'title'     => __( 'Amazon S3 configuration', 'tws-license-manager-server' ),
				'desc'      => sprintf(
					'%1$s %2$s',
					__( 'This plugin uses Amazon PHP SDK v3 for connecting with Amazon S3. Learn more about it here:', 'tws-license-manager-server' ),
					'<a href="https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-presigned-url.html" target="_blank">Amazon S3 Pre-Signed URL with AWS SDK for PHP Version 3
					</a>'
				),
			)
		)
		->add_field(
			's3_region',
			self::OPTION,
			array(
				'label'             => __( 'Amazon S3 Region', 'tws-license-manager-server' ),
				'desc'              => __( 'Enter your AWS Region selected for S3.', 'tws-license-manager-server' ),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 5,
			)
		)
		->add_field(
			's3_version',
			self::OPTION,
			array(
				'label'             => __( 'Amazon S3 Version', 'tws-license-manager-server' ),
				'desc'              => __( 'Enter your Amazon S3 Version.', 'tws-license-manager-server' ),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 10,
			)
		)
		->add_field(
			's3_bucket',
			self::OPTION,
			array(
				'label'             => __( 'Amazon S3 Bucket', 'tws-license-manager-server' ),
				'desc'              => __( 'Enter your Amazon S3 Bucket Name. This will be your global bucket name. If you only use one bucket for all your products, then set it here and no need to enter it for each product. If you manage separate buckets, then enter them in product edit page.', 'tws-license-manager-server' ),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 15,
			)
		)
		->add_field(
			's3_key',
			self::OPTION,
			array(
				'label'             => __( 'Amazon S3 Key', 'tws-license-manager-server' ),
				'desc'              => __( 'Enter your AWS IAM User programmable API Key.', 'tws-license-manager-server' ),
				'type'              => 'password',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat hz_password_control',
				'priority'          => 20,
			)
		)
		->add_field(
			's3_secret',
			self::OPTION,
			array(
				'label'             => __( 'Amazon S3 Secret', 'tws-license-manager-server' ),
				'desc'              => __( 'Enter your AWS IAM User programmable API Secret.', 'tws-license-manager-server' ),
				'type'              => 'password',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat hz_password_control',
				'priority'          => 25,
			)
		);
	}
}
