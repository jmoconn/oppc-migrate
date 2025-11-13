<?php
/**
 * @package  OPPC_Migrate
 */
namespace OppcMigration;

final class Init
{
	/**
	 * Add general actions and filters, and add custom CLI command
	 */
	public function initialize() 
	{
		if ( wp_get_environment_type() === 'local' ) {
			add_filter( 'https_local_ssl_verify', '__return_false' );
			add_filter( 'https_ssl_verify', '__return_false' );
		}
		
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'oppc', ( new \OppcMigration\Base\Migrate() ) );
			
			if ( defined( 'WP_CLI' ) && WP_CLI) {
				\WP_CLI::add_hook('after_wp_load', function() {
					system('clear');
				});
			}
		}
	}
}