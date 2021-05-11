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

namespace TheWebSolver\License_Manager\Components;

use LicenseManagerForWooCommerce\Settings;
use LicenseManagerForWooCommerce\Models\Resources\License;
use LicenseManagerForWooCommerce\Repositories\Resources\License as License_Handler;
use LicenseManagerForWooCommerce\Models\Resources\Generator;
use LicenseManagerForWooCommerce\Repositories\Resources\Generator as Generator_Handler;
use TheWebSolver\License_Manager\Single_Instance;

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
	 * Sets up WooCommerce order.
	 *
	 * @return Order
	 */
	public function instance() {
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
	 * @param int       $order_id The current order ID.
	 * @param \WC_Order $order    The current order.
	 */
	public function validate_order_with_license( $order_id, $order ) {
		// Hack we did at checkout for lmfwc order meta was unsuccessful, stop further processing.
		if ( ! get_post_meta( $order_id, 'lmfwc_order_complete', true ) ) {
			return;
		}

		$parent_order_id = get_post_meta( $order_id, Checkout::PARENT_ORDER_KEY, true );

		$this->update_license_validity( $parent_order_id );
	}

	/**
	 * Gets all license from an order.
	 *
	 * @param int $order_id The order ID for which license(s) to retrieve.
	 *
	 * @return bool True if successful, false otherwise.
	 */
	private function update_license_validity( $order_id ) {
		$licenses = License_Handler::instance()->findAllBy( array( 'order_id' => $order_id ) );
		$complete = false;

		// Order must save all generated licenses in an array.
		if ( ! is_array( $licenses ) ) {
			return $complete;
		}

		/** @var License $license The License object. */ // phpcs:ignore
		foreach ( $licenses as $license ) {
			// Get transient key (created from license key field at checkout).
			$key = sha1( $license->getDecryptedLicenseKey() );

			// Get checkout transient data.
			$transient = get_transient( $key );

			// Get parent order ID from transient.
			$parent_id = isset( $transient['order_id'] ) ? (int) $transient['order_id'] : 0;

			// Get license ID entered during checkout from transient.
			$license_id = isset( $transient['license_id'] ) ? (int) $transient['license_id'] : 0;

			// Perform tasks for license whose order ID and License key matches.
			if ( ( $parent_id === (int) $order_id ) && ( $license->getId() === $license_id ) ) {

				// Check if expiration date set for license.
				if ( $license->getExpiresAt() ) {
					// Get the product ID for which the license is issued.
					$id = $license->getProductId();

					// Check if generator was used for issuing license.
					$use = get_post_meta( $license->getProductId(), Product::USE_GENERATOR_META, true );

					// Get the generator ID if product license was issued by generator.
					$get = $use ? get_post_meta( $id, Product::ASSIGNED_GENERATOR_META, true ) : 0;

					// Check if generator ID is valid.
					if ( $get ) {
						/** @var Generator $generator The Generator object. */ // phpcs:ignore
						$generator = Generator_Handler::instance()->find( $get );

						// Check if generator has number of days for expiry set.
						if ( $generator->getExpiresIn() ) {
							$this->update_expiry_date( $license, $generator, true );

							// Clear the checkout tranisent.
							delete_transient( $key );

							// Flag successful update of the license.
							$complete = true;
						}
					}
				}

				// Found license, updated expiry date. Stop futher execution.
				break;
			}
		}

		return $complete;
	}

	/**
	 * Updates license to new expiry date.
	 *
	 * @param License   $license   The ordered product license key.
	 * @param Generator $generator The ordered product license generator.
	 * @param bool      $update    True to update the license, false to return data to be updated.
	 */
	public function update_expiry_date( License $license, Generator $generator, $update = false ) {
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

		// If license has expired, create new expiry time from the current time.
		if ( $license_expiry_time < $maximum_grace_time ) {
			$new_expiry = $current_time->add( $interval )->format( $format );
		}

		$data = array(
			'expires_at' => $new_expiry,
			'status'     => 2, // DELIVERED.
		);

		// Update license with new data.
		if ( $update ) {
			License_Handler::instance()->update( $license->getId(), $data );
		}

		return $data;
	}
}
