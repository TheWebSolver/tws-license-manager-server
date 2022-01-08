<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server WooCommerce Order handler.
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

use LicenseManagerForWooCommerce\Settings;
use LicenseManagerForWooCommerce\Models\Resources\License;
use LicenseManagerForWooCommerce\Repositories\Resources\License as License_Handler;
use LicenseManagerForWooCommerce\Models\Resources\Generator;
use LicenseManagerForWooCommerce\Repositories\Resources\Generator as Generator_Handler;
use TheWebSolver\License_Manager\Single_Instance;
use WC_Order;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TheWebSolver\License_Manager\Components\Order class.
 *
 * Handles WooCommerce order.
 */
final class Order {
	use Single_Instance;

	/**
	 * Renewing order IDs for the parent license order.
	 *
	 * @var string
	 */
	const RENEWAL_IDS_KEY = 'tws_license_manager_renew_order_ids';

	/**
	 * The parent order ID which issued the license.
	 *
	 * @var int
	 */
	private $parent_id = 0;

	/**
	 * The child (current) order ID which renewed the parent order.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The current Order instance.
	 *
	 * @var WC_Order
	 */
	private $order;

	/**
	 * The license instance.
	 *
	 * @var License
	 */
	private $license;

	/**
	 * The license key to renew.
	 *
	 * @var string
	 */
	private $license_key;

	/**
	 * Sets up WooCommerce order.
	 *
	 * @return Order
	 */
	public function instance(): Order {
		$this->handle_order_status_transition();

		return $this;
	}

	/**
	 * Handles transition of order status.
	 *
	 * @filesource license-manager-for-woocommerce/includes/integrations/woocommerce/Order.php
	 */
	private function handle_order_status_transition() {
		$status = Settings::get( 'lmfwc_license_key_delivery_options', Settings::SECTION_ORDER_STATUS );

		// The order status settings haven't been configured.
		if ( empty( $status ) ) {
			return;
		}

		foreach ( $status as $status => $settings ) {
			if ( array_key_exists( 'send', $settings ) ) {
				$value = filter_var( $settings['send'], FILTER_VALIDATE_BOOLEAN );

				if ( $value ) {
					$transition = str_replace( 'wc-', '', $status );
					$tag        = "woocommerce_order_status_{$transition}";

					add_action( $tag, array( $this, 'validate_order_with_license' ), 10, 2 );
				}
			}
		}
	}

	/**
	 * Validates order that has license key entered during checkout when placing new order.
	 *
	 * @param int      $order_id The current order ID.
	 * @param WC_Order $order    The current order.
	 */
	public function validate_order_with_license( int $order_id, WC_Order $order ) {
		// Hack we did at checkout for lmfwc order meta was unsuccessful, stop further processing.
		if ( 1 !== absint( get_post_meta( $order_id, 'lmfwc_order_complete', true ) ) ) {
			return;
		}

		$license_key       = get_post_meta( $order_id, Checkout::PARENT_ORDER_KEY, true );
		$this->license     = lmfwc_get_license( $license_key );
		$this->license_key = $license_key;
		$this->id          = $order_id;
		$this->order       = $order;

		$this->update_license_validity();
	}

	/**
	 * Updates license validity.
	 *
	 * @return array|false Updated expiry date and status in an array, false if can't update.
	 */
	private function update_license_validity() {
		// Bail early if not a valid license.
		if ( ! $this->license ) {
			return false;
		}

		$this->parent_id = $this->license->getOrderId();

		// Bail if expiration date isn't set for license.
		if ( ! $this->license->getExpiresAt() ) {
			return false;
		}

		// Get the product ID for which the license is issued.
		$id = $this->license->getProductId();

		// Check if generator is used for issuing license.
		$is_used = 1 === absint( get_post_meta( $id, Product::USE_GENERATOR_META, true ) );

		// Get the generator ID if product license was issued by generator.
		$gen_id = $is_used ? get_post_meta( $id, Product::ASSIGNED_GENERATOR_META, true ) : 0;

		// Bail if generator ID is not valid.
		if ( ! $gen_id ) {
			return false;
		}

		/**
		 * The current license generator.
		 *
		 * @var Generator $generator The Generator object.
		 */
		$generator = Generator_Handler::instance()->find( $gen_id );

		// Bail if generator has number of days for expiry not set.
		if ( ! $generator || ! $generator->getExpiresIn() ) {
			return false;
		}

		return $this->update_expiry_date( $this->license, $generator, true );
	}

	/**
	 * Updates license to new expiry date.
	 *
	 * Handy API that can be used for testing out too.
	 * Set the `$update` value to `false` while testing.
	 *
	 * @param License   $license   The ordered product license key.
	 * @param Generator $generator The ordered product license generator.
	 * @param bool      $update    True to update the license, false to return data to be updated.
	 *
	 * @return (string|int)[]
	 */
	public function update_expiry_date( License $license, Generator $generator, bool $update = false ) {
		// Extend license validity by generator expiry days (same format used by lmfwc).
		$expires_in = 'P' . $generator->getExpiresIn() . 'D';

		// Create date interval from the generator.
		$interval = new \DateInterval( $expires_in );

		// lmfwc uses "GMT" as timezone. Lets create one.
		$timezone = new \DateTimeZone( 'GMT' );

		// lmfwc uses "Y-m-d H:i:s" format for date time. Lets use that one.
		$format = 'Y-m-d H:i:s';

		// Get license expiry time.
		$expires_at = $license->getExpiresAt();

		// Get the previous expiry date time from license.
		$previous_time = \DateTime::createFromFormat( $format, $expires_at, $timezone );

		// Get the current date time.
		$current_time = new \DateTime( 'now', $timezone );

		// Create new expiry date by adding generators interval to previous license expiry date.
		$new_expiry = $previous_time->add( $interval )->format( $format );

		// Create comparison dates to check if license has expired.
		$license_expiry_time = new \DateTime( $expires_at );
		$maximum_grace_time  = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		/**
		 * WPHOOK: Filter -> Extend expiry time from the renewed time if license expired.
		 *
		 * @param bool $extend Whether to start expiry from current time or previous expiry time.
		 * @var   bool
		 * @example Usage
		 *
		 * License expired on Jan 8, 2022.
		 * User renewed license on March 8, 2022.
		 * Generator has expiry days as 365 (a year).
		 * * If true, new validity will be March 8, 2023.
		 * * If false, new validity will be Jan 8, 2023.
		 */
		$extend = apply_filters( 'hzfex_license_manager_server_extend_expiration_time', true );

		// If license has expired, create new expiry time from the current time.
		if ( ( $license_expiry_time < $maximum_grace_time ) && $extend ) {
			$new_expiry = $current_time->add( $interval )->format( $format );
		}

		$data = array(
			'expires_at' => $new_expiry,
			'status'     => 2, // DELIVERED.
		);

		if ( $update ) {
			License_Handler::instance()->update( $license->getId(), $data );

			$meta     = get_post_meta( $this->parent_id, self::RENEWAL_IDS_KEY, true );
			$meta     = is_array( $meta ) ? $meta : array();
			$old_meta = $meta;
			$meta[]   = $this->id;

			$args = array(
				'customer' => 0,
				'user'     => false,
				'note'     => sprintf(
					/* translators: %s - The license key. */
					__( 'Successfully renewed License Key: "%s".', 'tws-license-manager-server' ),
					$this->license_key
				),
			);

			/**
			 * WPHOOK: Filter -> Order note message after license renewal.
			 *
			 * @param array  $args        Note args. Possible key/value is:
			 * * `int`    `customer` - Is this a note for the customer?.
			 * * `bool`   `user`     - Was the note added by a user?.
			 * * `string` `note`     - The note to add.
			 * @param string $license_key The license key which is renewed.
			 * @var   (int|bool|string)[]
			 */
			$note = apply_filters( 'hzfex_license_manager_server_add_order_note', $args, $this->license_key );

			$this->order->add_order_note( $note['note'], $note['customer'], $note['user'] );

			// Push and save current order ID to meta value of license parent order ID.
			// This creates a parent/child relation saving all child order IDs to the license order.
			// This can then be used for showing all child orders, or for any other purpose.
			update_post_meta( $this->parent_id, self::RENEWAL_IDS_KEY, $meta, $old_meta );

			// Successfully renewed license. Clear license parent order ID from current order.
			delete_post_meta( $this->id, Checkout::PARENT_ORDER_KEY );
		}

		return $data;
	}
}
