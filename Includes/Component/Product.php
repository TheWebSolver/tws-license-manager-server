<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server WooCommerce Product handler.
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

use TheWebSolver\License_Manager\Server;
use TheWebSolver\License_Manager\Single_Instance;

/**
 * TheWebSolver\License_Manager\Components\Product class.
 *
 * Handles WooCommerce Product.
 */
class Product {
	use Single_Instance;

	/**
	 * Amazon S3 fields in data tabs.
	 *
	 * @var bool
	 */
	private $s3_data_fields = false;

	/**
	 * Product meta suffix.
	 *
	 * @var string
	 */
	private $meta_suffix = '_product_details';

	/**
	 * Product meta key for using stock for license.
	 *
	 * @var string
	 */
	const STOCK_META = 'lmfwc_licensed_product_use_stock';

	/**
	 * Product meta key to check if generator was used or not.
	 *
	 * @var string
	 */
	const USE_GENERATOR_META = 'lmfwc_licensed_product_use_generator';

	/**
	 * Product meta key for getting the assigned license generator ID.
	 *
	 * @var string
	 */
	const ASSIGNED_GENERATOR_META = 'lmfwc_licensed_product_assigned_generator';

	/**
	 * Product meta key for generating number of licenses.
	 *
	 * @var string
	 */
	const QUANTITY_META = 'lmfwc_licensed_product_delivered_quantity';

	/**
	 * Sets up WooCommerce product.
	 *
	 * @return Product
	 */
	public function instance() {
		// WooCommerce hooks for adding product meta.
		add_action( 'add_meta_boxes_product', array( $this, 'add_product_server_information' ) );

		// WooCommerce hooks for updating product meta.
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'process_product_data' ), 10, 1 );

		// Alternate way to process S3 bucket and file names.
		if ( $this->s3_data_fields ) {
			add_action( 'woocommerce_product_options_downloads', array( $this, 'add_s3_storage_details' ) );
			add_action( 'woocommerce_variation_options_download', array( $this, 'add_variation_s3_storage_details' ), 10, 3 );
			add_action( 'woocommerce_admin_process_variation_object', array( $this, 'process_variable_product_data' ), 10, 2 );
		}

		return $this;
	}

	/**
	 * Enables Amazon S3 storage bucket and filename fields.
	 *
	 * These fields will be displayed in product data tab
	 * and inside the download section when `download` is checked.
	 *
	 * @param bool $enable Whether to enable s3 data field or not.
	 */
	public function enable_s3_data_fields( bool $enable ) {
		$this->s3_data_fields = $enable;
	}

	/**
	 * Gets the product data.
	 *
	 * @param int  $id       The product ID for which license was issued.
	 * @param bool $dispatch Whether data is being sent as response.
	 *
	 * @return array
	 */
	public function get_data( int $id, $dispatch = true ) {
		$meta = get_post_meta( $id, Server::PREFIX . $this->meta_suffix, true );
		$meta = is_array( $meta ) ? $meta[ Server::PREFIX ] : array();
		$data = array( 'id' => $id );
		$keys = array_keys( $this->get_meta_fields() );

		// Do not send Amazon S3 details back to client.
		if ( $dispatch ) {
			if ( isset( $meta['_product_bucket'] ) ) {
				unset( $meta['_product_bucket'] );
			}

			if ( isset( $meta['_product_filename'] ) ) {
				unset( $meta['_product_filename'] );
			}
		}

		foreach ( $keys as $id ) {
			// Ignore the separators.
			if ( $this->is_separator( $id ) ) {
				continue;
			}

			// Ignore fields not saved.
			if ( ! isset( $meta[ $id ] ) || empty( $meta[ $id ] ) ) {
				continue;
			}

			// Make key simpler. Trim out prefix.
			$key = str_replace( '_product_', '', $id );

			// Convert logo (icons) and cover (banner) to an array.
			if ( 'logo' === $key || 'cover' === $key ) {
				$data[ $key ] = Server::load()->manager->make_thing_array( $meta[ $id ] );

				continue;
			}

			$data[ $key ] = $meta[ $id ];
		}

		/**
		 * WPHOOK: Filter -> change default updates meta.
		 *
		 * @param array $data The saved product meta data. Possible array keys are:
		 * * `string`   `version`        - The latest product version.
		 * * `string`   `wp_tested`      - The latest WordPress version product supports.
		 * * `string`   `wp_requires`    - The minimum WordPress version product needs.
		 * * `string`   `last_updated`   - The last update date for the product.
		 * * `string[]` `logo`           - `1x` (128x128px) version as a single array item.
		 *                                 File extensions for icons can be jpg, png or gif.
		 *                                 Multiple versions can be in an array.
		 *                                 Possible multiple array keys are:
		 *                                 ** `svg` (just svg url to the image),
		 *                                 ** `1x` (url to 128x128px size image),
		 *                                 ** `2x` (url to 256x256px size image).
		 * * `string[]` `cover`          - `1x` (772x250px) version as a single array item.
		 *                                 File extensions for banners can be jpg, png.
		 *                                 Multiple versions can be in an array.
		 *                                 Possible array keys are:
		 *                                 ** `1x` (url to 772x250px size image),
		 *                                 ** `2x` (url to 1544x500px size image).
		 * * `string`   `bucket`         - S3 bucket name where this product is saved.
		 * * `string`   `filename`       - S3 filename inside bucket for this product (.zip).
		 * @param int   $id   The current product ID.
		 * @var   array
		 */
		$product_meta = apply_filters( 'hzfex_license_manager_pre_product_meta_dispatch', $data, $id );

		return $product_meta;
	}

	/**
	 * Creates product metabox fields.
	 *
	 * @return array
	 */
	public function get_meta_fields() {
		$fields = array(
			'_product_separator'    => array(
				'title' => __( 'Product Details', 'tws-license-manager-server' ),
			),
			'_product_version'      => array(
				'label' => __( 'Version', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
			'_product_wp_tested'    => array(
				'label' => __( 'Tested upto WordPress Version', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
			'_product_wp_requires'  => array(
				'label' => __( 'Requires WordPress Version', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
			'_product_last_updated' => array(
				'label' => __( 'Last updated Date (YYYY-MM-DD)', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
			'_product_logo'         => array(
				'label' => __( 'Logo (128x128px)', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
			'_product_cover'        => array(
				'label' => __( 'Cover Photo (772x250 px)', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
			'_storage_separator'    => array(
				'title'    => __( 'Storage Details', 'tws-license-manager-server' ),
				'subtitle' => __( 'Leave below fields empty if you don\'t use Amazon S3 Storage for your product downloads.', 'tws-license-manager-server' ),
			),
			'_product_bucket'       => array(
				'label' => __( 'Amazon S3 Bucket', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
			'_product_filename'     => array(
				'label' => __( 'Amazon S3 File Name', 'tws-license-manager-server' ),
				'type'  => 'text',
			),
		);

		return $fields;
	}

	/**
	 * Adds product metabox.
	 *
	 * @param \WP_Post $post The current post/product instance.
	 */
	public function add_product_server_information( \WP_Post $post ) {
		add_meta_box(
			Server::PREFIX . 'product_details_metabox',
			__( 'Product Server Information', 'tws-license-manager-server' ),
			array( $this, 'render_product_fields' ),
			'product',
			'side',
			'high'
		);
	}

	/**
	 * Saves simple product type.
	 *
	 * @param \WC_Product $product The product.
	 */
	public function process_product_data( \WC_Product $product ) {
		$data   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fields = array_keys( $this->get_s3_fields() );

		// Process the input data and save.
		$product_details = $this->process_product_input_fields( $data );
		$product->update_meta_data( Server::PREFIX . $this->meta_suffix, $product_details );

		// If S3 data fields are enabled, process them too.
		if ( $this->s3_data_fields ) {
			foreach ( $fields as $id ) {
				$key   = Server::PREFIX . $id;
				$value = isset( $data[ $key ] ) && ! empty( $data[ $key ] )
				? sanitize_text_field( $data[ $key ] )
				: '';

				$product->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * Saves variable product type metadata.
	 *
	 * @param \WC_Product_Variation $variation Variation object.
	 * @param int                   $position  The current variation position in loop.
	 */
	public function process_variable_product_data( \WC_Product_Variation $variation, int $position ) {
		$data   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fields = array_keys( $this->get_s3_fields() );

		// Iterate over all fields and save values to post variation meta.
		foreach ( $fields as $id ) {
			$key = Server::PREFIX . $id;

			$value = isset( $data[ $key ][ $position ] ) && ! empty( $data[ $key ][ $position ] )
			? sanitize_text_field( $data[ $key ][ $position ] )
			: '';

			$variation->update_meta_data( $key, $value );
		}
	}

	/**
	 * Renders fields inside metabox.
	 *
	 * @param \WP_Post $post The current post/product instance.
	 */
	public function render_product_fields( \WP_Post $post ) {
		$meta = get_post_meta( $post->ID, Server::PREFIX . $this->meta_suffix, true );

		// Prepare meta as an array value.
		if ( ! is_array( $meta ) ) {
			$meta = array();
			foreach ( $this->get_meta_fields() as $id => $args ) {
				// Ignore the separators.
				if ( $this->is_separator( $id ) ) {
					continue;
				}

				$meta[ Server::PREFIX ][ $id ] = '';
			}
		}

		$this->create_html_fields( $meta );
	}

	/**
	 * Creates HTML input fields.
	 *
	 * @param array $meta The post meta.
	 */
	private function create_html_fields( array $meta ) {
		$fields = $this->get_meta_fields();

		foreach ( $fields as $id => $args ) {
			$meta_id     = Server::PREFIX . $id;
			$meta_name   = Server::PREFIX . '[' . $id . ']';
			$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';

			// Add the separators as title.
			if ( $this->is_separator( $id ) ) {
				echo '<h4 class="' . esc_attr( $id ) . '">' . esc_html( $args['title'] ) . '</h4>';

				if ( isset( $args['subtitle'] ) ) {
					echo '<small>' . esc_html( $args['subtitle'] ) . '</small>';
				}

				continue;
			}

			echo '<p class="hz_' . esc_attr( $id ) . '_meta_field">';
			echo '<label for="' . esc_attr( $meta_id ) . '">' . esc_html( $args['label'] ) . '</label>';
			echo '<input class="widefat ' . esc_attr( $id ) . '" type="' . esc_attr( $args['type'] ) . '" id="' . esc_attr( $meta_id ) . '" name="' . esc_attr( $meta_name ) . '" value="' . esc_attr( $meta[ Server::PREFIX ][ $id ] ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
			echo '</p>';
		}
	}

	/**
	 * Processes product meta fields.
	 *
	 * @param array $data The posted data.
	 *
	 * @return array
	 */
	public function process_product_input_fields( $data ) {
		$fields = $this->get_meta_fields();
		$value  = array();

		foreach ( $fields as $id => $args ) {
			// Ignore the separators.
			if ( $this->is_separator( $id ) ) {
				continue;
			}

			// Make some validation by field type.
			switch ( $args['type'] ) {
				case 'text':
					$sanitize = 'sanitize_text_field';
					break;
				case 'number':
					$sanitize = 'intval';
					break;
				default:
					$sanitize = 'sanitize_text_field';
			}

			$value[ Server::PREFIX ][ $id ] = isset( $data[ Server::PREFIX ][ $id ] )
			? \call_user_func( $sanitize, $data[ Server::PREFIX ][ $id ] )
			: '';
		}

		return $value;
	}

	/**
	 * Gets the S3 Storage fields data.
	 *
	 * @return array
	 */
	public function get_s3_fields() {
		$fields = array(
			// S3 Bucket name text field.
			'_s3_bucket_name' => array(
				'label'         => __( 'S3 Bucket Name', 'tws-license-manager-server' ),
				'placeholder'   => __( 'Leave blank if use same bucket set in setting...', 'tws-license-manager-server' ),
				'desc_tip'      => true,
				'description'   => __( 'Add the bucket name where your premium plugin/theme is stored. Enter exactly the same what is set on Amazon S3.', 'tws-license-manager-server' ),
				'wrapper_class' => 'hz_s3_bucket_name',
			),

			// S3 plugin/theme name text field.
			'_s3_file_name'   => array(
				'label'         => __( 'S3 File Name', 'tws-license-manager-server' ),
				'placeholder'   => __( 'Leave blank if filename same as this product slug...', 'tws-license-manager-server' ),
				'desc_tip'      => true,
				'description'   => __( 'Add the plugin/theme name which will be downloaded by user. The ".zip" extension will be added automatically.', 'tws-license-manager-server' ),
				'wrapper_class' => 'hz_s3_file_name',
			),
		);

		return $fields;
	}

	/**
	 * Adds S3 storage bucket details.
	 */
	public function add_s3_storage_details() {
		$fields = $this->get_s3_fields();

		// Iterate over given fields and create text input field for downloadable product.
		foreach ( $fields as $id => $field ) {
			$field_id = Server::PREFIX . $id;

			$args = array( 'id' => $field_id );

			// Prepare final args for text input field.
			$s3_field = array_merge( $field, $args );

			woocommerce_wp_text_input( $s3_field );
		}
	}

	/**
	 * Adds S3 storage bucket details.
	 *
	 * @param int      $position       Position in the loop.
	 * @param array    $variation_data Variation data. (deprecated since WC 4.4).
	 * @param \WP_Post $variation      Current variation post object.
	 */
	public function add_variation_s3_storage_details( int $position, array $variation_data, \WP_Post $variation ) {
		$fields = $this->get_s3_fields();

		// Iterate over given fields and create text input field for downloadable variable product.
		foreach ( $fields as $id => $field ) {
			$meta_key   = Server::PREFIX . $id;
			$field_id   = "$meta_key{$position}";
			$field_name = $meta_key . '[' . $position . ']';
			$value      = get_post_meta( $variation->ID, $meta_key, true );

			// Add additional args for variable product.
			$args = array(
				'id'    => $field_id,
				'name'  => $field_name,
				'value' => $value,
			);

			// Prepare final args for text input field.
			$s3_field = array_merge( $args, $field );

			woocommerce_wp_text_input( $s3_field );
		}
	}

	/**
	 * Defines which meta key are treated as separators.
	 *
	 * @param string $id The meta ID.
	 *
	 * @return bool
	 */
	private function is_separator( string $id ) {
		return '_product_separator' === $id || '_storage_separator' === $id;
	}
}
