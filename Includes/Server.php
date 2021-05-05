<?php // phpcs:ignore WordPress.NamingConventions
/**
 * The Web Solver Licence Manager Server.
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

use TheWebSolver\License_Manager\API\S3;

/**
 * TheWebSolver\License_Manager\Server class.
 */
final class Server {
	/**
	 * TheWebSolver\License_Manager\API\S3 Instance.
	 *
	 * @var S3
	 */
	public $s3;

	/**
	 * Instantiates Server.
	 *
	 * @return Server
	 */
	public static function init() {
		static $plugin;

		if ( ! is_a( $plugin, get_class() ) ) {
			$plugin = new self();
		}

		return $plugin;
	}

	/**
	 * Private Constructor.
	 */
	private function __construct() {
		$this->s3 = new S3( 'index.php' );
	}
}
