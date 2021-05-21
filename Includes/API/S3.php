<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server Amazon S3 Handler.
 *
 * @package TheWebSolver\License_Manager\Server\AWS
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
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use LicenseManagerForWooCommerce\Models\Resources\License;
use TheWebSolver\License_Manager\Server;
use TheWebSolver\License_Manager\Options_Interface;
use TheWebSolver\License_Manager\Single_Instance;
use TheWebSolver\License_Manager\Options_Handler;
use WP_Error;

/**
 * TheWebSolver\License_Manager\API\S3 class.
 *
 * Handles Amazon S3 SDK.
 */
class S3 implements Options_Interface {
	use Single_Instance, Options_Handler;

	/**
	 * Options key.
	 *
	 * @var string
	 */
	const OPTION = 'tws_license_manager_s3_config';

	/**
	 * Sets up Amazon S3.
	 *
	 * @return S3
	 */
	public function instance() {
		$this->defaults = array(
			'use_amazon_s3'     => 'on',
			's3_region'         => '',
			's3_version'        => 'latest',
			's3_key'            => '',
			's3_secret'         => '',
			's3_bucket'         => '',
			's3_url_expiration' => '10',
		);
		$this->options  = wp_parse_args( get_option( self::OPTION, array() ), $this->defaults );

		return $this;
	}

	/**
	 * Gets S3 Credentials.
	 *
	 * @return Credentials|false False if option not set.
	 */
	private function get_credentials() {
		if ( empty( $this->options['s3_key'] || $this->options['s3_secret'] ) ) {
			return false;
		}

		return new Credentials( $this->options['s3_key'], $this->options['s3_secret'] );
	}

	/**
	 * Gets S3 Client Configuration.
	 *
	 * @return array|WP_Error Options not set properly, WP_Error happens.
	 */
	private function get_config() {
		// Prepare config with Amazon S3 version.
		$config      = array( 'version' => 'latest' );
		$credentials = $this->get_credentials();

		if ( empty( $this->options['s3_region'] ) ) {
			return new WP_Error(
				'amazon_s3_region_not_set',
				__( 'Amazon S3 Region is not set.', 'tws-license-manager-server' ),
				404
			);
		}

		// Set Amazon S3 region from option.
		$config['region'] = $this->options['s3_region'];

		if ( false === $credentials ) {
			return new WP_Error(
				'amazon_s3_credentials_not_set',
				__( 'Amazon S3 Credentials are not set.', 'tws-license-manager-server' ),
				405
			);
		}

		// Set Amazon S3 version from option.
		if ( ! empty( $this->options['s3_version'] ) ) {
			$config['version'] = $this->options['s3_version'];
		}

		// Set Amazon S3 credentials from option.
		$config['credentials'] = $credentials;

		return $config;
	}

	/**
	 * Creates presigned URL for the package as download.
	 *
	 * @param License $license The current license instance.
	 * @link https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-presigned-url.html
	 */
	public function get_presigned_url_for( License $license ) {
		$config = $this->get_config();
		$meta   = Server::load()->product->get_data( $license->getProductId(), false );
		$bucket = isset( $meta['bucket'] ) ? (string) $meta['bucket'] : $this->options['s3_bucket'];

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( ! isset( $meta['filename'] ) || empty( $meta['filename'] ) ) {
			return new WP_Error(
				'amazon_s3_key_not_set',
				__( 'Amazon s3 Key/Product filename not set.', 'tws-license-manager-server' ),
				406
			);
		}

		try {
			$client  = new S3Client( $config );
			$cmd     = $client->getCommand(
				'GetObject',
				array(
					'Bucket' => $bucket,
					'Key'    => $meta['filename'],
				)
			);
			$request = $client->createPresignedRequest( $cmd, "+{$this->options['s3_url_expiration']} Minutes" );
		} catch ( S3Exception $e ) {
			return new WP_Error( 'amazon_s3_exception', $e->getAwsErrorMessage(), $e->getAwsErrorCode() );
		} catch ( AwsException $e ) {
			return new WP_Error( 'amazon_aws_exception', $e->getAwsErrorMessage(), $e->getAwsErrorCode() );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'amazon_s3_args_invalid', $e->getMessage(), $e->getCode() );
		}

		return (string) $request->getUri();
	}

	/**
	 * Adds options section to container.
	 *
	 * @param int $priority admin_init hook priority.
	 *
	 * @inheritDoc
	 */
	public function add_page_section( int $priority ) {
		add_action( 'admin_init', array( $this, 'add_section' ), $priority );
	}

	/**
	 * Adds Amazon S3 page.
	 *
	 * @inheritDoc
	 */
	public function add_section() {
		Server::load()->container
		->add_section(
			self::OPTION,
			array(
				'tab_title' => __( 'Storage', 'tws-license-manager-server' ),
				'title'     => __( 'Amazon S3 Configuration', 'tws-license-manager-server' ),
				'desc'      => sprintf(
					'%1$s %2$s',
					__( 'This plugin uses Amazon PHP SDK v3 for connecting with Amazon S3. Learn more about it here:', 'tws-license-manager-server' ),
					'<a href="https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-presigned-url.html" target="_blank">Amazon S3 Pre-Signed URL with AWS SDK for PHP Version 3
					</a>'
				),
			)
		)
		->add_field(
			'use_amazon_s3',
			self::OPTION,
			array(
				'label'             => __( 'Use Amazon S3', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice alert">%2$s</span>',
					__( 'Use Amazon S3 as storage for your premium plugins/themes and handle the plugin/theme update URL for the client.', 'tws-license-manager-server' ),
					__( 'All options set below will be ignored when this is turned off.', 'tws-license-manager-server' ),
				),
				'type'              => 'checkbox',
				'class'             => 'hz_switcher_control',
				'sanitize_callback' => 'sanitize_key',
				'priority'          => 1,
				'default'           => $this->defaults['use_amazon_s3'],
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
				'placeholder'       => 'ap-south-1',
			)
		)
		->add_field(
			's3_version',
			self::OPTION,
			array(
				'label'             => __( 'Amazon S3 Version', 'tws-license-manager-server' ),
				'desc'              => __( 'Enter your Amazon S3 Version. Defaults to "latest"', 'tws-license-manager-server' ),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 10,
				'placeholder'       => $this->defaults['s3_version'],
				'default'           => $this->defaults['s3_version'],
			)
		)
		->add_field(
			's3_bucket',
			self::OPTION,
			array(
				'label'             => __( 'Amazon S3 Bucket', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice success">%2$s</span>',
					__( 'Enter your Amazon S3 Bucket Name. It will be your global bucket name.', 'tws-license-manager-server' ),
					__( 'If you only use one bucket for all your products, then set it here, and no need to set it for each product. If you manage separate buckets, then set them on the product level.', 'tws-license-manager-server' )
				),
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
		)
		->add_field(
			's3_url_expiration',
			self::OPTION,
			array(
				'label'             => __( 'URL Expiration Time', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice alert">%2$s <a href="%3$s" target="_blank">%4$s</a></span>',
					__( 'Enter the number of minutes after which the Pre-signed URL to the product package will expire. Expired links will no longer provide access to the package.', 'tws-license-manager-server' ),
					__( 'The expiration link must be less than a week. i.e. "10080"', 'tws-license-manager-server' ),
					'https://docs.aws.amazon.com/general/latest/gr/signature-version-4.html',
					__( 'Learn more about it.', 'tws-license-manager-server' )
				),
				'type'              => 'number',
				'sanitize_callback' => 'absint',
				'class'             => 'widefat',
				'priority'          => 30,
				'placeholder'       => $this->defaults['s3_url_expiration'],
				'default'           => $this->defaults['s3_url_expiration'],
				'min'               => 1,
				'max'               => 10080,
				'step'              => 1,
			)
		);
	}
}
