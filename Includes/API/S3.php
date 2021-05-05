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

/**
 * Amazon S3 Storage Class.
 */
class S3 {
	/**
	 * Parent slug for Amazon S3 Credentials.
	 *
	 * @var string
	 */
	private $parent_slug;

	/**
	 * Submenu hook suffix. Will only be set if parent slug is passed.
	 *
	 * @var false|string
	 */
	public $hook_suffix;

	/**
	 * Prefixer.
	 *
	 * @var string
	 */
	public $prefix = 'tws_license_manager_server_config';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public $page_slug = 'tws-license-manager-server-s3-config';

	/**
	 * Amazon S3 Constructor.
	 *
	 * @param string $parent_slug The main menu slug to hook S3 page to.
	 */
	public function __construct( string $parent_slug ) {
		$this->parent_slug = $parent_slug;

		if ( '' !== $this->parent_slug ) {
			add_action( 'admin_menu', array( $this, 'add_page' ), 999 );
		}

		add_action( 'admin_init', array( $this, 'start' ) );
	}

	/**
	 * Gets S3 Client instance.
	 *
	 * @return S3Client
	 */
	public function get() {
		$config = get_option( $this->prefix, array() );

		if ( empty( $config ) ) {
			return null;
		}

		return new S3Client( $config );
	}

	/**
	 * Starts handling page data.
	 */
	public function start() {
		// Bail early if not S3 page.
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['page'] ) || $this->page_slug !== $_GET['page'] ) {
			return;
		}

		// enqueue scripts here.

		if ( ! isset( $_POST['validate_form'] ) || 'validate_form' !== $_POST['validate_form'] ) {
			return;
		}
		// phpcs:enable

		if ( ! empty( $this->get_posted_values() ) ) {
			$posted_values = maybe_serialize( $this->get_posted_values() );

			update_option( $this->prefix, $posted_values );
		}
	}

	/**
	 * Gets posted fields data.
	 *
	 * @return array
	 */
	public function get_posted_values() {
		$to_save = array();
		$data    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( isset( $data[ $this->prefix ]['s3_region'] ) ) {
			$to_save[] = sanitize_text_field( $data[ $this->prefix ]['s3_region'] );
		}
		if ( isset( $data[ $this->prefix ]['s3_version'] ) ) {
			$to_save[] = sanitize_text_field( $data[ $this->prefix ]['s3_version'] );
		}
		if ( isset( $data[ $this->prefix ]['s3_key'] ) ) {
			$to_save[] = sanitize_text_field( $data[ $this->prefix ]['s3_key'] );
		}
		if ( isset( $data[ $this->prefix ]['s3_secret'] ) ) {
			$to_save[] = sanitize_text_field( $data[ $this->prefix ]['s3_secret'] );
		}

		return $to_save;
	}

	/**
	 * Adds Amazon S3 page.
	 *
	 * @return void
	 */
	public function add_page() {
		$this->hook_suffix = add_submenu_page(
			$this->parent_slug,
			__( 'Amazon S3 Options', 'tws-license-manager-server' ),
			__( 'Amazon S3 Options', 'tws-license-manager-server' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'generate_form' )
		);
	}

	/**
	 * Generates Amazon S3 configuration form.
	 *
	 * Use this to hook form to different menu/submenu.
	 */
	public function generate_form() {
		$this->show_license_form();
	}

	/**
	 * Shows Amazon S3 Storage configuration form.
	 */
	protected function show_license_form() {
		?>
		<div id="hz_amazon_s3_form">
			<div class="hz_amazon_s3_form_head">
				<div id="logo"><img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) ); ?>/Assets/logo.png"></div>
				<h2 id="tagline"><?php esc_html_e( 'The Web Solver License Manager Client', 'tws-license-manager-client' ); ?></h2>
			</div>
			<div class="hz_amazon_s3_form_content">
				<form method="POST">
					<fieldset id="hz_amazon_3_setup">
						<label for="<?php echo esc_attr( $this->prefix ); ?>[s3_region]">
							<?php esc_html_e( 'Amazon S3 Region', 'tws-license-manager-server' ); ?>
							<input type="text" id="<?php echo esc_attr( $this->prefix ); ?>[s3_region]" name="<?php echo esc_attr( $this->prefix ); ?>[s3_region]" value="">
						</label>
						<label for="<?php echo esc_attr( $this->prefix ); ?>[s3_version]">
							<?php esc_html_e( 'Amazon S3 Version', 'tws-license-manager-server' ); ?>
							<input type="text" id="<?php echo esc_attr( $this->prefix ); ?>[s3_version]" name="<?php echo esc_attr( $this->prefix ); ?>[s3_version]" value="">
						</label>
						<label for="<?php echo esc_attr( $this->prefix ); ?>[s3_key]">
							<?php esc_html_e( 'Amazon S3 Key', 'tws-license-manager-server' ); ?>
							<input type="text" id="<?php echo esc_attr( $this->prefix ); ?>[s3_key]" name="<?php echo esc_attr( $this->prefix ); ?>[s3_key]" value="">
						</label>
						<label for="<?php echo esc_attr( $this->prefix ); ?>[s3_secret]">
							<?php esc_html_e( 'Amazon S3 Secret', 'tws-license-manager-server' ); ?>
							<input type="text" id="<?php echo esc_attr( $this->prefix ); ?>[s3_secret]" name="<?php echo esc_attr( $this->prefix ); ?>[s3_secret]" value="">
						</label>
					</fieldset>
					<fieldset id="hz_amazon_s3_actions">
						<input type="submit" class="hz_btn__prim" value="<?php esc_attr_e( 'Save Changes', 'tws-license-manager-server' ); ?>">
						<input type="hidden" name="validate_form" value="validate_form">
					</fieldset>
				</form>
			</div>
		</div>
		<?php
	}
}
