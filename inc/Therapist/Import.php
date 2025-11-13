<?php
/**
 * @package  OPPC_Migrate
 */
namespace OppcMigration\Therapist;

class Import
{
	private $therapists = [];
	private $migrate_id_key = '_migrate_id';
	private $post_type = 'therapist';
	private $update_existing = false;

	public function import( $args = [], $assoc_args = [] )
	{
		$this->prime_existing_imports();

		$rest_url = 'https://oppc-old.lndo.site/wp-json/custom/v1/therapists/';

		$page = isset( $assoc_args['page'] ) ? max( 1, absint( $assoc_args['page'] ) ) : 1;
		$per_page = isset( $assoc_args['per_page'] ) ? max( 1, absint( $assoc_args['per_page'] ) ) : 100;
		$max_pages = isset( $assoc_args['max_pages'] ) ? max( 1, absint( $assoc_args['max_pages'] ) ) : 0;
		$max_items = isset( $assoc_args['max_items'] ) ? max( 1, absint( $assoc_args['max_items'] ) ) : 0;
		$this->update_existing = isset( $assoc_args['update'] ) ? wp_validate_boolean( $assoc_args['update'] ) : false;

		$stats = [
			'inserted' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
		];

		$more_pages = true;
		$processed_items = 0;
		$reached_page_limit = false;
		$reached_item_limit = false;

		while ( $more_pages ) {
			if ( $max_pages && $page > $max_pages ) {
				$reached_page_limit = true;
				break;
			}

			if ( $max_items && $processed_items >= $max_items ) {
				$reached_item_limit = true;
				break;
			}

			$url = add_query_arg( [
				'page'     => $page,
				'per_page' => $per_page,
			], $rest_url );

			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) ) {
				error_log( "Failed to fetch therapists on page $page" );
				break;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || empty( $data ) ) {
				error_log( "No more therapists found on page $page" );
				break;
			}

			foreach ( $data as $therapist_data ) {
				if ( $max_items && $processed_items >= $max_items ) {
					$reached_item_limit = true;
					$more_pages = false;
					break;
				}

				$result = $this->import_therapist( (array) $therapist_data );
				if ( isset( $stats[ $result ] ) ) {
					$stats[ $result ]++;
				}
				$processed_items++;
			}

			if ( $more_pages ) {
				if ( count( $data ) < $per_page ) {
					$more_pages = false;
				} else {
					$page++;
				}
			}
		}

		if ( $reached_page_limit ) {
			$this->log( sprintf( 'Stopped after reaching the max page limit (%d).', $max_pages ) );
		}

		if ( $reached_item_limit ) {
			$this->log( sprintf( 'Stopped after reaching the max item limit (%d).', $max_items ) );
		}

		$this->update_existing = false;

		$this->log( sprintf(
			'Finished therapist import. Inserted: %d, Updated: %d, Skipped: %d, Failed: %d',
			$stats['inserted'],
			$stats['updated'],
			$stats['skipped'],
			$stats['failed']
		), $stats['failed'] ? 'warning' : 'success' );
	}

	public function import_therapist( $therapist_data )
	{
		$this->prime_existing_imports();

		$therapist_data = (array) $therapist_data;
		$remote_id = $this->get_remote_id( $therapist_data );

		if ( ! $remote_id ) {
			$this->log( 'Skipping therapist import: missing original ID.', 'warning' );
			return 'failed';
		}

		$lookup_key = (string) $remote_id;
		$existing_post_id = $this->therapists[ $lookup_key ] ?? 0;

		if ( $existing_post_id && ! $this->update_existing ) {
			$this->log( sprintf(
				'Skipping therapist %d — already imported as post %d.',
				$remote_id,
				$existing_post_id
			) );
			return 'skipped';
		}

		$postarr = $this->build_post_array( $therapist_data, $existing_post_id );

		if ( $existing_post_id && $this->update_existing ) {
			$post_id = wp_update_post( $postarr, true );
			$action = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$action = 'inserted';
		}

		if ( is_wp_error( $post_id ) ) {
			$this->log( sprintf(
				'Failed to %s therapist %d: %s',
				$existing_post_id ? 'update' : 'insert',
				$remote_id,
				$post_id->get_error_message()
			), 'error' );
			return 'failed';
		}

		$post_id = (int) $post_id;

		$this->therapists[ $lookup_key ] = $post_id;

		$this->persist_migration_meta( $post_id, $remote_id, $therapist_data );

		$this->log( sprintf(
			'%s therapist %d into post %d.',
			ucfirst( $action ),
			$remote_id,
			$post_id
		) );

		return $action;
	}

	private function prime_existing_imports()
	{
		if ( ! empty( $this->therapists ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}

		$query = $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND p.post_type = %s",
			$this->migrate_id_key,
			$this->post_type
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$old_id = (string) ( $row['meta_value'] ?? '' );
			$new_id = (int) ( $row['post_id'] ?? 0 );

			if ( '' === $old_id || ! $new_id ) {
				continue;
			}

			$this->therapists[ $old_id ] = $new_id;
		}
	}

	private function get_remote_id( array $therapist_data ): int
	{
		$id = $therapist_data['ID'] ?? ( $therapist_data['id'] ?? 0 );
		return absint( $id );
	}

	private function build_post_array( array $therapist_data, int $existing_post_id = 0 ): array
	{
		$title = $this->normalize_field_value( $therapist_data['post_title'] ?? ( $therapist_data['title']['rendered'] ?? '' ) );
		$status = $therapist_data['post_status'] ?? ( $therapist_data['status'] ?? 'draft' );
		$slug = $therapist_data['post_name'] ?? ( $therapist_data['slug'] ?? '' );

		$postarr = [
			'post_title'   => $title ?: sprintf( 'Therapist %d', $this->get_remote_id( $therapist_data ) ),
			'post_status'  => $status ?: 'draft',
			'post_type'    => $this->post_type,
		];

		$post_slug = $slug ?: $postarr['post_title'];
		$postarr['post_name'] = sanitize_title( $post_slug );

		$post_date = $this->normalize_datetime( $therapist_data['post_date'] ?? ( $therapist_data['date'] ?? '' ) );
		if ( $post_date ) {
			$postarr['post_date']    = $post_date;
			$postarr['post_date_gmt'] = get_gmt_from_date( $post_date );
		}

		if ( $existing_post_id ) {
			$postarr['ID'] = $existing_post_id;
		}

		return $postarr;
	}

	private function normalize_field_value( $value ): string
	{
		if ( is_array( $value ) ) {
			if ( isset( $value['rendered'] ) ) {
				$value = $value['rendered'];
			} else {
				$value = '';
			}
		}

		return (string) $value;
	}

	private function normalize_datetime( $value ): string
	{
		if ( empty( $value ) ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			$timestamp = (int) $value;
		} else {
			$timestamp = strtotime( (string) $value );
		}

		if ( ! $timestamp ) {
			return '';
		}

		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
	}

	private function persist_migration_meta( int $post_id, int $remote_id, array $therapist_data ): void
	{
		update_post_meta( $post_id, $this->migrate_id_key, $remote_id );

		update_post_meta( $post_id, '_migrate_data', $therapist_data );
	}

	private function log( string $message, string $type = 'log' ): void
	{
		if ( class_exists( '\WP_CLI' ) ) {
			switch ( $type ) {
				case 'success':
					\WP_CLI::success( $message );
					return;
				case 'warning':
					\WP_CLI::warning( $message );
					return;
				case 'error':
					\WP_CLI::error( $message, false );
					return;
				default:
					\WP_CLI::log( $message );
					return;
			}
		}

		error_log( $message );
	}

}
