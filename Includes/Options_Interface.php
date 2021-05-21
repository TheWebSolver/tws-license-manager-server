<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server Options Interface.
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
 * TheWebSolver\License_Manager\Options_Interface interface.
 *
 * Handles options page creation.
 */
interface Options_Interface {
	/**
	 * Sets options section priority to container.
	 *
	 * The priority will be used with `admin_init` hook which in turns
	 * becomes the priority for displaying sections in options page.
	 *
	 * Higher the priority, sections gets appended to the last.
	 *
	 * ___
	 * ***Priority must be less than `99999`***.
	 * ___
	 *
	 * @param int $priority The `admin_init` hook priority.
	 *
	 * @return $this
	 */
	public function add_page_section( int $priority );

	/**
	 * Creates sections and fields to the container.
	 *
	 * This must be a callable method for `admin_init` hook
	 * initialized by the method `add_page_section`, and
	 * with the priority set by `set_section_priority`.
	 */
	public function add_section();

	/**
	 * Get section option.
	 *
	 * Returns the section options as an array values.
	 *
	 * @param string $key The section's field key to get the field value directly.
	 *
	 * @return array
	 */
	public function get_option( string $key = '' );
}
