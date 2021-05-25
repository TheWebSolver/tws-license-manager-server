<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server Options Trait.
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

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Options trait.
 */
trait Options_Handler {
	/**
	 * Default option values.
	 *
	 * @var array
	 */
	private $defaults = array();

	/**
	 * The section options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Adds Checkout options.
	 *
	 * @param int $priority admin_init hook priority.
	 *
	 * @return $this
	 */
	public function add_page_section( int $priority ) {
		add_action( 'admin_init', array( $this, 'add_section' ), $priority );

		return $this;
	}

	/**
	 * Gets saved options.
	 *
	 * @param string $key The sections field's key.
	 *
	 * @return string|string[] The section all fields value in an array, or
	 *                         just the field's value if $key is passed.
	 */
	public function get_option( string $key = '' ) {
		if ( ! $key ) {
			return $this->options;
		}

		return isset( $this->options[ $key ] ) ? $this->options[ $key ] : '';
	}
}
