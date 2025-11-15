<?php
/**
 * @package  OPPC_Migrate
 */
namespace OppcMigration\Base;

class Migrate
{
	public function import_therapists( $args = [], $assoc_args = [] )
	{
		( new \OppcMigration\Therapist\Import() )->import( $args, $assoc_args );
	}

	public function cleanup_therapists( $args = [], $assoc_args = [] )
	{
		$args = [
			'post_type' => 'therapist',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key'     => '_migrate_id',
					'compare' => 'EXISTS',
				],
			],
		];
		$post_ids = get_posts( $args );

		foreach( $post_ids as $key => $post_id ) {
			\WP_CLI::log( "Cleaning up $key therapist post ID: {$post_id}" );
			( new \OppcMigration\Therapist\Cleanup( $post_id ) )->cleanup();
		}
	}

	public function cleanup_therapist( $args = [], $assoc_args = [] )
	{
		$post_id = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : null;
		if ( empty( $post_id ) ) {
			print "Please provide a post ID to clean up a therapist.\n";
			return;
		}
		( new \OppcMigration\Therapist\Cleanup( $post_id ) )->cleanup();
	}

	public function index_therapists( $args = [], $assoc_args = [] )
	{
		$args = [
			'post_type' => 'therapist',
			'post_count' => -1,
			'fields' => 'ids',
		];
		$post_ids = get_posts( $args );
		foreach( $post_ids as $post_id ) {
			$user = oppc_user(['post_id' => $post_id]);
			$user->index(true);
			$user->sync_algolia();
		}
	}

	public function import_clients( $args = [], $assoc_args = [] )
	{
		( new \OppcMigration\Client\Import() )->import( $args, $assoc_args );
	}

	public function cleanup_clients( $args = [], $assoc_args = [] )
	{
		( new \OppcMigration\Client\Cleanup() )->cleanup( $args, $assoc_args );
	}

	public function delete_all_subscribers()
	{
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$user_ids = get_users( [
			'role' => 'subscriber',
			'number' => -1,
			'fields' => 'ids',
		] );

		if ( empty( $user_ids ) ) {
			\WP_CLI::success( 'No subscribers found.' );
			return;
		}

		$deleted = 0;

		foreach ( $user_ids as $user_id ) {
			if ( wp_delete_user( $user_id ) ) {
				$deleted++;
			} else {
				\WP_CLI::warning( "Failed to delete subscriber ID {$user_id}" );
			}
		}

		\WP_CLI::success( "Deleted {$deleted} subscriber(s)." );
	}

	public function delete_all_therapists()
	{
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		$args = [
			'post_type' => 'therapist',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key'     => '_migrate_id',
					'compare' => 'EXISTS',
				],
			],
		];

		$therapist_ids = get_posts( $args );

		$count = 0;
		foreach ( $therapist_ids as $therapist_id ) {
			wp_delete_post( $therapist_id, true );
			$count++;
		}

		\WP_CLI::success( "Deleted {$count} therapist(s)." );
	}
}