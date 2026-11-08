<?php
/**
 * Export Module Database Installation
 *
 * Handles database table creation and upgrades for the Report Export Module.
 *
 * @package MonsterInsights
 * @subpackage Pro
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create the secure exports directory with proper security files.
 *
 * This function creates the directory structure for storing export files
 * and adds security files to prevent direct access.
 *
 * @return bool True on success, false on failure.
 */
function monsterinsights_pro_export_create_directory() {
	$upload_dir = WP_CONTENT_DIR . '/uploads/monsterinsights/exports/';

	// Create directory if it doesn't exist
	if ( ! file_exists( $upload_dir ) ) {
		if ( ! wp_mkdir_p( $upload_dir ) ) {
			return false;
		}
	}

	// Create .htaccess file to deny direct access
	$htaccess_file = $upload_dir . '.htaccess';
	if ( ! file_exists( $htaccess_file ) ) {
		$htaccess_content = "# MonsterInsights Export Security\n";
		$htaccess_content .= "<Files *>\n";
		$htaccess_content .= "Order Deny,Allow\n";
		$htaccess_content .= "Deny from all\n";
		$htaccess_content .= "</Files>\n";

		file_put_contents( $htaccess_file, $htaccess_content );
	}

	// Create index.php security file
	$index_file = $upload_dir . 'index.php';
	if ( ! file_exists( $index_file ) ) {
		file_put_contents( $index_file, '<?php // Silence is golden' );
	}

	return true;
}
add_action( 'monsterinsights_after_install_routine', 'monsterinsights_pro_export_create_directory' );
