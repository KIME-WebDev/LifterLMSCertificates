<?php
/**
 * Plugin Name:       LLSCE Certificate Report
 * Plugin URI:        https://www.llsce.org/
 * Description:       Lets an administrator pick a certificate date range and download an Excel workbook of LifterLMS certificate earners with their license, state, and pharmacist details resolved per certificate.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            KIME WebDev
 * License:           GPL-2.0-or-later
 * Text Domain:       llsce-certificate-report
 */

defined( 'ABSPATH' ) || exit;

define( 'LLSCE_CR_VERSION', '1.0.0' );
define( 'LLSCE_CR_FILE', __FILE__ );
define( 'LLSCE_CR_DIR', plugin_dir_path( __FILE__ ) );

require_once LLSCE_CR_DIR . 'includes/class-llsce-cr-xlsx-writer.php';
require_once LLSCE_CR_DIR . 'includes/class-llsce-cr-report.php';
require_once LLSCE_CR_DIR . 'includes/class-llsce-cr-admin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		if ( is_admin() ) {
			LLSCE_CR_Admin::instance();
		}
	}
);
