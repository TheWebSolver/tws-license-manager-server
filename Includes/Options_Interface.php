<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server WooCommerce Checkout handler.
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

namespace TheWebSolver\License_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TheWebSolver\License_Manager\Options_Interface interface.
 *
 * Handles options page creation.
 */
interface Options_Interface {
	/**
	 * Sets options section priority to container.
	 *
	 * This priority will be used with `admin_init` hook which in turns
	 * becomes the priority for displaying sections in options page.
	 *
	 * Higher the priority, sections gets appended to the last.
	 *
	 * ___
	 * ***It must be less than `99999`***.
	 * ___
	 *
	 * @param int $priority The `admin_init` hook priority.
	 *
	 * @return $this
	 */
	public function set_section_priority( int $priority );

	/**
	 * Initializes the section creation.
	 *
	 * Internally, it will add `admin_init` action hook
	 * with method `add_page_section` as callable method.
	 */
	public function add_section();

	/**
	 * Creates sections and fields to the container.
	 *
	 * This method must be a callable method for `admin_init` hook
	 * initialized by the method `add_option`, and
	 * with the priority set by `set_section_priority`.
	 */
	public function add_page_section();

	/**
	 * Get section options.
	 *
	 * Returns the section options as an array values.
	 *
	 * @return array
	 */
	public function get_options();
}
