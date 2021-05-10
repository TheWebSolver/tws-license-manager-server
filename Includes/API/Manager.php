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

use LicenseManagerForWooCommerce\Repositories\Resources\License as LicenseHandler;
use LicenseManagerForWooCommerce\Models\Resources\License;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * License Manager Server.
 */
final class Manager {
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
	private $debug = true;

	/**
	 * Whether to update license status to active(3)/inactive(4).
	 *
	 * @var bool
	 */
	private $update_status = true;

	/**
	 * Data to be added on license activation.
	 *
	 * @var array
	 */
	private $activation_data = array();

	/**
	 * Connector constructor.
	 *
	 * @param bool   $debug   Server in test mode. Do not set to `true` in production.
	 *                        When set to `false`, only license activation/deactivation
	 *                        from client license form is possible.
	 * @param string $license The request route endpoint. Must be true when debug is `false`.
	 *                        The endpoint will be appended after version.
	 *                        ***true*** for `licenses`, ***false*** for `generators`.
	 */
	public function __construct( $debug = false, $license = true ) {
		$this->debug   = $debug;
		$request_base  = '/lmfwc/v2/';
		$endpoint      = $license ? 'licenses' : 'generators';
		$request_route = $request_base . $endpoint;

		// Set properties.
		$this->route = $request_route;
	}

	/**
	 * Sets validation data to check request against.
	 *
	 * @param string[] $data Same validation key/value pair set on client site
	 *                       with client site method `Manager::validation_data()`.
	 *
	 * @return Manager
	 */
	public function set_validation( $data ) {
		$this->validation_data = $data;

		return $this;
	}

	/**
	 * Validates API on server.
	 */
	public function validate() {
		add_filter( 'lmfwc_rest_api_validation', array( $this, 'validate_request' ), 10, 3 );
	}

	/**
	 * Updates license status and metadata.
	 *
	 * @param bool  $status True to set status as `active/inactive` and vice-versa.
	 * @param array $data   The data to add on license activation.
	 */
	public function update( $status = true, $data = array() ) {
		$this->update_status   = $status;
		$this->activation_data = is_array( $data ) ? $data : array( $data );

		// Modify response and perform additional tasks.
		add_filter( 'lmfwc_rest_api_pre_response', array( $this, 'parse_response' ), 10, 3 );
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
		$client_url = $request->get_header_as_array( 'referer' )[0];
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
			$msg = isset( $this->validation_data['license_key'] )
			? $this->validation_data['license_key']
			: 'License key not found';

			return $this->debug ? true : $this->request_error( $msg, 404, $error_data );
		}

		$meta_key     = 'data-' . $this->parse_url( $client_url );
		$metadata     = lmfwc_get_license_meta( $license->getId(), $meta_key, true );
		$metadata     = is_array( $metadata ) ? $metadata : array();
		$saved_email  = isset( $metadata['email'] ) ? $metadata['email'] : '';
		$saved_url    = isset( $metadata['url'] ) ? $metadata['url'] : '';
		$saved_status = isset( $metadata['status'] ) ? $metadata['status'] : '';
		$purchased_on = $license->getCreatedAt();

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
				__( 'The license for this site has already been <b>%sd</b>.', 'tws-license-manager-server' ),
				$parameters['form_state']
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
	private function is_valid_request( $license, $parameters, $client_url ) {
		$metadata = array();

		// Product name didn't match with WooCommerce Product Title, $request => WP_Error.
		if ( array_key_exists( 'slug', $parameters ) ) {
			$product = wc_get_product( $license->getProductId() );
			$msg     = isset( $this->validation_data['slug'] )
			? $this->validation_data['slug']
			: 'Product not found.';

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
			$msg   = isset( $this->validation_data['order_id'] )
			? $this->validation_data['order_id']
			: 'Order not found.';

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
			$msg  = isset( $this->validation_data['email'] )
			? $this->validation_data['email']
			: 'Email not found.';

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
		$metadata['key'] = 'data-' . $this->parse_url( $client_url );
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
			$data['error'] = __( 'Server hacked. Contact plugin support.', 'tws-license-manager-server' );

			return $data;
		}

		// Possible status of a license: SOLD(1), DELIVERED(2), ACTIVE(3), & INACTIVE(4).
		// Since, activation/deactivation happening here, status can only be 3 or 4.
		$status_number = 3;
		$status_text   = 'active';

		if ( 'deactivate' === $form_state ) {
			$status_number = 4;
			$status_text   = 'inactive';
		}

		$license_key = $data['licenseKey'];
		$license     = lmfwc_get_license( $license_key );
		$transient   = sha1( $license->getDecryptedLicenseKey() );
		$metadata    = get_transient( $transient );
		$meta_key    = is_array( $metadata ) && isset( $metadata['key'] ) ? (string) $metadata['key'] : '';

		// Clear meta key from metadata.
		unset( $metadata['key'] );

		// Get email from transient metadata.
		$saved_email = isset( $metadata['email'] ) ? $metadata['email'] : '';

		// Update active/inactive status if no of times it can be activated is only 1.
		if ( $this->update_status && 1 === $data['timesActivatedMax'] ) {
			LicenseHandler::instance()->update( $license->getId(), array( 'status' => $status_number ) );

			// Send updated status number as response data.
			$data['status'] = $status_number;
		}

		// Check if key is present.
		if ( $meta_key ) {
			// Set active status as meta value.
			$metadata['status'] = $status_text;

			// Send meta key as response data.
			$data['key'] = $meta_key;

			// Set additional activation parameters passed as meta value.
			if ( ! empty( $this->activation_data ) ) {
				$metadata['data'] = $this->activation_data;
			}

			$this->update_meta( $license->getId(), $meta_key, $metadata );

			// Clear transient.
			delete_transient( $transient );
		}

		// Send email address as response data, if set.
		if ( $saved_email ) {
			$data['email'] = $saved_email;
		}

		// Send license status text as response data with state key.
		$data['state'] = $status_text;

		/**
		 * WPHOOK: Filter -> Response data before sending back.
		 *
		 * @param array $data The response data.
		 */
		return apply_filters( 'hzfex_license_manager_server_pre_response_dispatch', $data );
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
		$from_key = ltrim( $uri, '/wp-json/lmfwc/v2/licenses/validate/' );

		// Trim the URI after license key (where query starts).
		$key     = substr( $from_key, 0, strpos( $from_key, '?' ) );
		$license = \lmfwc_get_license( $key );

		// Add error messages to response data if license has expired and update the the meta.
		if ( $license ) {
			$valid      = $this->is_license_valid( $license );
			$headers    = getallheaders();
			$client_url = isset( $headers['Referer'] ) ? (string) $headers['Referer'] : '';
			$meta_key   = 'data-' . $this->parse_url( $client_url );
			$metadata   = lmfwc_get_license_meta( $license->getId(), $meta_key, true );

			if ( is_wp_error( $valid ) ) {
				$data['status'] = 'expired';
				$data['error']  = array(
					'message' => $valid->get_error_message(),
					'data'    => $valid->get_error_data(),
				);

				$data['header']   = $client_url;
				$data['metadata'] = $metadata;
			}

			$data['license_key'] = $key;
		}

		/**
		 * WPHOOK: Filter -> Response data before sending back.
		 *
		 * Validation endpoint should be handled differently.
		 *
		 * @param array  $data      The response data.
		 * @param string $meta_key  The meta key to save license metadata.
		 * @param string $metadata  The existing metadata.
		 * @param array $parameters The request query parameters.
		 */
		return apply_filters( 'hzfex_license_manager_server_pre_response_validate', $data, $meta_key, $metadata, $parameters );
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
	private function parse_url( $domain ) {
		$domain = wp_parse_url( $domain, PHP_URL_HOST );
		$domain = ltrim( $domain, 'www.' );

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
	 * @param string $id    The license ID.
	 * @param string $key   The meta key to add/update value for.
	 * @param mixed  $value The meta value.
	 */
	public function update_meta( string $id, string $key, $value ) {
		if ( false === lmfwc_get_license_meta( $id, $key, true ) ) {
			lmfwc_add_license_meta( $id, $key, $value );
		} else {
			lmfwc_update_license_meta( $id, $key, $value );
		}
	}
}
