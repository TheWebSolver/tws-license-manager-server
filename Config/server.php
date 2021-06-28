<?php
/**
 * Plugin Name:          The Web Solver License Manager Server
 * Plugin URI:           https://github.com/TheWebSolver/tws-license-manager-server
 * Description:          A PHP Client for License Manager for WooCommerce plugin for servers with Amazon Web Services S3 integration for managing storage and downloads.
 * Version:              1.0
 * Author:               TheWebSolver
 * Author URI:           https://thewebsolver.com
 * Requires at least:    5.0
 * Requires PHP:         7.0
 * Text Domain:          tws-license-manager-server
 * Domain Path:          /Languages/
 * WC requires at least: 4.0.0
 * WC tested up to:      5.0.0
 *
 * @package              TheWebSolver\License_Manager\Server
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

use TheWebSolver\License_Manager\Server;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

Server::load()->instance();
