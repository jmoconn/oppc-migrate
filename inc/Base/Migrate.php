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
			'post_count' => -1,
			'fields' => 'ids',
		];
		$post_ids = get_posts( $args );

		foreach( $post_ids as $post_id ) {
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
}