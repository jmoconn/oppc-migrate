<?php
/**
 * @package  OPPC_Migrate
 */
namespace OppcMigration\Client;

class Import
{
	private $existing_clients = [];
	private $clients_primed = false;
	private $post_type = 'client';
	private $migrate_id_key = '_migrate_id';
	private $update_existing = true;

	public function import( $args = [], $assoc_args = [] )
	{
		$page = $args['page'] ?? 1;
		$max_pages = $args['max_pages'] ?? 30000;
		$max_items = isset( $assoc_args['max_items'] ) ? max( 1, absint( $assoc_args['max_items'] ) ) : 0;
		$total_pages = 1;
		$this->update_existing = array_key_exists( 'update', $args ) ? wp_validate_boolean( $args['update'] ) : true;

		$this->prime_existing_imports();
		
		$processed_items = 0;
		$reached_item_limit = false;
		
		do {
			if ( $max_items && $processed_items >= $max_items ) {
				$reached_item_limit = true;
				break;
			}

			$url = 'https://oppc-old.lndo.site/wp-json/custom/v1/clients/';
			$url = add_query_arg( [
				'page'     => $page,
				'per_page' => 100,
			], $url );
	
			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) ) {
				error_log( "Failed to fetch client data on page $page" );
				return;
			}
	
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) ) {
				error_log( "Failed to decode client data on page $page" );
				return;
			}
	
			$clients       = $data['data'] ?? [];
			$total_pages   = $data['total_pages'] ?? 1;
			$current_page  = $data['current_page'] ?? $page;

			if ( ! empty( $clients ) ) {
				foreach ( $clients as $client ) {
					if ( $max_items && $processed_items >= $max_items ) {
						$reached_item_limit = true;
						break;
					}

					$this->import_client( $client );
					$processed_items++;
				}
			}
	
			$page++;
		} while ( $page <= $total_pages &&  $page <= $max_pages );

		if ( $reached_item_limit ) {
			printf( "Stopped client import after reaching the max item limit (%d).\n", $max_items );
		}
	}

	public function import_client( $client_data )
	{
		$this->prime_existing_imports();

		if ( empty( $client_data['id'] ) ) {
			error_log( "Client data missing ID" );
			return;
		}

		$existing_post = $this->get_existing_post( $client_data['id'] );
		if ( $existing_post && ! $this->update_existing ) {
			print "Skipped client import: ID {$client_data['id']} already exists as post {$existing_post}\n";
			return;
		}

		$args = [
			'post_title' => trim( $client_data['fname'] .  ' ' . $client_data['lname'] ),
			'post_type' => 'client',
			'post_status' => 'publish',
			'meta_input' => [
				'_migrate_id' => (string) $client_data['id'],
				'_migrate_data' => $client_data,
			],
		];

		$date = $this->normalize_date( $client_data['submitted'] ?? $client_data['paid'] ?? null );
		if (  $date ) {
			$args['post_date'] = date( 'Y-m-d H:i:s', strtotime( $date ) );
			$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $date ) );
		}

		$action = "Imported";

		if ( $existing_post ) {
			$args['ID'] = $existing_post;
			$action = "Updated";
		}

		$post_id = wp_insert_post( $args, true );

		if ( is_wp_error( $post_id ) ) {
			error_log( "Failed to create client post: " . $post_id->get_error_message() );
			return;
		}

		$this->existing_clients[ (string) $client_data['id'] ] = (int) $post_id;

		print "$action client: ID: {$client_data['id']}\n";
	}

	public function normalize_date( $input ) {
		if ( empty( $input ) ) {
			return false;
		}
		// If input is a numeric string or int, assume it's a Unix timestamp
		if ( is_numeric( $input ) && (int)$input == $input ) {
			// Unix timestamps are usually 10 digits
			return date( 'Y-m-d H:i:s', (int)$input );
		}
	
		// Try parsing as MM-DD-YYYY explicitly
		$dt = \DateTime::createFromFormat( 'm-d-Y', $input );
		if ( $dt && $dt->format( 'm-d-Y' ) === $input ) {
			return $dt->format( 'Y-m-d H:i:s' );
		}
	
		// Fallback to strtotime() for anything else (e.g., ISO format)
		$timestamp = strtotime( $input );
		if ( $timestamp !== false ) {
			return date( 'Y-m-d H:i:s', $timestamp );
		}
	
		// Invalid format
		return false;
	}

	private function prime_existing_imports(): void
	{
		if ( $this->clients_primed ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			$this->clients_primed = true;
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
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$remote_id = (string) ( $row['meta_value'] ?? '' );
				$post_id   = (int) ( $row['post_id'] ?? 0 );

				if ( '' === $remote_id || ! $post_id ) {
					continue;
				}

				$this->existing_clients[ $remote_id ] = $post_id;
			}
		}

		$this->clients_primed = true;
	}

	public function get_existing_post( $migrate_id )
	{
		$this->prime_existing_imports();

		$migrate_id = (string) $migrate_id;
		if ( '' === $migrate_id ) {
			return 0;
		}

		return $this->existing_clients[ $migrate_id ] ?? 0;
	}
}
