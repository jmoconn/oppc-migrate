<?php
/**
 * @package  Teal_Media_Content_Migration
 */
/*
Plugin Name: OPPC Content Migration
Author: Teal Media
Description: Content migration CLI tool
Text Domain: tm-content-migration
*/

// If this file is called firectly, abort
defined( 'ABSPATH' ) or die();

// Require Composer Autoload
if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

// helpful constants
define( 'OPPC_MIGRATE_PATH', plugin_dir_path( __FILE__ ) );
define( 'OPPC_MIGRATE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize all the core classes of the plugin
 */
if ( class_exists( 'OppcMigration\\Init' ) ) {
	function OPPC_Migrate_initialize() {
		( new OppcMigration\Init() )->initialize();
	}
	add_action( 'plugins_loaded', 'OPPC_Migrate_initialize' );
}