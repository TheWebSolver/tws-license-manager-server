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
use WP_Error;
use WP_Rest_Server;
use WP_Rest_Request;

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
	 * Product Secret Key.
	 *
	 * @var string
	 */
	private $hash = '';

	/**
	 * The request URL.
	 *
	 * @var string
	 */
	public $client_url = '';

	/**
	 * The WooCommerce product slug.
	 *
	 * @var string
	 */
	public $product_slug = '';

	/**
	 * The request license key.
	 *
	 * @var string
	 */
	public $license_key = '';

	/**
	 * The request License instance.
	 *
	 * NOTE: This should not be used when returning response data.
	 *
	 * The response data has already been modified if the
	 * request succeeds. Fresh license data must be
	 * fetched so license activation times is accurate.
	 *
	 * @var License
	 */
	public $license;

	/**
	 * Current request type.
	 *
	 * Possible values are `activate|deactivate|validate`.
	 *
	 * @var string
	 */
	public $request_type = '';

	/**
	 * Current request parameters.
	 *
	 * @var array
	 */
	public $parameters = array();

	/**
	 * License meta key.
	 *
	 * @var string
	 */
	public $meta_key = '';

	/**
	 * License metadata.
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * Sets up server manager.
	 *
	 * @return Manager
	 */
	public function instance(): Manager {
		$options        = (array) get_option( self::OPTION, array() );
		$this->defaults = array(
			'debug_mode'                => 'off',
			'debug_endpoint'            => 'off',
			'license_validate_response' => 'License not found.',
			'email_validate_response'   => 'Email address not found.',
			'order_validate_response'   => 'Order not found.',
			'name_validate_response'    => 'Product not found.',
		);

		// phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure, WordPress.CodeAnalysis.AssignmentInCondition
		if ( is_string( $hash = Server::load()->secret_key() ) ) {
			$this->hash = (string) $hash;
		}

		if ( ! empty( $options ) ) {
			array_walk( $options, array( $this, 'set_options' ) );
		} else {
			$this->options = $this->defaults;
		}

		$this->debug = 'on' === $this->options['debug_mode'];
		$generator   = 'on' === $this->options['debug_endpoint'];
		$endpoint    = $this->debug && $generator ? 'generators' : 'licenses';
		$this->route = "/lmfwc/v2/$endpoint";

		return $this;
	}

	/**
	 * Sets options.
	 *
	 * @param string $option The option value.
	 * @param string $key    The option key.
	 */
	public function set_options( string $option, string $key ) {
		$this->options[ $key ] = '' !== $option ? $option : $this->defaults[ $key ];
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
	 * Checks if request route is for managing license.
	 *
	 * The check is made against `activate|deactivate|validate` states in the
	 * request route (that is present just before the license key in URL).
	 * Route is valid if an array with only one state is found.
	 *
	 * @param string $route The state in request route from WP REST API.
	 *
	 * @return string The route state if found, empty string otherwise.
	 */
	private function is_license_route( string $route ): string {
		$states   = array( 'activate', 'deactivate', 'validate' );
		$callback = function( $state ) use ( $route ) {
			return strpos( $route, "{$this->route}/{$state}/" ) === 0;
		};
		$endpoint = array_filter( $states, $callback );

		// Valid route must contain only one state in an array.
		return ! empty( $endpoint ) && 1 === count( $endpoint ) ? (string) array_shift( $endpoint ) : '';
	}

	/**
	 * Gets license meta key.
	 *
	 * @return string
	 */
	private function get_meta_key(): string {
		return self::parse_url( $this->client_url ) . '-' . $this->product_slug;
	}

	/**
	 * Upgrades license metadata.
	 *
	 * The upgrade is for the old license meta key.
	 * New meta key is set from clinet URL and product slug.
	 * Doing so will allow client manager to be used on multiple
	 * premium plugins/themes on the same site and keeping
	 * the meta key unique.
	 *
	 * @param bool $update Whether to update to new meta key or not.
	 *
	 * @return bool True if old meta found, false otherwise.
	 */
	private function upgrade_meta( bool $update = false ): bool {
		$id      = $this->license->getId();
		$old_key = 'data-' . self::parse_url( $this->client_url );
		$old_val = lmfwc_get_license_meta( $id, $old_key, true );

		if ( false === $old_val ) {
			return false;
		}

		if ( $update ) {
			$this->update_meta( $old_val );

			lmfwc_delete_license_meta( $id, $old_key, $old_val );
		}

		return true;
	}

	/**
	 * Syncs activated count.
	 *
	 * This syncs license's times activated count after expiry.
	 * Decreases active count by 1 so new activation doesn't count for total activation.
	 *
	 * This is done because if request is valid, response will increase active count by 1.
	 *
	 * @param License $license The license object.
	 */
	private function sync_active_count( License $license ) {
		$data = array( 'times_activated' => $license->getTimesActivated() - 1 );

		lmfwc_update_license( $this->license_key, $data );
	}

	/**
	 * Gets response error that is to be sent back with data.
	 *
	 * @param string $msg  Error message.
	 * @param int    $code Error Code.
	 *
	 * @return array
	 */
	private function response_error( string $msg, int $code = 400 ): array {
		return array(
			'message' => $msg,
			'code'    => $code,
		);
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
	 * @param true|WP_Error   $result  Response to replace the requested version with.
	 *                                 Can be anything a normal endpoint can return,
	 *                                 or null to not hijack the request.
	 * @param WP_Rest_Server  $server  Server instance.
	 * @param WP_Rest_Request $request Request used to generate the response.
	 *
	 * @return true|WP_Error
	 *
	 * @link https://www.licensemanager.at/docs/tutorials-how-to/rest-api/validating-custom-request-data
	 * @link https://developer.wordpress.org/reference/hooks/rest_pre_dispatch/
	 */
	public function validate_request( $result, WP_Rest_Server $server, WP_Rest_Request $request ) {
		/**
		 * Debug mode on server can allow any valid API endpoint request.
		 *
		 * When debug mode if off:
		 * - License shouldn't already have been activated/deactivated for same site.
		 * -- Meaning once license is active for the site, it can't be activated again.
		 * -- It can only be deactivated after that and vice versa.
		 * - Route must be for license activation/deactivation/validation.
		 * - Request can only be made from license form (or "validate_license" method).
		 * - Endpoint (activate/deactivate) will be generated from the client license form.
		 * - Final possible routes for validation are:
		 * -- /lmfwc/v2/licenses/activate/
		 * -- /lmfwc/v2/licenses/deactivate/
		 * -- /lmfwc/v2/licenses/validate/ (from "validate_license" method)
		 */
		if ( $this->debug ) {
			return true;
		}

		$valid_route = $this->is_license_route( $request->get_route() );

		// Not a activate/deactivate/validate route, $request => valid.
		if ( ! $valid_route ) {
			return true;
		}

		/**
		 * WPHOOK: Filter -> Forbit API access directly.
		 *
		 * Whether License Manager for WooCommerce can be accessed using REST API
		 * programs such as browser URL, Postman, etc.
		 *
		 * Restricting here means API can only be accessed when using the license form
		 * at the client site. However, it can be hacked by passing URL parameter like this:
		 * `'form_state'='validate'`. Still, even if form_state is passed in the parameter
		 * as a hacking mechanism, futher validations are in place. So, no worries there.
		 *
		 * @param bool $restrict Whether to restrict direct access or not.
		 * @var   bool           True to restrict, false to allow direct access.
		 * @example usage Use below code to remove API restriction. By default, it is set to true.
		 * ```
		 * add_filter('hzfex_license_manager_server_request_restrict_api_access', '__return_false');
		 * ```
		 */
		$restrict   = apply_filters( 'hzfex_license_manager_server_request_restrict_api_access', true );
		$parameters = $request->get_params();
		$state      = isset( $parameters['form_state'] ) ? (string) $parameters['form_state'] : '';
		$route      = "{$this->route}/{$state}/";
		$error      = $this->request_error( __( 'Direct access to License API is forbidden.', 'tws-license-manager-server' ), 403 );

		if ( ! $state ) {
			// Forbit API access directly from REST API programs if restriction applied, else $request => valid.
			return $restrict ? $error : true;
		} elseif ( $state !== $valid_route ) {
			// Prevent different state on route and parameter.
			return $error;
		}

		$authorize  = $request->get_header_as_array( 'authorization' );
		$authorize  = is_array( $authorize ) ? (string) $authorize[0] : 'Basic None';
		$from       = $request->get_header_as_array( 'from' );
		$user_email = is_array( $from ) ? (string) $from[0] : '';
		$client     = $request->get_header_as_array( 'referer' );
		$client_url = is_array( $client ) ? (string) $client[0] : '';
		$authorize  = explode( ' ', $authorize );
		$auth_type  = isset( $authorize[0] ) ? $authorize[0] : '';
		$auth_val   = isset( $authorize[1] ) ? $authorize[1] : '';

		// Request is not being sent from license form (except validation), $request => WP_Error.
		if (
			( 'validate' !== $state ) &&
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			( 'TWS' !== $auth_type || base64_decode( $auth_val ) !== $this->hash )
		) {
			return $this->request_error(
				sprintf(
					/* translators: %s: The current activate/deactivate request type. */
					__( 'You are not authorized to %s license.', 'tws-license-manager-server' ),
					$state
				),
				401
			);
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

		// No license found, $request => WP_Error.
		if ( ! $license ) {
			return $this->request_error( $this->options['license_validate_response'], 404, $error_data );
		}

		$auth_key             = 'data-' . self::parse_url( $client_url );
		$this->client_url     = $client_url;
		$this->product_slug   = (string) $parameters['slug'];
		$this->meta_key       = $this->get_meta_key();
		$this->license_key    = $endpoint[1];
		$this->license        = $license;
		$this->request_type   = $state;
		$this->parameters     = $parameters;
		$this->meta['status'] = 'deactivate' === $state ? 'inactive' : 'active';
		$this->meta['url']    = $client_url;

		$this->upgrade_meta( true );

		$metadata     = $this->get_metadata();
		$metadata     = is_array( $metadata ) ? $metadata : array();
		$saved_email  = isset( $metadata['email'] ) ? (string) $metadata['email'] : '';
		$saved_url    = isset( $metadata['url'] ) ? (string) $metadata['url'] : '';
		$saved_status = isset( $metadata['status'] ) ? (string) $metadata['status'] : '';
		$purchased_on = $license->getCreatedAt();
		$has_expired  = isset( $metadata['expired'] ) ? (string) $metadata['expired'] : '';

		// Check for validation request before proceeding further.
		if (
			( 'validate' === $state ) &&
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			( 'TWS' !== $auth_type || "{$auth_key}/{$purchased_on}:{$this->hash}" !== base64_decode( $auth_val ) )
		) {
			return $this->request_error(
				__( 'You are not authorized for making license validation.', 'tws-license-manager-server' ),
				401,
				$error_data
			);
		}

		/**
		 * This event occurs if license has expired on server already and client makes another request.
		 *
		 * This event can be triggered on three possible cases once license expired meta
		 * is saved on server as well as on client.
		 * License meta is added at the time just before sending the response back to client.
		 *
		 * - FIRST CASE: Activate/deactivate request sent from license form.
		 *   Before sending expired error response, expired => yes value is added to license meta.
		 *
		 * - SECOND CASE: cron event has routine check set on client.
		 *   Once license expires on server, expired => yes value is added to license meta.
		 *
		 * - THIRD CASE: WordPress updates triggers on the client.
		 *   If license expired on server, expired => yes value is added to license meta.
		 *
		 * In a nutshell, activate form won't trigger this event unless
		 * license metadata is expired and client status is also expired.
		 */
		if ( 'yes' === $has_expired ) {
			// License still expired, stop processing further.
			if ( is_wp_error( $this->is_license_valid() ) ) {
				// Let the scheduled request pass.
				if (
					'validate' === $state &&
					isset( $parameters['flag'] ) &&
					'cron' === $parameters['flag']
				) {
					return true;
				}

				return $this->request_error(
					__( 'Your license has expired. Renew your license first.', 'tws-license-manager-server' ),
					400
				);
			}

			/*
			|-------------------------------------------------|
			|- If we reached here, license has been renewed. -|
			|-------------------------------------------------|
			*/

			// Lets stop there. Can't deactivate just renewed license.
			if ( 'deactivate' === $state ) {
				return $this->request_error( __( 'Please activate your license first after renewal.', 'tws-license-manager-server' ), 400 );
			}

			// Create newly renewed license metadata.
			$renewed_metadata = array(
				'status' => $saved_status,
				'url'    => $client_url,
			);

			if ( $user_email ) {
				$renewed_metadata['email'] = $user_email;
			}

			// Check if license was active when it expired.
			if ( 'active' === $saved_status ) {
				$renewed_metadata['status'] = 'inactive';

				$this->sync_active_count( $license );

				// Hack saved status so new activation is possible after renewal.
				$saved_status = 'inactive';
			}

			// Finally, clear the expired flag.
			$renewed_metadata['expired'] = 'no';

			$this->update_meta( $renewed_metadata );
		}

		// Same client, active status and from activate license form, $request => WP_Error.
		$active = ( $client_url === $saved_url ) && ( 'active' === $saved_status ) && ( 'activate' === $state );

		// Same client, inactive status and from deactivate license form, $request => WP_Error.
		$deactive = ( $client_url === $saved_url ) && ( 'inactive' === $saved_status ) && ( 'deactivate' === $state );

		// If email validation set, check that also.
		if ( $user_email ) {
			$active                    = $active && ( $user_email === $saved_email );
			$deactive                  = $deactive && ( $user_email === $saved_email );
			$this->meta['email']       = $user_email;
			$this->parameters['email'] = $user_email;
		}

		// Client manager already implements whether license is already active/inactive.
		// No remote request made if license has already been activated/deactivated.
		// This is an extra measure on server not to let bypass same request again for same client.
		if ( $active || $deactive ) {
			$msg = sprintf(
				/* Translators: %s - activate/deactivate state. */
				__( 'The license for this site has already been %sd.', 'tws-license-manager-server' ),
				"{$state}"
			);
			return $this->request_error( $msg, 400 );
		}

		return $this->is_valid_request();
	}

	/**
	 * Validates request license and parameters.
	 *
	 * @return true|WP_Error True if everything is validated, WP_Error otherwise.
	 */
	private function is_valid_request() {
		$parameters = $this->parameters;

		// Product slug didn't match with WooCommerce Product Title, $request => WP_Error.
		// Always check for product slug as it is a required parameter.
		$product = wc_get_product( $this->license->getProductId() );
		$error   = $this->request_error( $this->options['name_validate_response'], 404 );

		if (
			! array_key_exists( 'slug', $parameters )
			|| ! ( $product instanceof \WC_Product )
			|| $parameters['slug'] !== $product->get_slug()
		) {
			return $error;
		}

		// Order ID didn't match with WooCommerce order ID, $request => WP_Error.
		if ( array_key_exists( 'order_id', $parameters ) ) {
			$order = wc_get_order( $this->license->getOrderId() );
			$error = $this->request_error( $this->options['order_validate_response'], 404 );

			if (
				! ( $order instanceof \WC_Order ) ||
				absint( $parameters['order_id'] ) !== $order->get_id()
			) {
				return $error;
			}
		}

		// Email address didn't match with WordPress user email, $request => WP_Error.
		if ( array_key_exists( 'email', $parameters ) ) {
			$user  = get_userdata( $this->license->getUserId() );
			$error = $this->request_error( $this->options['email_validate_response'], 404 );

			if (
				! ( $user instanceof \WP_User ) ||
				$parameters['email'] !== $user->user_email
			) {
				return $error;
			}
		}

		/**
		 * WPHOOK: Filter -> Request validation.
		 *
		 * Any other validation besides above default can be filtered
		 * and validation check can be performed as required.
		 *
		 * NOTE: Devs must always return `true` on success, `WP_Error` on failure.
		 *
		 * @param License        $valid If request is valid.
		 * @param Manager        $this  The manager instance.
		 * @var   true|WP_Error
		 */
		$valid = apply_filters( 'hzfex_license_manager_server_request_validation', true, $this );

		return $valid;
	}

	/**
	 * Handles response.
	 *
	 * Possible status of a license: SOLD(1), DELIVERED(2), ACTIVE(3), & INACTIVE(4).
	 *
	 * @param string $method The request method.
	 * @param string $route  The request route name.
	 * @param array  $data   The response data.
	 *
	 * @return array The modified response data.
	 *
	 * @link https://www.licensemanager.at/docs/tutorials-how-to/rest-api/modifying-response-data
	 */
	public function parse_response( $method, string $route, array $data ): array {
		// Bail early if is in debug mode.
		if ( $this->debug ) {
			return $data;
		}

		$endpoint = "v2/licenses/{$this->request_type}/{license_key}";

		// Handle validate license for that endpoint and stop processing further.
		if ( 'v2/licenses/validate/{license_key}' === $endpoint ) {
			return $this->send_validate_response( $data );
		}

		// No activate/deactivate happening from the client license form, stop further processing.
		if ( $endpoint !== $route ) {
			return $data;
		}

		$id = isset( $data['productId'] ) ? (int) $data['productId'] : 0;

		/**
		 * WPHOOK: Filter -> License meta data.
		 *
		 * @param array     $license_meta Meta value to save to database.
		 * @param Manager   $this         The manager instance.
		 * @var   string[]
		 */
		$meta = apply_filters( 'hzfex_license_manager_server_response_license_meta', $this->meta, $this );

		// Check if key is present.
		if ( $this->meta_key ) {
			$this->update_meta( $meta );
		}

		return $this->send_product_details_with_data( $id, $meta );
	}

	/**
	 * Sends product meta details along with response.
	 *
	 * @param int   $id       The license product ID.
	 * @param array $metadata The license meta data.
	 *
	 * @return array The modified response data.
	 */
	private function send_product_details_with_data( int $id, array $metadata ): array {
		$data = Server::load()->product->get_data( $id );

		if ( 'active' === $metadata['status'] ) {
			$data['package'] = $this->get_package_for( $this->license );
		}

		return $this->send_response( $metadata, $data );
	}

	/**
	 * Sends response data for validate endpoint.
	 *
	 * @param array $data The response data.
	 *
	 * @return array The modified response data.
	 */
	private function send_validate_response( array $data ): array {
		// License not found for some reason, $data => error.
		if ( ! $this->license ) {
			$data['error'] = $this->response_error( __( 'License can not be verified.', 'tws-license-manager-server' ) );

			return $data;
		}

		$metadata = $this->get_metadata();

		// No license status meta saved, $data => error.
		if ( ! isset( $metadata['status'] ) ) {
			$data['error'] = $this->response_error( __( 'License status can not be verified.', 'tws-license-manager-server' ), 401 );

			return $data;
		}

		// Make license expire when the time comes.
		if (
			'expired' === $metadata['status'] &&
			( ! isset( $metadata['expired'] ) || 'yes' !== $metadata['expired'] )
		) {
			$metadata['expired'] = 'yes';
			$this->update_meta( $metadata );
		}

		// Send state also.
		$data['state'] = $metadata['status'];

		if ( is_wp_error( $this->is_license_valid() ) ) {
			$data['state']     = 'expired';
			$data['expiresAt'] = $this->license->getExpiresAt();

			// Update metadata when the license gets expired (if not updated already).
			if ( 'expired' !== $metadata['status'] ) {
				$metadata['status']  = 'expired';
				$metadata['expired'] = 'yes';

				$this->update_meta( $metadata );
			}
		}

		return $this->validate_license( $data, $metadata );
	}

	/**
	 * Validates response data before sending back.
	 *
	 * @param array $data     The response data.
	 * @param array $metadata The saved metadata.
	 *
	 * @return array
	 */
	private function validate_license( array $data, array $metadata ): array {
		// The license state.
		$state = (string) $data['state'];

		$flag        = isset( $this->parameters['flag'] ) ? (string) $this->parameters['flag'] : '';
		$is_schedule = $flag && 'cron' === $flag;
		$is_update   = $flag && ( 'update_themes' === $flag || 'update_plugins' === $flag );
		$meta        = Server::load()->product->get_data( $this->license->getProductId() );

		// Request is a scheduled (cron job) event, $data => valid.
		if ( $is_schedule ) {
			return $this->send_response( $metadata, $meta );
		}

		// Request is not made for product updates, stop further processing.
		// This is the stage where it is assumed that validation is performed without any flag.
		// Nothing happens on server side. License data is sent back as response.
		if ( ! $is_update ) {
			return $data;
		}

		// Send product info along with response.
		$data['product_meta'] = $meta;

		// License is not active, $data => error.
		if ( 'active' !== $metadata['status'] ) {
			$data['error'] = $this->response_error( __( 'License is not active.', 'tws-license-manager-server' ), 402 );

			return $data;
		}

		// License has expired, $data => error.
		if ( 'expired' === $state ) {
			$data['error'] = $this->response_error( __( 'License has expired.', 'tws-license-manager-server' ), 403 );

			return $data;
		}

		// Set the package URL as response.
		$data['product_meta']['package'] = $this->get_package_for( $this->license );

		return $data;
	}

	/**
	 * Sends resposne.
	 *
	 * @param array $metadata     The license meta value.
	 * @param array $product_meta The licensed product metadata.
	 *
	 * @return array
	 */
	public function send_response( array $metadata, array $product_meta ): array {
		// Since license activation count is changed after successful request,
		// new database call needs to be made to get updated license details.
		$license = lmfwc_get_license( $this->license_key );
		$data    = array(
			'key'          => $this->meta_key,
			'status'       => $metadata['status'],
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
	 * Gets license metadata.
	 *
	 * @return array License meta value in an array.
	 */
	private function get_metadata(): array {
		$meta = lmfwc_get_license_meta( $this->license->getId(), $this->meta_key, true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Executes tasks when license expired.
	 *
	 * @param License $license The current license instance.
	 */
	public function license_expired( License $license ) {
		$meta_value = $this->get_metadata();

		if ( ! isset( $meta_value['expired'] ) || 'yes' !== $meta_value['expired'] ) {
			$meta_value['expired'] = 'yes';

			$this->update_meta( $meta_value );
		}
	}

	/**
	 * Sets validation error.
	 *
	 * @param string $message     The error message.
	 * @param int    $status_code The error status code.
	 * @param mixed  $data        Optional. Additional data.
	 *
	 * @return WP_Error
	 */
	private function request_error( string $message, int $status_code, $data = '' ) {
		$error_data = array();

		$error_data['status'] = $status_code;

		if ( $data ) {
			$error_data['data'] = $data;
		}

		return new WP_Error(
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
	public static function parse_url( $domain ): string {
		$domain = wp_parse_url( $domain, PHP_URL_HOST );
		$domain = str_replace( 'www.', '', $domain );

		return sanitize_key( $domain );
	}

	/**
	 * Checks license expiry date.
	 *
	 * @return true|WP_Error True if not expired, WP_Error with expired message otherwise.
	 *
	 * @filesource license-manager-for-woocommerce/includes/api/v2/Licenses.php
	 */
	public function is_license_valid() {
		$expiry_date = $this->license->getExpiresAt();

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
					'<b>' . $this->license->getExpiresAt() . '</b>'
				);

				return $this->request_error( $error, 405, $this->license->getExpiresAt() );
			}
		}

		return true;
	}

	/**
	 * Updates license metadata.
	 *
	 * If no meta key exists, new meta key/value will be added, else same meta key will be updated.
	 *
	 * @param string[] $value The meta value.
	 */
	public function update_meta( array $value ) {
		$id  = $this->license->getId();
		$key = $this->meta_key;

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
	 * Gets Amazon S3 package.
	 *
	 * @param License $license The current license.
	 *
	 * @return string
	 */
	private function get_package_for( License $license ): string {
		// Initialize download package URL.
		$package = '';

		if ( 'on' === Server::load()->s3->get_option( 'use_amazon_s3' ) ) {
			$from_s3 = Server::load()->s3->get_presigned_url_for( $license );

			if ( ! is_wp_error( $from_s3 ) && is_string( $from_s3 ) ) {
				$package = $from_s3;
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

		return $url;
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
