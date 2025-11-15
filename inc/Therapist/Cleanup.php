<?php
/**
 * @package  OPPC_Migrate
 */
namespace OppcMigration\Therapist;

class Cleanup
{
	public $post_id;
	public $migrate_data;
	private static $map = null;
	private $state_name_cache = [];
	private $country_name_cache = [];

	function __construct( $post_id )
	{
		$this->post_id = $post_id;
		$this->migrate_data = get_post_meta( $post_id, '_migrate_data', true );
	}

	public function cleanup()
	{
		$data = $this->migrate_data;
		$post_date = get_the_date( 'Y-m-d H:i:s', $this->post_id );

		$is_pending = 'complete' !== ( $data['meta']['application_status'] ?? 'complete' );

		$country = $this->get_country( $data['meta']['office_country'] ?? '' );

		$headshot = $data['meta']['_thumbnail_id'] ?? '';
		if ( $headshot ) {
			$old_headshot_id = (int) $headshot;
			$headshot = $this->find_attachment_by_old_id( $old_headshot_id );
			if ( ! $headshot ) {
				$headshot = $this->import_remote_attachment( $old_headshot_id );
			}
		}

		$start_year = '';
		if ( ! empty( $data['meta']['years_in_practice'] ?? null ) ) {
			$years_in_practice = (int) ( $data['meta']['years_in_practice'] ?? 0 );
			$post_published = get_post_time( 'U', true, $this->post_id );
			$start_year = date( 'Y', strtotime( "-{$years_in_practice} years", $post_published ) );
		}
		
		$supervisor_name = $data['meta']['supervisor'] ?? '';
		$supervisor_first_name = '';
		$supervisor_last_name = '';
		if ( $supervisor_name ) {
			$supervisor_parts = explode( ' ', $supervisor_name );
			$supervisor_first_name = array_shift( $supervisor_parts );
			$supervisor_last_name = implode( ' ', $supervisor_parts );
		}

		$modality_term_map = $this->get_modality_term_map();
		$selected_modalities = array_map( 'intval', (array) $this->get_modality( $data['taxonomies']['modality'] ?? null ) );
		$has_individuals = isset( $modality_term_map['individuals'] ) && in_array( $modality_term_map['individuals'], $selected_modalities, true );
		$has_couples = isset( $modality_term_map['couples'] ) && in_array( $modality_term_map['couples'], $selected_modalities, true );
		$has_families = isset( $modality_term_map['families'] ) && in_array( $modality_term_map['families'], $selected_modalities, true );

		$rate_defaults = $this->get_default_rate_schedule( (int) $country );
		$individual_rates = $rate_defaults['individuals'] ?? ['min' => null, 'max' => null];
		$couples_rates = $rate_defaults['couples'] ?? ['min' => null, 'max' => null];
		$family_rates = $rate_defaults['families'] ?? ['min' => null, 'max' => null];

		$new_data = [
			'preferred_first_name' => $data['user']['meta']['first_name'] ?? '',
			'first_name' => $data['user']['meta']['first_name'] ?? '',
			'last_name' => $data['user']['meta']['last_name'] ?? '',
			'email_address' => $data['user']['user_email'] ?? '',
			'phone_number' => $data['meta']['clinician_phone_number'] ?? '',
			'mission_statement' =>  $data['meta']['abstract_4'] ?? '',
			'personal_statement' => $data['post_content'] ?? '',
			'rate_individual_min' => $has_individuals ? ( $individual_rates['min'] ?? null ) : null,
			'rate_individual_max' => $has_individuals ? ( $individual_rates['max'] ?? null ) : null,
			'rate_couples_min' => $has_couples ? ( $couples_rates['min'] ?? null ) : null,
			'rate_couples_max' => $has_couples ? ( $couples_rates['max'] ?? null ) : null,
			'rate_family_min' => $has_families ? ( $family_rates['min'] ?? null ) : null,
			'rate_family_max' => $has_families ? ( $family_rates['max'] ?? null ) : null,
			'credentials' => $data['meta']['license'] ?? '',
			'educational_certification' => $data['meta']['masters_completed'] ?? 0,
			'masters_diploma' => null, // -- not on old site
			'psypact_number' => null, //  -- not on old site
			'headshot' => $headshot, // image
			'video' => null, // -- not on old site
			'undergraduate_school' => $data['meta']['clinician_school'], // text
			'postgraduate_school' => $data['meta']['clinician_school_post'], // text
			'start_year' => $start_year, // text
			'practice_name' => $data['meta']['practice_name'], // text
			'practice_website' => $data['meta']['clinician_website_url'], // text
			'new_clients' => $data['meta']['new_clients'], // true/false
			'free_consultation' => null, //  -- not on old site
			'supervisor_first_name' => $supervisor_first_name, // text
			'supervisor_last_name' => $supervisor_last_name, // text
			'supervisor_license_number' => null, // text
			'supervisor_email_address' => null, // text
			'graduation_month' => $data['meta']['graduation_month'] ?? null, // number
			'graduation_year' => $data['meta']['graduation_year'] ?? null, // number
			'supervision_contract' => null, // file
			'unofficial_transcript' => null, // file
			'submitted_date' => $post_date,
			'application_started_date' => $post_date,
			'registration_status' => 'complete',
			'account_status' => $is_pending ? 'review' : 'complete',
		];

		// locations
		$location_data = $this->get_locations( $data );
		$locations = $location_data['locations'] ?? [];
		$geocoded_addresses = $location_data['geocoded'] ?? [];
		if ( ! empty( $locations ) ) {
			update_field( 'locations', $locations, $this->post_id );
			$new_data['locations'] = $locations;
		} else {
			update_field( 'locations', [], $this->post_id );
		}

		update_post_meta( $this->post_id, '_geocoded_addresses', $geocoded_addresses );

		// taxonomies
		$new_data = $this->add_taxonomies( $new_data, $data );

		update_post_meta( $this->post_id, 'client_data', $new_data );

		// $this->create_user();
	}

	public function add_taxonomies( $new_data, $data )
	{
		if ( empty( $data['taxonomies'] ) ) {
			return $new_data;
		}

		$country = $this->get_country( $data['meta']['office_country'] ?? '' );

		$license_status = $this->get_license_status( $data['meta']['license_status'] ?? '', $country == 654 );
		if ( $license_status ) {
			$license_status = [$license_status];
		}

		$therapist_type = $this->get_therapist_type( $data['meta']['therapist_type'] ?? '' );
		if ( $therapist_type ) {
			if ( 733 === $therapist_type ) {
				$student_intern_location_type = [];
				$global_online = ! empty( $data['meta']['accepting_teleconferencing'] ) ? 1 : 0;
				if ( $global_online ) {
					$student_intern_location_type[] = 911;
				}
				if ( ! empty( $data['meta']['office_street'] ) ) {
					$student_intern_location_type[] = 910;
				}
			}
			$therapist_type = [$therapist_type];
		}

		$pronouns = $this->get_pronouns( $data['meta']['pronoun'] ?? '', $data['meta']['pronoun_other'] ?? '' );

		$ethnicity = $this->get_ethnicity( $data['taxonomies'] );
		if ( ! empty( $ethnicity ) ) {
			$ethnicity = array_values( array_unique( (array) $ethnicity ) );
		} else {
			$ethnicity = null;
		}

		$modality = $this->get_modality( $data['taxonomies']['modality'] ?? null );

		$age = $this->get_age( $data['taxonomies']['age'] ?? null );

		$languages = $this->get_languages( $data['taxonomies']['languages'] ?? null );

		$taxonomies = [
			'therapist_country' => $country,
			'therapist_referral_source' => [667], // other
			'therapist_referral_source_other' => $data['meta']['other'] ?? '',
			'therapist_license_status' => $license_status,
			'therapist_license_type' => $therapist_type,
			'supervisor_license_type' => null,
			'therapist_license_compact' => null,
			'therapist_modality' => $modality,
			'therapist_payment_method' => null,
			'specialty' => $this->get_specialties( $data['taxonomies']['specialties'] ?? [] ),
			'race' => null,
			'orientation' => $this->get_orientation( $data['taxonomies']['treatment-categories'] ?? [] ),
			'language' => $languages,
			'age' => $age,
			'faith' => null,
			'ethnicity' => $ethnicity,
			'client_state' => null,
			'client_pronouns' => $pronouns['pronouns'] ?? null,
			'client_pronouns_other' => $pronouns['other'] ?? null,
			'client_gender' => null, // new taxonomy
			'therapist_contact_method' => null,
		];

		foreach ( $taxonomies as $field_key => $taxonomy_data ) {
			$new_data[ $field_key ] = $taxonomy_data;
		}

		return $new_data;
	}

	public function get_specialties( $specialties )
	{
		if ( empty( $specialties ) ) {
			return null;
		}

		$new_value = [];

		foreach ( $specialties as $term ) {
			$new_term = get_term_by( 'slug', $term['slug'], 'specialty' );
			if ( $new_term ) {
				$new_value[] = $new_term->term_id;
			}
		}

		if ( empty( $new_value ) ) {
			return null;
		}

		return $new_value;
		
	}

	public function get_orientation( $orientations )
	{
		if ( empty( $orientations ) ) {
			return null;
		}

		$new_value = [];

		foreach ( $orientations as $term ) {
			$new_term = get_term_by( 'slug', $term['slug'], 'orientation' );
			if ( $new_term ) {
				$new_value[] = $new_term->term_id;
			}
		}

		if ( empty( $new_value ) ) {
			return null;
		}

		return $new_value;
		
	}

	public function get_locations( $data )
	{
		$global_online = ! empty( $data['meta']['accepting_teleconferencing'] ) ? 1 : 0;
		$primary_country_name = $this->resolve_country_name(
			$data['meta']['office_country'] ?? '',
			$this->get_country_name_from_term_id( $this->get_country( $data['meta']['office_country'] ?? '' ) )
		);

		$geocoded_addresses = [];
		$locations = [];

		$this->process_office_row( [
			'street'  => $data['meta']['office_street'] ?? null,
			'street2' => $data['meta']['office_street2'] ?? null,
			'city'    => $data['meta']['office_city'] ?? null,
			'state'   => $data['meta']['office_state'] ?? null,
			'zip'     => $data['meta']['office_zip'] ?? null,
			'country' => $data['meta']['office_country'] ?? '',
			'lat'     => $data['meta']['office_lat'] ?? null,
			'lng'     => $data['meta']['office_lng'] ?? null,
		], $primary_country_name, $global_online, $locations, $geocoded_addresses );

		$this->process_office_row( [
			'street'  => $data['meta']['office2_street'] ?? null,
			'street2' => $data['meta']['office2_street2'] ?? null,
			'city'    => $data['meta']['office2_city'] ?? null,
			'state'   => $data['meta']['office2_state'] ?? null,
			'zip'     => $data['meta']['office2_zip'] ?? null,
			'country' => $data['meta']['office2_country'] ?? '',
			'lat'     => $data['meta']['office2_lat'] ?? null,
			'lng'     => $data['meta']['office2_lng'] ?? null,
		], $primary_country_name, $global_online, $locations, $geocoded_addresses );

		$additional_indexes = [];
		foreach ( array_keys( $data['meta'] ?? [] ) as $meta_key ) {
			if ( preg_match( '/^additional_offices_(\d+)_office_/', (string) $meta_key, $matches ) ) {
				$additional_indexes[] = (int) $matches[1];
			}
		}

		$additional_indexes = array_values( array_unique( $additional_indexes ) );
		sort( $additional_indexes );

		foreach ( $additional_indexes as $i ) {
			$office_prefix = "additional_offices_{$i}_office_";
			$this->process_office_row( [
				'street'  => $data['meta'][ "{$office_prefix}street" ] ?? null,
				'street2' => $data['meta'][ "{$office_prefix}street2" ] ?? null,
				'city'    => $data['meta'][ "{$office_prefix}city" ] ?? null,
				'state'   => $data['meta'][ "{$office_prefix}state" ] ?? null,
				'zip'     => $data['meta'][ "{$office_prefix}zip" ] ?? null,
				'country' => $data['meta'][ "{$office_prefix}country" ] ?? '',
				'lat'     => $data['meta'][ "{$office_prefix}lat" ] ?? null,
				'lng'     => $data['meta'][ "{$office_prefix}lng" ] ?? null,
			], $primary_country_name, $global_online, $locations, $geocoded_addresses );
		}

		return [
			'locations' => $locations,
			'geocoded'  => array_values( $geocoded_addresses ),
		];
	}

	public function get_state( $state_code )
	{
		if ( ! $state_code ) {
			return null;
		}

		if ( 'DC' === strtoupper( $state_code ) ) {
			$state_code = 'District of Columbia';
		} else if ( 'PR' === strtoupper( $state_code ) ) {
			$state_code = 'Puerto Rico';
		}

		if ( is_numeric( $state_code ) ) {
			$term = get_term_by( 'id', (int) $state_code, 'client_state' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		$state_code = trim( (string) $state_code );
		if ( '' === $state_code ) {
			return null;
		}

		$lookup_values = array_filter( array_unique( [
			strtolower( $state_code ),
			sanitize_title( $state_code ),
			str_replace( ' ', '-', strtolower( $state_code ) ),
		] ) );

		foreach ( $lookup_values as $value ) {
			$term = get_term_by( 'slug', $value, 'client_state' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		$normalized_name = ucwords( strtolower( $state_code ) );
		$term = get_term_by( 'name', $state_code, 'client_state' );
		if ( ! $term ) {
			$term = get_term_by( 'name', $normalized_name, 'client_state' );
		}

		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}

		$abbr_map = $this->get_state_abbreviation_map();
		$upper_code = strtoupper( $state_code );
		if ( isset( $abbr_map[ $upper_code ] ) ) {
			return $this->get_state( $abbr_map[ $upper_code ] );
		}

		error_log( "State term not found for code: $state_code" );
		return null;
	}

	private function process_office_row( array $args, $primary_country_name, $global_online, array &$locations, array &$geocoded_addresses ): void
	{
		$street    = $args['street'] ?? null;
		$street2   = $args['street2'] ?? null;
		$city      = $args['city'] ?? null;
		$state_raw = $args['state'] ?? null;
		$zip       = $args['zip'] ?? null;
		$country   = $this->resolve_country_name( $args['country'] ?? '', $primary_country_name );
		$lat       = $args['lat'] ?? null;
		$lng       = $args['lng'] ?? null;

		if ( empty( array_filter( [ $street, $street2, $city, $state_raw, $zip ] ) ) ) {
			return;
		}

		$state_term_id = $this->get_state( $state_raw );
		$state_name = $this->get_state_name_from_id( $state_term_id ) ?: $state_raw;

		$has_street = ! empty( $street );
		$is_online_only = ! $has_street && ( ! empty( $city ) || ! empty( $state_raw ) );

		$online_flag = $global_online ? 1 : 0;
		if ( $is_online_only ) {
			$online_flag = 1;
		}

		$geocoded_addresses[] = $this->build_geocoded_row(
			[
				'street'    => $street,
				'city'      => $city,
				'state'     => $state_name,
				'zip'       => $zip,
				'country'   => $country,
				'online'    => $online_flag,
				'in_person' => $has_street ? 1 : 0,
			],
			$lat,
			$lng
		);

		$office_entry = $has_street ? array_filter(
			[
				'street'  => $street,
				'street2' => $street2,
				'city'    => $city,
				'zip'     => $zip,
			],
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		) : null;

		$this->append_office_to_locations( $locations, $state_term_id, $office_entry, $online_flag, $has_street ? 1 : 0 );
	}

	private function append_office_to_locations( array &$locations, ?int $state_id, ?array $office, int $online_flag, int $in_person_flag ): void
	{
		if ( $state_id ) {
			$index = $this->find_location_index( $locations, $state_id );
			if ( null === $index ) {
				$locations[] = [
					'client_state' => $state_id,
					'online'       => 0,
					'in_person'    => 0,
					'licenses'     => [],
					'offices'      => [],
				];
				$index = array_key_last( $locations );
			}
		} else {
			if ( empty( $locations ) ) {
				return;
			}
			$index = 0;
		}

		$locations[ $index ]['online'] = $locations[ $index ]['online'] || $online_flag;
		$locations[ $index ]['in_person'] = $locations[ $index ]['in_person'] || $in_person_flag;

		if ( ! empty( $office ) ) {
			$locations[ $index ]['offices'][] = $office;
			$locations[ $index ]['offices'] = array_values( $locations[ $index ]['offices'] );
		}

		$locations[ $index ]['online'] = $locations[ $index ]['online'] ? 1 : 0;
		$locations[ $index ]['in_person'] = $locations[ $index ]['in_person'] ? 1 : 0;
	}

	private function find_location_index( array $locations, int $state_id ): ?int
	{
		foreach ( $locations as $index => $location ) {
			if ( (int) ( $location['client_state'] ?? 0 ) === $state_id ) {
				return $index;
			}
		}
		return null;
	}

	private function get_state_abbreviation_map(): array
	{
		return [
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
			'AB' => 'Alberta',
			'BC' => 'British Columbia',
			'MB' => 'Manitoba',
			'NB' => 'New Brunswick',
			'NL' => 'Newfoundland and Labrador',
			'NS' => 'Nova Scotia',
			'NT' => 'Northwest Territories',
			'NU' => 'Nunavut',
			'ON' => 'Ontario',
			'PE' => 'Prince Edward Island',
			'QC' => 'Quebec',
			'SK' => 'Saskatchewan',
			'YT' => 'Yukon',
		];
	}

	public function get_languages( $languages )
	{
		if ( ! $languages ) {
			return null;
		}

		$new_terms = [
			'American Sign Language' => 153,
			'Arabic' => 154,
			'Armenian' => 340,
			'Bosnian' => 341,
			'Cantonese' => 342,
			'Croatian' => 343,
			'Dutch' => 344,
			'English' => 345,
			'Farsi (Persian)' => 346,
			'Fillipino (Tagalog)' => 347,
			'French' => 348,
			'German' => 349,
			'Greek' => 350,
			'Gujarati' => 351,
			'Haitian Creole' => 149,
			'Hebrew' => 352,
			'Hindi' => 353,
			'Hungarian' => 354,
			'Indonesian' => 146,
			'Italian' => 355,
			'Japanese' => 356,
			'Kannada' => 151,
			'Korean' => 357,
			'Malayalam' => 148,
			'Mandarin (Chinese)' => 358,
			'Marathi' => 359,
			'Pashto' => 152,
			'Polish' => 360,
			'Portuguese' => 361,
			'Punjabi' => 362,
			'Romanian' => 363,
			'Russian' => 364,
			'Serbian' => 365,
			'Singales' => 366,
			'Somali' => 145,
			'Spanish' => 367,
			'Swahili' => 368,
			'Swedish' => 369,
			'Tamil' => 150,
			'Thai' => 147,
			'Turkish' => 370,
			'Ukrainian' => 371,
			'Urdu' => 372,
			'Vietnamese' => 373,
			'Yiddish' => 374,
		];

		$new_value = [];

		foreach ( $languages as $term ) {
			if ( isset( $new_terms[ trim( $term['name'] ) ] ) ) {
				$new_value[] = $new_terms[ trim( $term['name'] ) ];
			}
		}

		if ( empty( $new_value ) ) {
			return null;
		}

		return $new_value;
	}

	public function get_age( $age )
	{
		if ( ! $age ) {
			return null;
		}

		$new_terms = [
			'Adolescents / Teenagers (14-19)' => 160,
			'Adults (20-64)' => 159,
			'Children (6-10)' => 155,
			'Elders (65+)' => 156,
			'Preteens / Tweens (11-13)' => 157,
			'Toddlers / Preschoolers (0-5)' => 158,
		];

		$new_value = [];

		foreach ( $age as $term ) {
			switch( $term['name'] ) {
				case 'Adolescents / Teenagers (14 to 19)':
					$new_value[] = $new_terms['Adolescents / Teenagers (14-19)'];
					break;
				case 'Adults':
				case 'Adults (21-65)':
					$new_value[] = $new_terms['Adults (20-64)'];
					break;
				case 'Children (6 to 10)':
					$new_value[] = $new_terms['Children (6-10)'];
					break;
				case 'Elders (65+)':
					$new_value[] = $new_terms['Elders (65+)'];
					break;
				case 'Preteens / Tweens (11 to 13)':
					$new_value[] = $new_terms['Preteens / Tweens (11-13)'];
					break;
				case 'Toddlers / Preschoolers (0 to 6)':
					$new_value[] = $new_terms['Toddlers / Preschoolers (0-5)'];
					break;
			}
		}

		return $new_value;
	}

	public function get_modality( $modality )
	{
		if ( ! $modality ) {
			return null;
		}

		$term_map = $this->get_modality_term_map();
		$new_modality = [];

		if ( is_array( $modality ) ) {
			foreach ( $modality as $value ) {
				$key = $this->normalize_modality_key( $value['name'] ?? '' );
				if ( $key && isset( $term_map[ $key ] ) ) {
					$new_modality[] = $term_map[ $key ];
				}
			}
		}

		return $new_modality;
	}

	public function get_ethnicity( $taxonomies ) {
		$races = $taxonomies['races'] ?? [];
		$ethnicity = $taxonomies['ethnicity'] ?? [];
		$all = array_filter( array_merge( (array) $races, (array) $ethnicity ) );

		$new_terms = [
			'Asian or Asian American' => 167,
			'Black or African American' => 166,
			'Hispanic or Latino' => 864,
			'Middle Eastern or North African' => 865,
			'Multiracial or Multiethnic' => 866,
			'Native American, Indigenous, or Alaska Native' => 867,
			'Native Hawaiian or other Pacific Islander' => 868,
			'Prefer not to answer' => 871,
			'South Asian or South Asian American' => 870,
			'White' => 869,
		];

		$new_ethnicity = [];
		if ( ! empty( $all ) ) {
			foreach ( $all as $item ) {
				switch ( $item['name'] ) {
					case 'Black or African American':
					case 'African-American':
						$new_ethnicity[] = $new_terms['Black or African American'];
						break;
					case 'Asian':
						$new_ethnicity[] = $new_terms['Asian or Asian American'];
						break;
					case 'Caucasian':
					case 'White or European American':
						$new_ethnicity[] = $new_terms['White'];
						break;
					case 'Latin/Latinx/Latina/Latino/Hispanic':
					case 'Latino':
						$new_ethnicity[] = $new_terms['Hispanic or Latino'];
						break;
					case 'Native American':
					case 'Native American or Alaska Native':
						$new_ethnicity[] = $new_terms['Native American, Indigenous, or Alaska Native'];
						break;
					case 'Pacific Islander':
					case 'Native Hawaiian or other Pacific Islander':
						$new_ethnicity[] = $new_terms['Native Hawaiian or other Pacific Islander'];
						break;
					case 'South Asian':
						$new_ethnicity[] = $new_terms['South Asian or South Asian American'];
						break;
					case 'Middle Eastern':
						$new_ethnicity[] = $new_terms['Middle Eastern or North African'];
						break;
				}
			}
		}

		return $new_ethnicity;
	}

	public function get_pronouns( $pronouns, $other )
	{
		if ( empty( $pronouns ) && empty( $other ) ) {
			return [
				'pronouns' => null,
				'other' => null,
			];
		}

		$pronouns = maybe_unserialize( $pronouns );

		$new_options = [
			'he/him' => 455,
			'she/her' => 456,
			'he/they' => 457,
			'she/they' => 458,
			'they/them' => 459,
			'ze/hir' => 460,
			'xe/xem' => 461,
			'ver/vir' => 462,
			'the/tem' => 463,
			'e/em' => 464,
			'she/her/ella' => 465,
			'he/him/él' => 466,
			'they/them/elle' => 467,
			'they/them/ellx' => 468,
			'Prefer to self-describe (text field)' => 469,
			'Prefer not to answer' => 470
		];

		$pronouns_new = [];
		foreach ( (array) $pronouns as $pronoun ) {
			switch( $pronoun ) {
				case 'Please do not include in my profile':
					$pronouns_new[] = 470;
					break;
				case 'other':
					$pronouns_new[] = 469;
					break;
				case 'he/him':
					$pronouns_new[] = 455;
					break;
				case 'she/her':
					$pronouns_new[] = 456;
					break;
				case 'he/they':
					$pronouns_new[] = 457;
					break;
				case 'she/they':
					$pronouns_new[] = 458;
					break;
				case 'they/them':
					$pronouns_new[] = 459;
					break;
			}
		}

		return [
			'pronouns' => $pronouns_new,
			'other' => $other,
		];
	}

	public function get_therapist_type( $type )
	{
		if ( ! $type || ! is_string( $type ) ) {
			return null;
		}

		$new_terms = [
			"Art Therapist" => 703,
			"Associate Marriage and Family Therapist" => 704,
			"Associate Professional Clinical Counselor" => 705,
			"Associate Professional Counselor" => 706,
			"Associate Social Worker" => 707,
			"Behavior Analyst" => 708,
			"Clinical Social Worker/Therapist" => 709,
			"Counselor" => 710,
			"Creative Arts Therapist" => 711,
			"Drug & Alcohol Counselor/Therapist" => 712,
			"Independent Marriage and Family Therapist" => 713,
			"Licensed Master Social Worker" => 714,
			"Licensed Mental Health Counselor" => 715,
			"Licensed Mental Health Counselor Associate" => 716,
			"Licensed Psychoanalyst" => 717,
			"Limited License Psychology" => 718,
			"Marriage & Family Therapist" => 719,
			"National Certified Counselor" => 720,
			"Occupational Therapist" => 721,
			"Pastoral Counselor/Therapist" => 722,
			"Pre-Licensed Professional" => 723,
			"Provisionally Licensed Counselor" => 728,
			"Psychiatric Nurse/Therapist" => 724,
			"Psychologist" => 725,
			"Psychologist Associate" => 726,
			"Psychotherapist" => 727,
			"Registered Psychotherapist" => 729,
			"Registered Psychotherapist Qualifying" => 730,
			"Registered Social Worker" => 731,
			"School Psychologist" => 732,
			"Student Intern" => 733
		];


		switch( trim( $type ) ) {
			case 'Marriage & Family Therapist':
				return $new_terms['Marriage & Family Therapist'];
				break;
			case 'Counselor':
				return $new_terms['Counselor'];
				break;
			case 'Licensed Psychoanalyst':
				return $new_terms['Licensed Psychoanalyst'];
				break;
			case 'Clinical Social Worker/Therapist':
				return $new_terms['Clinical Social Worker/Therapist'];
				break;
			case 'Psychologist':
				return $new_terms['Psychologist'];
				break;
			case 'Psychotherapist':
				return $new_terms['Psychotherapist'];
				break;
			case 'Pre-Licensed Professional':
				return $new_terms['Pre-Licensed Professional'];
				break;
			case 'Licensed Mental Health Counselor':
				return $new_terms['Licensed Mental Health Counselor'];
				break;
			case 'Art Therapist':
				return $new_terms['Art Therapist'];
				break;
			case 'Licensed Professional Counselor':
				// ??
				break;
			case 'Pastoral Counselor/Therapist':
				return $new_terms['Pastoral Counselor/Therapist'];
				break;
			case 'Treatment Facility':
				// ??
				break;
			case 'Drug & Alcohol Counselor/Therapist':
				return $new_terms['Drug & Alcohol Counselor/Therapist'];
				break;
			case 'Associate Marriage and Family Therapist':
				return $new_terms['Associate Marriage and Family Therapist'];
				break;
			case 'Provisionally Licensed Counselor':
				return $new_terms['Provisionally Licensed Counselor'];
				break;
			case 'Limited License Psychology':
				return $new_terms['Limited License Psychology'];
				break;
			case 'Creative Arts Therapist':
				return $new_terms['Creative Arts Therapist'];
				break;
			case 'Marriage & Family Therapist Intern':
				// ??
				break;
			case 'Other':
				// ??
				break;
			case 'Psychiatric Nurse/Therapist':
				return $new_terms['Psychiatric Nurse/Therapist'];
				break;
			case 'Licensed Marriage and Family Therapist Associate':
				// ??
				break;
			case 'Psychologist Associate':
				return $new_terms['Psychologist Associate'];
				break;
			case 'Associate Counselor':
				// ??
				break;
			case 'Licensed Marriage and Family Therapist':
				// ??
				break;
			case 'National Certified Counselor':
				return $new_terms['National Certified Counselor'];
				break;
			case 'Associate Professional Counselor':
				return $new_terms['Associate Professional Counselor'];
				break;
			case 'Licensed Master Social Worker':
				return $new_terms['Licensed Master Social Worker'];
				break;
			case 'Licensed Clinical Social Worker':
				// ??
				break;
			case 'School Psychologist':
				return $new_terms['School Psychologist'];
				break;
			case 'Licensed Professional Counselor Associate':
				// ??
				break;
			case 'Associate Professional Clinical Counselor':
				return $new_terms['Associate Professional Clinical Counselor'];
				break;
			case 'Occupational Therapist':
				return $new_terms['Occupational Therapist'];
				break;
			case 'Associate Social Worker':
				return $new_terms['Associate Social Worker'];
				break;
			case 'Registered Social Worker':
				return $new_terms['Registered Social Worker'];
				break;
			case 'Licensed Mental Health Counselor Associate':
				return $new_terms['Licensed Mental Health Counselor Associate'];
				break;
			case 'Behavior Analyst':
				return $new_terms['Behavior Analyst'];
				break;
			case 'Student Intern':
				return $new_terms['Student Intern'];
				break;
			case 'Registered Psychotherapist':
				return $new_terms['Registered Psychotherapist'];
				break;
			case 'Registered Psychotherapist Qualifying':
				return $new_terms['Registered Psychotherapist Qualifying'];
				break;
			case 'Independent Marriage and Family Therapist':
				return $new_terms['Independent Marriage and Family Therapist'];
				break;
		}
	}

	public function get_license_status( $status, $us )
	{
		if ( ! $status ) {
			return null;
		}

		switch( trim( $status ) ) {
			case 'Student Intern':
				return 671; // student intern
			case 'Fully licensed':
				return 668; // fully licensed
			case 'Pre-licensed':
			case 'Pre-licensed professional':
			case 'Pre licensed':
			case 'Pre-licensed / Supervisee license':
				return 670; // pre-licensed
			case 'Provisional license':
			case 'Associate/intern license':
			case 'Associate':
			case 'Associate license':
			case 'Intern/Associate':
			case 'Licensed associate':
			case 'Intern license':
			case 'Associates':
			case 'Provisionally licensed':
				if ( ! $us ) {
					return 863; // provisional/qualifying license (CA)
				} else {
					return 669; // Provisionally licensed (associate, intern) - US
				}
			default:
				return null;
		}
	}

	public function get_country( $country_code )
	{
		if ( ! $country_code ) {
			return null;
		}

		$us_term_id = 654;
		$canada_term_id = 653;

		switch( $country_code ) {
			case 'US':
			case 'United States':
				return $us_term_id;
				break;
			case 'CA':
			case 'Canada':
				return $canada_term_id;
			default:
				return null;
		}

		return $country->name;
	}

	private function get_modality_term_map(): array
	{
		return [
			'individuals' => 734,
			'couples'     => 735,
			'families'    => 736,
		];
	}

	private function normalize_modality_key( $value ): ?string
	{
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return null;
		}

		$aliases = [
			'individual'         => 'individuals',
			'individuals'        => 'individuals',
			'individual therapy' => 'individuals',
			'individual counseling' => 'individuals',
			'couple'             => 'couples',
			'couples'            => 'couples',
			'couples therapy'    => 'couples',
			'family'             => 'families',
			'families'           => 'families',
			'family therapy'     => 'families',
		];

		return $aliases[ $value ] ?? null;
	}

	private function get_default_rate_schedule( int $country = 0 ): array
	{
		$is_us = (int) $country === 654;

		return [
			'individuals' => [
				'min' => $is_us ? 40 : 50,
				'max' => $is_us ? 70 : 90,
			],
			'couples' => [
				'min' => $is_us ? 40 : 50,
				'max' => $is_us ? 80 : 100,
			],
			'families' => [
				'min' => $is_us ? 40 : 50,
				'max' => $is_us ? 80 : 100,
			],
		];
	}

	private function get_country_name_from_term_id( $term_id )
	{
		if ( ! $term_id ) {
			return null;
		}

		if ( isset( $this->country_name_cache[ $term_id ] ) ) {
			return $this->country_name_cache[ $term_id ];
		}

		$term = get_term_by( 'id', $term_id, 'therapist_country' );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->country_name_cache[ $term_id ] = $term->name;
			return $term->name;
		}

		return null;
	}

	private function get_state_name_from_id( $term_id )
	{
		if ( ! $term_id ) {
			return null;
		}

		if ( isset( $this->state_name_cache[ $term_id ] ) ) {
			return $this->state_name_cache[ $term_id ];
		}

		$term = get_term_by( 'id', $term_id, 'client_state' );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->state_name_cache[ $term_id ] = $term->name;
			return $term->name;
		}

		return null;
	}

	private function resolve_country_name( $country_value, $fallback = null )
	{
		$country_value = is_string( $country_value ) ? trim( $country_value ) : '';
		if ( '' !== $country_value ) {
			$term_id = $this->get_country( $country_value );
			if ( $term_id ) {
				$term_name = $this->get_country_name_from_term_id( $term_id );
				if ( $term_name ) {
					return $term_name;
				}
			}

			return $country_value;
		}

		return $fallback;
	}

	private function build_geocoded_row( array $address, $lat = null, $lng = null )
	{
		$defaults = [
			'street'    => null,
			'city'      => null,
			'state'     => null,
			'zip'       => null,
			'country'   => null,
			'online'    => 0,
			'in_person' => 0,
		];

		$row = array_merge( $defaults, $address );

		$has_lat = is_numeric( $lat );
		$has_lng = is_numeric( $lng );

		if ( $has_lat && $has_lng ) {
			$row['lat'] = (float) $lat;
			$row['lng'] = (float) $lng;
			if ( empty( $row['precision'] ) ) {
				$row['precision'] = 'provided';
			}
			return $row;
		}

		if ( ! empty( $row['street'] ) && ! empty( $row['city'] ) && ! empty( $row['state'] ) ) {
			if ( function_exists( 'oppc_geocode' ) ) {
				$geocoder = oppc_geocode();
				if ( $geocoder && method_exists( $geocoder, 'geocode_address' ) ) {
					$geocoded = $geocoder->geocode_address( $row, $this->post_id );
					if ( $geocoded && ! is_wp_error( $geocoded ) ) {
						$row = array_merge( $row, $geocoded );
					}
				}
			}
		}

		return $row;
	}

	private function import_remote_attachment( int $old_id ): ?int {
		if ( $old_id <= 0 ) {
			return null;
		}

		$endpoint = sprintf( 'https://oppc-old.lndo.site/wp-json/wp/v2/media/%d', $old_id );
		$endpoint = add_query_arg( [
			'_fields' => 'id,date,date_gmt,slug,status,title,caption,description,alt_text,media_details,source_url,post,mime_type',
		], $endpoint );

		$response = wp_remote_get( $endpoint, [
			'timeout' => 20,
			'headers' => [
				'Accept' => 'application/json',
				'User-Agent' => 'OPPC-Media-Fetch/1.0',
			],
		] );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( 'Failed to fetch remote attachment %d: %s', $old_id, $response->get_error_message() ) );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( sprintf( 'Failed to fetch remote attachment %d: HTTP %d', $old_id, $code ) );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$media = json_decode( $body, true );
		if ( ! is_array( $media ) ) {
			error_log( sprintf( 'Failed to parse remote attachment payload for %d.', $old_id ) );
			return null;
		}

		$source_url = (string) ( $media['source_url'] ?? '' );
		$mime_type  = (string) ( $media['mime_type'] ?? '' );

		if ( '' === $source_url || '' === $mime_type ) {
			error_log( sprintf( 'Remote attachment %d missing source URL or mime type.', $old_id ) );
			return null;
		}

		$title   = wp_strip_all_tags( $media['title']['rendered'] ?? '' );
		$caption = wp_strip_all_tags( $media['caption']['rendered'] ?? '' );
		$content = (string) ( $media['description']['rendered'] ?? '' );
		$slug    = sanitize_title( $media['slug'] ?? '' );
		$post_date = ! empty( $media['date'] ) ? (string) $media['date'] : current_time( 'mysql' );
		$post_date_gmt = ! empty( $media['date_gmt'] ) ? (string) $media['date_gmt'] : get_gmt_from_date( $post_date );

		$postarr = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => $title !== '' ? $title : basename( parse_url( $source_url, PHP_URL_PATH ) ?: '' ),
			'post_content'   => $content,
			'post_excerpt'   => $caption,
			'post_name'      => $slug,
			'post_author'    => get_current_user_id() ?: 0,
			'post_mime_type' => $mime_type,
			'post_date'      => $post_date,
			'post_date_gmt'  => $post_date_gmt,
			'guid'           => $source_url,
		];

		$inserted = wp_insert_post( $postarr, true );
		if ( is_wp_error( $inserted ) ) {
			error_log( sprintf( 'Failed to insert remote attachment %d: %s', $old_id, $inserted->get_error_message() ) );
			return null;
		}

		$attachment_id = (int) $inserted;

		$remote_path = ltrim( (string) parse_url( $source_url, PHP_URL_PATH ), '/' );
		if ( '' !== $remote_path ) {
			update_post_meta( $attachment_id, '_wp_attached_file', $remote_path );
		}

		$media_details = is_array( $media['media_details'] ?? null ) ? $media['media_details'] : [];
		$metadata = $this->normalize_remote_media_metadata( $media_details, $source_url, $mime_type );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );

		$alt_text = wp_strip_all_tags( $media['alt_text'] ?? '' );
		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		} else {
			delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
		}

		update_post_meta( $attachment_id, '_migrate_id', $old_id );
		update_post_meta( $attachment_id, '_migrate_url', $source_url );
		update_post_meta( $attachment_id, '_migrate_parent_id', (int) ( $media['post'] ?? 0 ) );
		update_post_meta( $attachment_id, '_migrate_data', $media_details );

		if ( null !== self::$map ) {
			self::$map[ $old_id ] = $attachment_id;
		}

		return $attachment_id;
	}

	private function normalize_remote_media_metadata( array $media_details, string $source_url, string $mime_type ): array {
		$file_path = isset( $media_details['file'] ) ? (string) $media_details['file'] : ltrim( (string) parse_url( $source_url, PHP_URL_PATH ), '/' );

		$metadata = [
			'width'      => isset( $media_details['width'] ) ? (int) $media_details['width'] : 0,
			'height'     => isset( $media_details['height'] ) ? (int) $media_details['height'] : 0,
			'file'       => $file_path,
			'filesize'   => isset( $media_details['filesize'] ) ? (int) $media_details['filesize'] : null,
			'sizes'      => [],
			'image_meta' => isset( $media_details['image_meta'] ) && is_array( $media_details['image_meta'] ) ? $media_details['image_meta'] : [],
		];

		if ( ! empty( $media_details['sizes'] ) && is_array( $media_details['sizes'] ) ) {
			foreach ( $media_details['sizes'] as $name => $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}

				$metadata['sizes'][ $name ] = [
					'file'      => isset( $size['file'] ) ? (string) $size['file'] : basename( parse_url( $size['source_url'] ?? '', PHP_URL_PATH ) ?: '' ),
					'width'     => isset( $size['width'] ) ? (int) $size['width'] : 0,
					'height'    => isset( $size['height'] ) ? (int) $size['height'] : 0,
					'mime-type' => isset( $size['mime_type'] ) ? (string) $size['mime_type'] : $mime_type,
				];

				if ( ! empty( $size['source_url'] ) ) {
					$metadata['sizes'][ $name ]['remote_source_url'] = (string) $size['source_url'];
				}

				if ( isset( $size['filesize'] ) ) {
					$metadata['sizes'][ $name ]['filesize'] = (int) $size['filesize'];
				}
			}
		}

		return $metadata;
	}

	// public function create_user()
	// {
	// 	$post_id = $this->post_id;
	// 	if ( ! $post_id ) {
	// 		return;
	// 	}

	// 	$data = get_post_meta( $post_id, '_migrate_data_all', true );

	// 	$author_id = (int) ( $data['user']['ID'] ?? 0 );
	// 	if ( ! $author_id ) {
	// 		return;
	// 	}

	// 	$email = $data['user']['user_email'] ?? '';
	// 	$first = $data['user']['meta']['first_name'] ?? '';
	// 	$last = $data['user']['meta']['last_name'] ?? '';

	// 	if ( ! is_email( $email ) ) {
	// 		return;
	// 	}

	// 	if ( email_exists( $email ) ) {
	// 		return;
	// 	}

	// 	$username = $email;
	// 	if ( username_exists( $username ) ) {
	// 		$username .= '_' . $post_id;
	// 	}

	// 	$password = wp_generate_password();

	// 	$user_id = wp_create_user( $username, $password, $email);
	// 	if ( is_wp_error( $user_id ) ) {
	// 		error_log( "Failed to create user for client ID {$post_id}: " . $user_id->get_error_message() );
	// 		return;
	// 	}

	// 	wp_update_user([
	// 		'ID'         => $user_id,
	// 		'first_name' => $first,
	// 		'last_name'  => $last,
	// 		'role' => 'subscriber',
	// 	]);

	// 	update_user_meta( $user_id, '_oppc_post_id', $post_id );

	// 	update_post_meta( $post_id, '_oppc_user_id', $user_id );

	// 	// delete_post_meta( $post_id, '_geocoded_addresses' );

	// 	print "Created user for therapist: " . get_the_title( $post_id ) . " (ID: $post_id) with author ID $author_id\n";
	// }

	// public function find_attachment_by_old_id( $old_id ) {
	// 	$args = [
	// 		'post_type'      => 'attachment',
	// 		'post_status'    => 'inherit',
	// 		'posts_per_page' => 1,
	// 		'meta_query'     => [
	// 			[
	// 				'key'   => '_migrate_id',
	// 				'value' => intval( $old_id ),
	// 			],
	// 		],
	// 	];

	// 	$query = new \WP_Query( $args );
	// 	if ( $query->have_posts() ) {
	// 		return $query->posts[0]->ID;
	// 	}

	// 	return null;
	// }

	public static function preload(): void {
		if (self::$map !== null) return;

		print 'Preloading attachment ID map...' . "\n";

		global $wpdb;
		// One query for all attachments that have _migrate_id
		$rows = $wpdb->get_results("
			SELECT
			CAST(pm.meta_value AS UNSIGNED) AS old_id,
			pm.post_id AS attachment_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_migrate_id' AND p.post_type = 'attachment'
		", ARRAY_A);

		$map = [];
		foreach ($rows as $r) {
			$old = (int)$r['old_id'];
			if ($old > 0) {
				$map[$old] = (int)$r['attachment_id'];
			}
		}
		self::$map = $map;
		// Optional: free $rows memory early
		unset($rows);

		print 'Preloaded ' . count(self::$map) . " attachment IDs.\n";
	}

	public static function find_attachment_by_old_id($old_id): ?int {
		if (self::$map === null) self::preload();
		$old_id = (int)$old_id;
		return self::$map[$old_id] ?? null;
	}
}
