<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server Manager.
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

use LicenseManagerForWooCommerce\Models\Resources\License;
use TheWebSolver\License_Manager\Options_Interface;
use TheWebSolver\License_Manager\Server;
use TheWebSolver\License_Manager\Single_Instance;
use TheWebSolver\License_Manager\Options_Handler;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TheWebSolver\License_Manager\API\Manager class.
 *
 * Handles license server request validation and response modification.
 */
final class Manager implements Options_Interface {
	use Single_Instance, Options_Handler;

	/**
	 * API Validation data.
	 *
	 * @var array
	 */
	private $validation_data = array();

	/**
	 * REST API route.
	 *
	 * @var string
	 */
	private $route;

	/**
	 * Whether server is getting request for debugging purposes.
	 *
	 * @var bool
	 */
	private $debug = false;

	/**
	 * Options key.
	 *
	 * @var string
	 */
	const OPTION = 'tws_license_manager_server_basic_config';

	/**
	 * Sets up server manager.
	 *
	 * @return Manager
	 */
	public function instance() {
		$this->defaults = array(
			'debug_mode'                => 'off',
			'debug_endpoint'            => 'off',
			'license_validate_response' => 'License not found.',
			'email_validate_response'   => 'Email address not found.',
			'order_validate_response'   => 'Order not found.',
			'name_validate_response'    => 'Product not found.',
		);
		$options        = wp_parse_args( get_option( self::OPTION, array() ), $this->defaults );
		$base           = '/lmfwc/v2/';
		$endpoint       = 'licenses';

		foreach ( $this->defaults as $key => $field ) {
			// Set debug option and continue.
			if ( 'debug_mode' === $key ) {
				$this->debug = 'on' === $options[ $key ] ? true : false;

				continue;
			}

			// Set endpoint option and continue.
			if ( 'debug_endpoint' === $key ) {
				$endpoint = $this->debug && 'on' === $options[ $key ] ? 'generators' : 'licenses';

				continue;
			}

			if ( ! empty( $options[ $key ] ) ) {
				$this->validation_data[ $key ] = $options[ $key ];
			}
		}

		// Set properties.
		$this->options = $options;
		$this->route   = $base . $endpoint;

		return $this;
	}

	/**
	 * Process client request and send response back.
	 */
	public function process() {
		$this->validate();
		$this->update();
		$this->expired();
	}

	/**
	 * Validates API on server.
	 */
	private function validate() {
		add_filter( 'lmfwc_rest_api_validation', array( $this, 'validate_request' ), 10, 3 );
	}

	/**
	 * Updates license status and metadata.
	 */
	private function update() {
		// Modify response and perform additional tasks.
		add_filter( 'lmfwc_rest_api_pre_response', array( $this, 'parse_response' ), 10, 3 );
	}

	/**
	 * Handles license expiration response.
	 *
	 * @todo Add hook to `LicenseManagerForWooCommerce\API\v2\Licenses::hasLicenseExpired`.
	 *       Hook tag: `lmfwc_rest_license_pre_send_expired_response` with `$license` as arg.
	 *       Add it before both return statements (catch Exception & expired license).
	 * @filesource license-manager-for-woocommerce\includes\api\v2\Licenses.php line `886`.
	 */
	private function expired() {
		add_action( 'lmfwc_rest_license_pre_send_expired_response', array( $this, 'license_expired' ) );
	}

	/**
	 * Validates REST API request on server before sending response.
	 *
	 * @param mixed            $result  Response to replace the requested version with.
	 *                                  Can be anything a normal endpoint can return,
	 *                                  or null to not hijack the request.
	 * @param \WP_Rest_Server  $server  Server instance.
	 * @param \WP_Rest_Request $request Request used to generate the response.
	 *
	 * @link https://licensemanager.at/docs/tutorials-how-to/additional-rest-api-validation/
	 * @link https://developer.wordpress.org/reference/hooks/rest_pre_dispatch/
	 */
	public function validate_request( $result, $server, $request ) {
		$route      = $this->route;
		$parameters = $request->get_params();
		$valid_form = array_key_exists( 'form_state', $parameters );

		// Get request headers for validation.
		$authorize  = $request->get_header_as_array( 'authorization' )[0];
		$from       = $request->get_header_as_array( 'from' );
		$user_email = is_array( $from ) ? (string) $from[0] : '';
		$client     = $request->get_header_as_array( 'referer' );
		$client_url = is_array( $client ) ? (string) $client[0] : '';
		$authorize  = explode( ' ', $authorize );
		$auth_type  = isset( $authorize[0] ) ? $authorize[0] : '';
		$auth_val   = isset( $authorize[1] ) ? $authorize[1] : '';

		// phpcs:disable -- Error code testing OK. Uncomment it to get requested data.
		// return new \WP_Error(
		// 	'test_request',
		// 	'test request message',
		// 	array(
		// 		'route'         => $route,
		// 		'request_route' => $request->get_route(),
		// 		'parameters'    => $parameters,
		// 		'from'          => $user_email,
		// 		'referer'       => $client_url,
		// 		'authorize'     => $authorize,
		// 	)
		// );
		// phpcs:enable

		/**
		 * When debug mode if off:
		 * - License shouldn't already have been activated/deactivated for same site.
		 * - Route must be for license activation/deactivation/validation.
		 * - Request can only be made from license form (or "validate_license" method).
		 * - Endpoint (activate/deactivate) will be generated from the client license form.
		 * - Final possible routes for validation are:
		 * -- /lmfwc/v2/licenses/activate/
		 * -- /lmfwc/v2/licenses/deactivate/
		 * -- /lmfwc/v2/licenses/validate/ (from "validate_license" method)
		 */
		if ( ! $this->debug ) {
			// Request made without activate/deactivate/validate endpoint, $request => WP_Error.
			if ( ! $valid_form ) {
				return $this->request_error( __( 'Server debug mode is off. Only activation/deactivation/validation is possible at this time.', 'tws-license-manager-server' ), 401, $parameters );
			}

			// Prepare final route with endpoint from the client request.
			$route = "{$this->route}/{$parameters['form_state']}/";

			// Client site license route did not match, $request => WP_Error.
			if ( strpos( $request->get_route(), $route ) !== 0 ) {
				$msg  = __( 'The request route did not match for further processing.', 'tws-license-manager-server' );
				$data = array(
					'request_route' => $request->get_route(),
					'remote_route'  => $route,
					'parameters'    => $parameters,
				);

				return $this->request_error( $msg, 401, $data );
			}

			// Request is not being sent from license form (except validation), $request => WP_Error.
			if (
				( 'validate' !== $parameters['form_state'] ) &&
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				( 'TWS' !== $auth_type || 'validate_license' !== base64_decode( $auth_val ) )
			) {
				return $this->request_error( __( 'Request was made outside of license form.', 'tws-license-manager-server' ), 401 );
			}
		}

		// Extract license key from the API path and get license object.
		$endpoint = explode( $route, $request->get_route() );
		$license  = ( isset( $endpoint[1] ) && ! empty( $endpoint[1] ) )
		? lmfwc_get_license( $endpoint[1] )
		: false;

		$error_data = array(
			'request_route' => $request->get_route(),
			'remote_route'  => $route,
		);

		// No license key in debug mode, $request => valid, WP_Error otherwise.
		if ( ! $license ) {
			$msg = isset( $this->validation_data['license_validate_response'] )
			? $this->validation_data['license_validate_response']
			: $this->defaults['license_validate_response'];

			return $this->debug ? true : $this->request_error( $msg, 404, $error_data );
		}

		$meta_key     = 'data-' . self::parse_url( $client_url );
		$metadata     = lmfwc_get_license_meta( $license->getId(), $meta_key, true );
		$metadata     = is_array( $metadata ) ? $metadata : array();
		$saved_email  = isset( $metadata['email'] ) ? (string) $metadata['email'] : '';
		$saved_url    = isset( $metadata['url'] ) ? (string) $metadata['url'] : '';
		$saved_status = isset( $metadata['status'] ) ? (string) $metadata['status'] : '';
		$purchased_on = $license->getCreatedAt();
		$has_expired  = isset( $metadata['expired'] ) ? (string) $metadata['expired'] : '';

		// Check for validation request before proceeding further.
		if (
			( 'validate' === $parameters['form_state'] ) &&
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			( 'TWS' !== $auth_type || "{$meta_key}:{$purchased_on}" !== base64_decode( $auth_val ) )
		) {
			return $this->request_error(
				__( 'You are not authorized for making license validation.', 'tws-license-manager-server' ),
				401,
				$error_data
			);
		}

		/**
		 * This event occurs only after cron or WordPress update system has already
		 * been triggered once on client and then license activation form is used.
		 *
		 * After cron or update system triggered, the client will have the
		 * matching license status as with the server. Then, when a user
		 * attempt to activate the license, this event will be triggered.
		 *
		 * In a nutshell, activate form won't trigger this event unless
		 * license metadata is expired and client status is also expired.
		 */
		if ( 'yes' === $has_expired ) {
			// License still expired, stop processing further.
			if ( is_wp_error( $this->is_license_valid( $license ) ) ) {
				// Let the scheduled request pass.
				if (
					'validate' === $parameters['form_state'] &&
					isset( $parameters['flag'] ) &&
					'cron' === $parameters['flag']
				) {
					return true;
				}

				// Expired license can't be activated.
				return $this->request_error(
					__( 'Renew your license before attempting to activate again.', 'tws-license-manager-server' ),
					400
				);
			}

			/*
			|-------------------------------------------------|
			|- If we reached here, license has been renewed. -|
			|-------------------------------------------------|
			*/

			// Lets stop there. Can't deactivate just renewed license.
			if ( 'deactivate' === $parameters['form_state'] ) {
				return $this->request_error( __( 'Please activate your license first after renewal.', 'tws-license-manager-server' ), 400 );
			}

			// Create newly renewed license metadata.
			$renewed_metadata = array();

			if ( $user_email ) {
				$renewed_metadata['email'] = $user_email;
			}

			$renewed_metadata['url']    = $client_url;
			$renewed_metadata['status'] = $saved_status;

			// Check if license was active when it expired.
			if ( 'active' === $saved_status ) {
				$renewed_metadata['status'] = 'inactive';

				// Hack saved status so new activation is possible after renewal.
				$saved_status = 'inactive';

				// Decrease active count by 1 so new activation doesn't count for total activation.
				lmfwc_update_license(
					$license->getDecryptedLicenseKey(),
					array( 'times_activated' => intval( $license->getTimesActivated() ) - 1 )
				);
			}

			// Finally, clear the expired flag.
			$renewed_metadata['expired'] = 'no';

			$this->update_meta( $license->getId(), $meta_key, $renewed_metadata );
		}

		// Same client, active status and from activate license form, $request => WP_Error.
		$active = ( $client_url === $saved_url ) && ( 'active' === $saved_status ) && ( 'activate' === $parameters['form_state'] );

		// Same client, inactive status and from deactivate license form, $request => WP_Error.
		$deactive = ( $client_url === $saved_url ) && ( 'inactive' === $saved_status ) && ( 'deactivate' === $parameters['form_state'] );

		// If email validation set, check that also.
		if ( $user_email ) {
			$active     = $active && ( $user_email === $saved_email );
			$deactive   = $deactive && ( $user_email === $saved_email );
			$parameters = array_merge( $parameters, array( 'email' => $user_email ) );
		}

		// Client manager already implements whether license is already active/inactive.
		// No remote request made if license has already been activated/deactivated.
		// This is an extra measure on server not to let bypass same request again for same client.
		if ( $active || $deactive ) {
			$msg = sprintf(
				/* Translators: %s - activate/deactivate state. */
				__( 'The license for this site has already been %sd.', 'tws-license-manager-server' ),
				"{$parameters['form_state']}"
			);
			return $this->request_error( $msg, 400 );
		}

		return $this->is_valid_request( $license, $parameters, $client_url );
	}

	/**
	 * Validates request license and parameters.
	 *
	 * @param License $license    The product license object.
	 * @param array   $parameters The client request parameters.
	 * @param string  $client_url The URL of client site from where request is generated.
	 *
	 * @return true|\WP_Error True if everything is validated, WP_Error otherwise.
	 */
	private function is_valid_request( License $license, array $parameters, string $client_url ) {
		$metadata = array();

		// Product slug didn't match with WooCommerce Product Title, $request => WP_Error.
		if ( array_key_exists( 'slug', $parameters ) ) {
			$product = wc_get_product( $license->getProductId() );
			$msg     = isset( $this->validation_data['name_validate_response'] )
			? $this->validation_data['name_validate_response']
			: $this->defaults['name_validate_response'];

			$error = $this->request_error( $msg, 404 );

			if (
				! ( $product instanceof \WC_Product ) ||
				$parameters['slug'] !== $product->get_slug()
			) {
				return $error;
			}
		}

		// Order ID didn't match with WooCommerce order ID, $request => WP_Error.
		if ( array_key_exists( 'order_id', $parameters ) ) {
			$order = wc_get_order( $license->getOrderId() );
			$msg   = isset( $this->validation_data['order_validate_response'] )
			? $this->validation_data['order_validate_response']
			: $this->defaults['order_validate_response'];

			$error = $this->request_error( $msg, 404 );

			if (
				! ( $order instanceof \WC_Order ) ||
				absint( $parameters['order_id'] ) !== $order->get_id()
			) {
				return $error;
			}
		}

		// Email address didn't match with WordPress user email, $request => WP_Error.
		if ( array_key_exists( 'email', $parameters ) ) {
			$user = get_userdata( $license->getUserId() );
			$msg  = isset( $this->validation_data['email_validate_response'] )
			? $this->validation_data['email_validate_response']
			: $this->defaults['email_validate_response'];

			$error = $this->request_error( $msg, 404 );

			if (
				! ( $user instanceof \WP_User ) ||
				$parameters['email'] !== $user->user_email
			) {
				return $error;
			}
			// Save email address as license meta.
			$metadata['email'] = $parameters['email'];
		}

		/**
		 * WPHOOK: Action -> Fires after parameters validation.
		 *
		 * Any other validation besides above default can be hooked with this action
		 * and validation check can be performed as required.
		 *
		 * @param License $license    The license object.
		 * @param array   $parameters The request parameters.
		 */
		do_action( 'hzfex_license_manager_server_request_validation', $license, $parameters );

		// Prepare meta key and value to save.
		$transient       = sha1( $license->getDecryptedLicenseKey() );
		$meta_key        = 'data-' . self::parse_url( $client_url );
		$metadata['key'] = $meta_key;
		$metadata['url'] = $client_url;

		// Save metadata for 5 minutes to catch with response, then delete.
		set_transient( $transient, $metadata, MINUTE_IN_SECONDS * 5 );

		return true;
	}

	/**
	 * Handles response.
	 *
	 * @param string $method The request method.
	 * @param string $route  The request route name.
	 * @param array  $data   The response data.
	 *
	 * @return array The modified response data.
	 *
	 * @link https://www.licensemanager.at/docs/tutorials-how-to/modifying-the-rest-api-response/
	 */
	public function parse_response( $method, $route, $data ) {
		// Bail early if is in debug mode.
		if ( $this->debug ) {
			return $data;
		}

		// Query parameters not found from request, send $data without doing anything else.
		if ( ! isset( $_SERVER['QUERY_STRING'] ) ) {
			return $data;
		}

		// Get all request parameters.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		parse_str( wp_unslash( $_SERVER['QUERY_STRING'] ), $parameters );

		// Get activate/deactivate/validate form state.
		$form_state = isset( $parameters['form_state'] ) ? (string) $parameters['form_state'] : '';
		$endpoint   = "v2/licenses/{$form_state}/{license_key}";

		// Handle validate license for that endpoint and stop processing further.
		if ( 'v2/licenses/validate/{license_key}' === $endpoint ) {
			return $this->send_validate_response( $data, $parameters );
		}

		// No activate/deactivate happening from the client license form, stop further processing.
		if ( $endpoint !== $route ) {
			$data['error'] = __( 'Something went wrong. Please contact plugin support.', 'tws-license-manager-server' );

			return $data;
		}

		// Possible status of a license: SOLD(1), DELIVERED(2), ACTIVE(3), & INACTIVE(4).
		// Since, we are saving as license meta, it will be 'active' or 'inactive'.
		$status_text = 'deactivate' === $form_state ? 'inactive' : 'active';
		$license_key = $data['licenseKey'];
		$license     = lmfwc_get_license( $license_key );
		$transient   = sha1( $license->getDecryptedLicenseKey() );
		$metadata    = get_transient( $transient );
		$metadata    = is_array( $metadata ) ? $metadata : array();
		$meta_key    = '';

		// Send license status text as response data with state key.
		$data['state'] = $status_text;

		// Clear meta key from metadata.
		if ( isset( $metadata['key'] ) ) {
			$meta_key = (string) $metadata['key'];
			unset( $metadata['key'] );
		}

		// Send meta key as response data.
		$data['key'] = $meta_key;

		// Set active status as meta value.
		$metadata['status'] = $status_text;

		/**
		 * WPHOOK: Filter -> License meta value.
		 *
		 * @param array   $license_meta Meta value to save to database.
		 * @param License $license      Current license object.
		 * @param string  $form_state   Current client form state. Can be `activate` or `deactivate`.
		 * @var   array
		 */
		$license_meta = apply_filters(
			'hzfex_license_manager_server_response_license_meta',
			$metadata,
			$license,
			$form_state,
		);

		// Check if key is present.
		if ( $meta_key ) {
			$this->update_meta( $license->getId(), $meta_key, $license_meta );
		}

		// Clear transient.
		delete_transient( $transient );

		return Server::load()->product_details( $data, $license, $meta_key, $license_meta );
	}

	/**
	 * Sends response data for validate endpoint.
	 *
	 * @param array $data       The response data.
	 * @param array $parameters The request query parameters.
	 *
	 * @return array The modified response data.
	 */
	private function send_validate_response( array $data, array $parameters ): array {
		//phpcs:ignore -- Get server request URI OK.
		$uri = (string) $_SERVER['REQUEST_URI'];

		// Trim everything before the license key.
		$from_key = str_replace( '/wp-json/lmfwc/v2/licenses/validate/', '', $uri );

		// Trim the URI after license key (where query starts).
		$key      = substr( $from_key, 0, strpos( $from_key, '?' ) );
		$license  = lmfwc_get_license( $key );
		$metadata = array();

		// Add error messages to response data if license has expired and update the the meta.
		if ( $license ) {
			$meta     = $this->get_metadata( $license, true );
			$meta_key = $meta['key'];
			$metadata = $meta['value'];

			// Send state also.
			$data['state'] = isset( $metadata['status'] ) ? $metadata['status'] : '';

			if ( is_wp_error( $this->is_license_valid( $license ) ) ) {
				$data['state']      = 'expired';
				$data['expired_on'] = $license->getExpiresAt();
			}
		}

		/**
		 * WPHOOK: Filter -> Response data before sending back.
		 *
		 * Validation endpoint should be handled differently.
		 *
		 * @param array  $data      The response data.
		 * @param string $meta_key  The meta key to save license metadata.
		 * @param array  $metadata  The existing metadata.
		 * @param array $parameters The request query parameters.
		 */
		return apply_filters( 'hzfex_license_manager_server_pre_response_validate', $data, $meta_key, $metadata, $parameters, $license );
	}

	/**
	 * Sends resposne.
	 *
	 * @param License $license      The license object.
	 * @param string  $key          The license meta key.
	 * @param array   $metadata     The license meta value.
	 * @param array   $product_meta The licensed product metadata.
	 * @param string  $state        The license latest state.
	 *
	 * @return array
	 */
	public function send_response( License $license, string $key, array $metadata, array $product_meta, string $state ): array {
		$data = array(
			'key'          => $key,
			'status'       => $state,
			'order_id'     => $license->getOrderId(),
			'expires_at'   => $license->getExpiresAt(),
			'product_id'   => $license->getProductId(),
			'active_count' => $license->getTimesActivated(),
			'total_count'  => $license->getTimesActivatedMax(),
			'license_key'  => $license->getDecryptedLicenseKey(),
			'purchased_on' => $license->getCreatedAt(),
			'product_meta' => $product_meta,
		);

		if ( isset( $metadata['email'] ) ) {
			$data['email'] = $metadata['email'];
		}

		/**
		 * WPHOOK: Filter -> before sending the response back.
		 *
		 * @param array  $data      The response data.
		 * @param License $license  The license object.
		 * @param array   $metadata The license metadata.
		 * @var   array
		 */
		$response = apply_filters( 'hzfex_license_manager_server_pre_send_response', $data, $license, $metadata );

		return $response;
	}

	/**
	 * Gets metadata from the client request headers.
	 *
	 * Only works during client request with `Referer` in request header.
	 *
	 * @param License $license The current license instance.
	 * @param bool    $key     Whether to return meta key also.
	 *
	 * @return array Array of license meta value.
	 *               If key is true, `array( 'key' => $meta_key, 'value' => $metadata )`.
	 */
	private function get_metadata( License $license, bool $key = false ) {
		$headers  = getallheaders();
		$client   = isset( $headers['Referer'] ) ? (string) $headers['Referer'] : '';
		$meta_key = 'data-' . self::parse_url( $client );
		$get_meta = lmfwc_get_license_meta( $license->getId(), $meta_key, true );
		$metadata = is_array( $get_meta ) ? $get_meta : array();

		$return = $metadata;

		if ( $key ) {
			$return = array(
				'key'   => $meta_key,
				'value' => $metadata,
			);
		}

		return $return;
	}

	/**
	 * Executes tasks when license expired.
	 *
	 * @param License $license The current license instance.
	 */
	public function license_expired( License $license ) {
		$metadata = $this->get_metadata( $license, true );

		$meta_key   = $metadata['key'];
		$meta_value = $metadata['value'];

		if ( ! isset( $meta_value['expired'] ) || 'yes' !== $meta_value['expired'] ) {
			$meta_value['expired'] = 'yes';

			$this->update_meta( $license->getId(), $meta_key, $meta_value );
		}
	}

	/**
	 * Sets validation error.
	 *
	 * @param string $message     The error message.
	 * @param int    $status_code The error status code.
	 * @param mixed  $data        Optional. Additional data.
	 *
	 * @return \WP_Error
	 */
	private function request_error( $message, $status_code, $data = '' ) {
		$error_data = array();

		$error_data['status'] = $status_code;

		if ( $data ) {
			$error_data['data'] = $data;
		}

		return new \WP_Error(
			'license_server_error',
			$message,
			$error_data
		);
	}

	/**
	 * Parses URL to get the domain.
	 *
	 * @param string $domain The full URI.
	 *
	 * @return string
	 */
	public static function parse_url( $domain ) {
		$domain = wp_parse_url( $domain, PHP_URL_HOST );
		$domain = str_replace( 'www.', '', $domain );

		return sanitize_key( $domain );
	}

	/**
	 * Checks license expiry date.
	 *
	 * @param License $license The license instance.
	 *
	 * @return true|\WP_Error True if not expired, WP_Error with expired message otherwise.
	 *
	 * @filesource license-manager-for-woocommerce/includes/api/v2/Licenses.php
	 */
	public function is_license_valid( License $license ) {
		$expiry_date = $license->getExpiresAt();
		if ( $expiry_date ) {
			try {
				$expires_at   = new \DateTime( $expiry_date );
				$current_date = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
			} catch ( \Exception $e ) {
				return $this->request_error( $e->getMessage(), 405 );
			}

			if ( $current_date > $expires_at ) {
				$error = sprintf(
					/* Translators: %s - The license expiry date. */
					__( 'The license Key expired on %s (UTC).', 'tws-license-manager-server' ),
					'<b>' . $license->getExpiresAt() . '</b>'
				);

				return $this->request_error( $error, 405, $license->getExpiresAt() );
			}
		}

		return true;
	}

	/**
	 * Updates license metadata.
	 *
	 * If no meta key exists, new meta key/value will be added, else same meta key will be updated.
	 *
	 * @param int    $id    The license ID.
	 * @param string $key   The meta key to add/update value for.
	 * @param mixed  $value The meta value.
	 */
	public function update_meta( int $id, string $key, $value ) {
		if ( false === lmfwc_get_license_meta( $id, $key, true ) ) {
			lmfwc_add_license_meta( $id, $key, $value );
		} else {
			lmfwc_update_license_meta( $id, $key, $value );
		}
	}

	/**
	 * Converts given thing to an array.
	 *
	 * @param string|array $thing The thing to convert to an array.
	 *
	 * @return array
	 */
	public function make_thing_array( $thing ): array {
		$to_array = array();

		if ( is_string( $thing ) ) {
			$to_array = array( '1x' => $thing );
		} elseif ( is_array( $thing ) ) {
			$to_array = $thing;
		}

		return $to_array;
	}

	/**
	 * Adds admin options section for Server General setting.
	 */
	public function add_section() {
		Server::load()->container
		->add_section(
			self::OPTION,
			array(
				'tab_title' => __( 'General', 'tws-license-manager-server' ),
				'title'     => __( 'Basic Server Configruation', 'tws-license-manager-server' ),
				'desc'      => __( 'Setup the license server with recommended options. These options will handle how requests to be validated and responses to be parsed.', 'tws-license-manager-server' ),
			)
		)
		->add_field(
			'debug_mode',
			self::OPTION,
			array(
				'label'             => __( 'Turn On Debug Mode', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice alert">%2$s</span>',
					__( 'Prepare the server to talk to the client when the client is also in debug mode. If debug mode is turned on, none of the below options will work except "Enable Generator Endpoint".', 'tws-license-manager-server' ),
					__( 'Always turn "OFF" the debug mode when the site is in production.', 'tws-license-manager-server' )
				),
				'type'              => 'checkbox',
				'sanitize_callback' => 'sanitize_key',
				'class'             => 'widefat hz_switcher_control',
				'priority'          => 5,
				'default'           => 'off',
			)
		)
		->add_field(
			'debug_endpoint',
			self::OPTION,
			array(
				'label'             => __( 'Enable Generator Endpoint', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice alert">%2$s</span>',
					__( 'Enable "generators" as endpoint instead of "licenses". Enabling this will let making a request for <b>"/lmfwc/v2/generators"</b> from the client.', 'tws-license-manager-server' ),
					__( 'This option will not work if debug mode is turned "OFF".', 'tws-license-manager-server' )
				),
				'type'              => 'checkbox',
				'sanitize_callback' => 'sanitize_key',
				'class'             => 'widefat hz_switcher_control',
				'priority'          => 5,
				'default'           => 'off',
			)
		)
		->add_field(
			'license_validate_response',
			self::OPTION,
			array(
				'label'             => __( 'License Validation', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice alert">%2$s</span>',
					__( 'Message to send back to the client if the license key is invalid/not found/does not exist.', 'tws-license-manager-server' ),
					__( 'This is a must and so will be validated. Highly recommended you set your response message than the default.', 'tws-license-manager-server' )
				),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 10,
				'default'           => $this->defaults['license_validate_response'],
				'placeholder'       => $this->defaults['license_validate_response'],
			)
		)
		->add_field(
			'email_validate_response',
			self::OPTION,
			array(
				'label'             => __( 'Email Validation', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice success">%2$s</span>',
					__( 'Message to send back to the client if a user email address is invalid/not found/does not exist.', 'tws-license-manager-server' ),
					__( 'Highly recommended to set validation for user email address on client. If the request is not sent from the client to validate the email, it is completely ignored.', 'tws-license-manager-server' )
				),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 15,
				'default'           => $this->defaults['email_validate_response'],
				'placeholder'       => $this->defaults['email_validate_response'],
			)
		)
		->add_field(
			'order_validate_response',
			self::OPTION,
			array(
				'label'             => __( 'Order Validation', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice success">%2$s</span>',
					__( 'Message to send back to the client if the Order ID is invalid/not found/does not exist.', 'tws-license-manager-server' ),
					__( 'If the request is not sent from the client to validate the order, it is completely ignored.', 'tws-license-manager-server' )
				),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 20,
				'default'           => $this->defaults['order_validate_response'],
				'placeholder'       => $this->defaults['order_validate_response'],
			)
		)
		->add_field(
			'name_validate_response',
			self::OPTION,
			array(
				'label'             => __( 'Product Slug Validation', 'tws-license-manager-server' ),
				'desc'              => sprintf(
					'%1$s <span class="option_notice success">%2$s</span>',
					__( 'Message to send back to the client if product slug is invalid/not found/does not exist.', 'tws-license-manager-server' ),
					__( 'Highly recommended to set validation for product slug on client. If the request is not sent from the client to validate the product slug, it is completely ignored.', 'tws-license-manager-server' )
				),
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'class'             => 'widefat',
				'priority'          => 25,
				'default'           => $this->defaults['name_validate_response'],
				'placeholder'       => $this->defaults['name_validate_response'],
			)
		);
	}
}
