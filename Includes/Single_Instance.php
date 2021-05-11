<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server Singleton Trait.
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
 * Singleton trait.
 */
trait Single_Instance {
	/**
	 * Singleton instance.
	 *
	 * @var $this
	 */
	protected static $instance = null;

	/**
	 * Loads class instance.
	 *
	 * @return $this Instance.
	 */
	final public static function load() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	// phpcs:disable -- Prevent these events.
	protected function __construct() {}
	final protected function __clone() {}
	final protected function __sleep() {}
	final protected function __wakeup() {}
	// phpcs:disable
}

