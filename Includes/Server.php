<?php // phpcs:disable

namespace TheWebSolver\License_Manager\REST_API;

use Aws\S3\S3Client;

final class Server {

	public $s3;

	public static function init() {
		static $plugin;

		if ( ! is_a( $plugin, get_class() ) ) {
			$plugin = new self();
		}

		return $plugin;
	}

	private function __construct() {}

	public function get_storage() {
	}
}