<?php
/**
 * @package  OPPC_Migrate
 */
namespace OppcMigration\Client;

class Cleanup
{
	public $post_id;
	public $migrate_data;

	function __construct( $post_id )
	{
		$this->post_id = $post_id;
		$this->migrate_data = get_post_meta( $post_id, '_migrate_data', true );
	}

	public function cleanup()
	{
		$client_id = $this->post_id;

		$client_data = get_post_meta( $client_id, '_migrate_data', true );
		if ( empty( $client_data ) ) {
			print "No migration data found for client ID $client_id.\n";
			return;
		}

		$referral = trim( ( $client_data['ref'] ?? '' ) . ' ' . ( $client_data['ref_details'] ?? '' ) ); // concatenate the two fields
		$referral = $this->get_referral( $referral );
		$modality = $this->get_modality( $client_data['modality'] ?? '' );
		$ethnicity = $this->get_ethnicity( $client_data['ethnicity'] ?? '' );
		$insurance = $this->get_insurance( $client_data['insurance'] ?? '' );
		$income = $this->get_income( $client_data['income'] ?? '' );
		$family_contributions = $this->get_family_contributions( $client_data['familyContribute'] ?? '' );
		$family_support = $this->get_family_support( $client_data['familySupport'] ?? '' );
		$income = $this->get_income( $client_data['income'] ?? '' );
		$housing = $this->get_housing( $client_data['financial_housing'] ?? '' );
		$car = $this->get_car( $client_data['financial_car'] ?? '' );
		$school = $this->get_school( $client_data['financial_school'] ?? '' );
		$child = $this->get_child( $client_data['financial_child'] ?? '' );
		$credit_payment = $this->get_credit_payment( $client_data['financial_credit_payment'] ?? '' );
		$credit_debt = $this->get_credit_debt( $client_data['financial_credit_debt'] ?? '' );
		$other_debt = $this->get_other_debt( $client_data['financial_other'] ?? '' );
		$pronouns = $this->get_pronouns( $client_data['pronoun'] ?? '' );

		$therapist = $this->get_therapist( $client_data['therapist'] ?? '' );

		$status = $this->get_account_status( $client_data );

		$country = $this->get_country( $client_data['country'] ?? '' );
		$state = $this->get_state( $client_data['state'] ?? '' );

		$birth_date = $client_data['birth'] ?? '';  // I think we should keep date

		$paid_date = $client_data['paid'] ?? null;
		$submitted_date = $client_data['submitted'] ?? null;

		$submitted_date_string = null;
		if ( $paid_date ) {
			$tz = new \DateTimeZone('America/New_York');
			$dt = \DateTime::createFromFormat('!m-d-Y', $paid_date, $tz);
			if ( $dt ) {
				$paid_date = $dt->format('Y-m-d H:i:s');
				$submitted_date_string = $paid_date;
			}
		}
		if ( $submitted_date ) {
			$tz = new \DateTimeZone('America/New_York');
			$dt = \DateTime::createFromFormat('!m-d-Y', $submitted_date, $tz);
			if ( $dt ) {
				$submitted_date = $dt->format('Y-m-d H:i:s');
				$submitted_date_string = $submitted_date;
			}
			
		}

		$data = [
			'member_id' => $client_data['member_id'] ?? '',
			'preferred_first_name' => $client_data['fname'] ?? '',
			'first_name' => $client_data['fname'] ?? '',
			'last_name' => $client_data['lname'] ?? '',
			'email_address' => $client_data['email'] ?? '',
			'phone_number' => $client_data['phone'] ?? '',
			'client_referral_source' => $referral['referral'],
			'referral_source_other' => $referral['referral_other'] ?? '',
			'birth_date' => $birth_date,
			'client_pronouns' => $pronouns['pronouns'] ?? '',
			'pronouns_other' => $pronouns['pronouns_other'] ?? '',
			'client_gender' => '', // no existing data
			'client_modality' => $modality,
			'client_ethnicity' => $ethnicity['ethnicity'],
			'client_ethnicity_other' => $ethnicity['ethnicity_other'] ?? '',
			'client_insurance_status' => $insurance['insurance'],
			'client_insurance_status_other' => $insurance['insurance_other'],
			'street_address' => ( $client_data['street'] ?? '' ) . ' ' . ( $client_data['street2'] ?? '' ),
			'city' => $client_data['city'] ?? '',
			'client_state' => $state,
			'zip_code' => $client_data['zip'] ?? '',
			'client_country' => $country,
			'client_household_income' => $income,
			'family_contribution' => $family_contributions,
			'family_support' => $family_support,
			'client_housing_payment' => $housing,
			'client_car_payment' => $car,
			'client_school_payment' => $school,
			'client_child_payment' => $child,
			'client_credit_payment' => $credit_payment,
			'client_credit_debt' => $credit_debt,
			'client_other_debt' => $other_debt,
			'therapist' => $therapist ? [
				[
					'post_id' => $therapist,// post_id
					'time_added' => null, // time added
				],
			] : null,
			'client_account_status' => $status,
			'paid_date' => $paid_date,
			'submitted_date' => $submitted_date,
		];

		$data = $this->apply_taxonomies( $data );

		\update_post_meta( $client_id, 'client_data', $data );

		if ( $therapist ) {
			$therapists = [
				[
					'field_685ed386be0dd' => $therapist,// post_id
					'field_685ed3abbe0de' => $submitted_date_string
				],
			];
			update_field( 'field_685ed381be0dc', $therapists, $client_id );
		}
		

		( new \Fritz\Oppc\Client(['post_id' => $client_id]) )->index( $data );
	}

	public function apply_taxonomies( $data )
	{
		$taxonomies = [
			'client_modality',
			'client_referral_source',
			// 'client_country',
			// 'client_state',
			// 'client_pronouns',
			'client_gender',
			'client_ethnicity',
			'client_insurance_status',
			'client_household_income',
			'client_housing_payment',
			'client_car_payment',
			'client_school_payment',
			'client_child_payment',
			'client_credit_payment',
			'client_credit_debt',
			'client_other_debt',
			'client_account_status',
			'family_contribution', // ?
			'family_support', // ?
		];

		foreach ( $taxonomies as $taxonomy ) {
			$string_value = $data[ $taxonomy ];
			if ( empty( $string_value ) ) {
				continue;
			}
			$terms = $this->get_terms( $taxonomy );
			
			$value_found = false;
			foreach ( $terms as $term ) {
				if ( strtolower( (string) $term->name ) === strtolower( (string) $string_value ) ) {
					$data[ $taxonomy ] = $term->term_id;
					$value_found = true;
					break;
				}
			}
			if ( ! $value_found ) {
				print "Warning: No term found for taxonomy '$taxonomy' with value '$string_value'.\n";
				// TODO: Log and debug this
				$data[ $taxonomy ] = null;
			}
		}

		$other_taxonomes = [
			'client_country',
			'client_state',
			'client_pronouns',
			// 'family_contributions',
			// 'family_support',
		];
		$taxonomies = array_merge( $taxonomies, $other_taxonomes );

		foreach ( $taxonomies as $taxonomy ) { 
			$data[$taxonomy] = array_filter( (array) $data[$taxonomy] );
		}

		return $data;
	}

	public function get_terms( $taxonomy )
	{
		$transient = 'tm_migration_terms_' . $taxonomy;
		$terms = get_transient( $transient );
		if ( $terms ) {
			return $terms;
		}
		
		$terms = get_terms( [
			'taxonomy' => $taxonomy,
			'hide_empty' => false,
		] );

		set_transient( $transient, $terms, DAY_IN_SECONDS );

		return $terms;
	}

	public function get_country( $country )
	{
		$options_map = [
			'United States' => 'United States',
			'US' => 'United States',
			'Canada' => 'Canada',
			'CA' => 'Canada'
		];

		if ( $options_map[ $country ] ?? null ) {
			$slug = strtolower( $options_map[ $country ] );
			$term = get_term_by( 'name', $slug, 'client_country' );
			if ( $term ) {
				return $term->term_id;
			}
		}

		return null;
	}

	public function get_state( $state )
	{
		$slug = strtolower( $state );
		$term = get_term_by( 'slug', $slug, 'client_state' );
		if ( $term ) {
			return $term->term_id;
		} else {
			// capitalize the first letter in each word
			$state = ucwords( strtolower( $state ) );
			$term = get_term_by( 'name', $state, 'client_state' );
			if ( $term ) {
				return $term->term_id;
			}
		}
	}

	public function get_school( $school )
	{
		$new_options = [
			"I don't have a student loan",
			"Up to $199",
			"$200 to $299",
			"$300 to $399",
			"$400 to $499",
			"$500 to $599",
			"$600 to $699",
			"$700 to $799",
			"$800 to $899",
			"$900 to $999",
			"$1,000 or more"
		];

		$clean = (float) preg_replace( '/[^\d.]/', '', $school );

		if ( 0 == intval( $clean ) && '0' == $school ) {
			return $new_options[0];
		} else if ( $clean < 200 ) {
			return $new_options[1];
		} else if ( $clean < 300 ) {
			return $new_options[2];
		} else if ( $clean < 400 ) {
			return $new_options[3];
		} else if ( $clean < 500 ) {
			return $new_options[4];
		} else if ( $clean < 600 ) {
			return $new_options[5];
		} else if ( $clean < 700 ) {
			return $new_options[6];
		} else if ( $clean < 800 ) {
			return $new_options[7];
		} else if ( $clean < 900 ) {
			return $new_options[8];
		} else if ( $clean < 1000 ) {
			return $new_options[9];
		} else if ( $clean >= 1000 ) {
			return $new_options[10];
		}

		return null;
	}
	
	public function get_child( $value )
	{
		$new_options = [
			"I don't have child care expenses",
			"Up to $399",
			"$400 to $599",
			"$600 to $799",
			"$800 to $999",
			"$1000 to $1199",
			"$1200 to $1399",
			"$1400 to $1599",
			"$1600 to $1799",
			"$1800 or more"
		];

		$clean = (float) preg_replace( '/[^\d.]/', '', $value );

		if ( 0 == intval( $clean ) && '0' == $value ) {
			return $new_options[0];
		} else if ( $clean < 400 ) {
			return $new_options[1];
		} else if ( $clean < 600 ) {
			return $new_options[2];
		} else if ( $clean < 800 ) {
			return $new_options[3];
		} else if ( $clean < 1000 ) {
			return $new_options[4];
		} else if ( $clean < 1200 ) {
			return $new_options[5];
		} else if ( $clean < 1400 ) {
			return $new_options[6];
		} else if ( $clean < 1600 ) {
			return $new_options[7];
		} else if ( $clean < 1800 ) {
			return $new_options[8];
		} else if ( $clean >= 1800 ) {
			return $new_options[9];
		}

		return null;
	}

	public function get_credit_payment( $value )
	{
		$new_options = [
			"I don't have credit card payments",
			"Up to $199",
			"$200 to $299",
			"$300 to $399",
			"$400 to $499",
			"$500 to $599",
			"$600 to $699",
			"$700 to $799",
			"$800 to $899",
			"$900 to $999",
			"$1,000 or more"
		];

		$clean = (float) preg_replace( '/[^\d.]/', '', $value );

		if ( 0 == intval( $clean ) && '0' == $value ) {
			return $new_options[0];
		} else if ( $clean < 200 ) {
			return $new_options[1];
		} else if ( $clean < 300 ) {
			return $new_options[2];
		} else if ( $clean < 400 ) {
			return $new_options[3];
		} else if ( $clean < 500 ) {
			return $new_options[4];
		} else if ( $clean < 600 ) {
			return $new_options[5];
		} else if ( $clean < 700 ) {
			return $new_options[6];
		} else if ( $clean < 800 ) {
			return $new_options[7];
		} else if ( $clean < 900 ) {
			return $new_options[8];
		} else if ( $clean < 1000 ) {
			return $new_options[9];
		} else if ( $clean >= 1000 ) {
			return $new_options[10];
		}

		return null;
	}

	public function get_credit_debt( $value )
	{
		$new_options = [
			"I don't have credit card debt",
			"I pay off my credit cards every month",
			"Up to $999",
			"$1,000 to $4,999",
			"$5,000 to $9,999",
			"$10,000 to $14,999",
			"$15,000 to $19,999",
			"$20,000 to $24,999",
			"$25,000 to $29,999",
			"$30,000 or more"
		];

		$clean = (float) preg_replace( '/[^\d.]/', '', $value );

		if ( 0 == intval( $clean ) && '0' == $value ) {
			return $new_options[0];
		} else if ( $clean < 1000 ) {
			return $new_options[2];
		} else if ( $clean < 5000 ) {
			return $new_options[3];
		} else if ( $clean < 10000 ) {
			return $new_options[4];
		} else if ( $clean < 15000 ) {
			return $new_options[5];
		} else if ( $clean < 20000 ) {
			return $new_options[6];
		} else if ( $clean < 25000 ) {
			return $new_options[7];
		} else if ( $clean < 30000 ) {
			return $new_options[8];
		} else if ( $clean >= 30000 ) {
			return $new_options[9];
		}

		return null;
	}

	// TODO: update this
	public function get_account_status( $client_data )
	{
		$paid = ! empty( $client_data['paid'] ?? null );
		$cancelled = ! empty( $client_data['cancelled'] ?? null );
		$submitted = ! empty( $client_data['submitted'] ?? null );

		if ( $cancelled ) {
			return 'Canceled';
		}
		if ( $paid ) {
			return 'Member';
		}
		if ( $submitted ) {
			return 'Unpaid';
		}

		return null;
	}

	public function get_therapist( $value )
	{
		if ( empty( $value ) || 0 === (int) $value ) {
			return null;
		}

		$map = $this->get_therapist_map();

		$therapist = $map[ (int) $value ] ?? null;

		if ( $therapist ) {
			print "Found therapist mapping for old ID $value to new ID $therapist\n";
			return $therapist;
		}

		return null;

		// return $this->get_existing_post( $value, ['therapist'] );
	}

	public function get_therapist_map()
	{
		$transient = 'tm_migration_therapist_map';
		$map = get_transient( $transient );
		if ( $map ) {
			return $map;
		}
		$map = [];
		$therapists = get_posts( [
			'post_type' => 'therapist',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key' => '_migrate_id',
					'compare' => 'EXISTS'
				]
			]
		] );
		foreach ( $therapists as $therapist_id ) {
			$old_id = get_post_meta( $therapist_id, '_migrate_id', true );
			if ( $old_id ) {
				$map[ (int) $old_id ] = (int) $therapist_id;
			}
		}
		set_transient( $transient, $map, DAY_IN_SECONDS );
		return $map;
	}

	public function get_pronouns( $value )
	{
		if ( empty( $value ) ) {
			return null;
		}
		$new_options = [
			'he/him',
			'she/her',
			'he/they',
			'she/they',
			'they/them',
			'ze/hir',
			'xe/xem',
			'ver/vir',
			'the/tem',
			'e/em',
			'she/her/ella',
			'he/him/él',
			'they/them/elle',
			'they/them/ellx',
			'Prefer to self-describe (text field)',
			'Prefer not to answer'
		];

		$value =  explode( ',', $value );

		$cleaned = [];
		$other = '';

		$new_options_lowercase = array_map( 'strtolower', $new_options );

		foreach ( $value as $v ) {
			$v = trim( strtolower( $v ) );
			if ( in_array( $v, $new_options_lowercase ) ) {
				$index = array_search( $v, $new_options_lowercase );
				$cleaned[] = $new_options_lowercase[ $index ];
			} else {
				if ( ! empty( $other ) ) {
					$other .= ', ' . $v;
				} else {
					$other = $v;
				}
			}
		}

		$term_ids = [];
		if ( ! empty( $cleaned ) ) {
			foreach ( $cleaned as $value ) {
				$taxonomy_term = get_term_by( 'name', $value, 'client_pronouns' );
				if ( $taxonomy_term ) {
					$term_ids[] = $taxonomy_term->term_id;
				}
			}
		}

		return [
			'pronouns' => $term_ids,
			'pronouns_other' => trim( $other )
		];
	}

	public function get_other_debt( $value )
	{
		$clean = (float) preg_replace( '/[^\d.]/', '', $value );
		if ( 0 == intval( $clean ) && '0' == $value ) {
			return "I don't have additional debt";
		}
		// TODO: are these the same new options?
		return $this->get_credit_debt( $value );
	}

	public function get_car( $car )
	{
		$new_options = [
			"I don't have a car payment",
			"Up to $199",
			"$200 to $299",
			"$300 to $399",
			"$400 to $499",
			"$500 to $599",
			"$600 to $699",
			"$700 to $799",
			"$800 to $899",
			"$900 to $999",
			"$1,000 or more"
		];

		$clean = (float) preg_replace( '/[^\d.]/', '', $car );

		if ( 0 == intval( $clean ) && '0' == $car ) {
			return $new_options[0];
		} else if ( $clean < 200 ) {
			return $new_options[1];
		} else if ( $clean < 300 ) {
			return $new_options[2];
		} else if ( $clean < 400 ) {
			return $new_options[3];
		} else if ( $clean < 500 ) {
			return $new_options[4];
		} else if ( $clean < 600 ) {
			return $new_options[5];
		} else if ( $clean < 700 ) {
			return $new_options[6];
		} else if ( $clean < 800 ) {
			return $new_options[7];
		} else if ( $clean < 900 ) {
			return $new_options[8];
		} else if ( $clean < 1000 ) {
			return $new_options[9];
		} else if ( $clean >= 1000 ) {
			return $new_options[10];
		}

		return null;
	}

	public function get_housing( $housing )
	{
		$new_options = [
			"I don't have a mortgage or rent payment",
			"Up to $499",
			"$500 to $599",
			"$600 to $699",
			"$700 to $799",
			"$800 to $899",
			"$900 to $999",
			"$1,000 to $1,099",
			"$1,100 to $1,199",
			"$1,200 to $1,299",
			"$1,300 to $1,399",
			"$1,400 to $1,499",
			"$1,500 to $1,599",
			"$1,600 to $1,699",
			"$1,700 to $1,799",
			"$1,800 to $1,899",
			"$1,900 to $1,999",
			"$2,000 or more"
		];

		$clean = (float) preg_replace( '/[^\d.]/', '', $housing );

		if ( 0 == intval( $clean ) && '0' == $housing ) {
			return $new_options[0];
		} else if ( $clean < 500 ) {
			return $new_options[1];
		} else if ( $clean < 600 ) {
			return $new_options[2];
		} else if ( $clean < 700 ) {
			return $new_options[3];
		} else if ( $clean < 800 ) {
			return $new_options[4];
		} else if ( $clean < 900 ) {
			return $new_options[5];
		} else if ( $clean < 1000 ) {
			return $new_options[6];
		} else if ( $clean < 1100 ) {
			return $new_options[7];
		} else if ( $clean < 1200 ) {
			return $new_options[8];
		} else if ( $clean < 1300 ) {
			return $new_options[9];
		} else if ( $clean < 1400 ) {
			return $new_options[10];
		} else if ( $clean < 1500 ) {
			return $new_options[11];
		} else if ( $clean < 1600 ) {
			return $new_options[12];
		} else if ( $clean < 1700 ) {
			return $new_options[13];
		} else if ( $clean < 1800 ) {
			return $new_options[14];
		} else if ( $clean < 1900 ) {
			return $new_options[15];
		} else if ( $clean < 2000 ) {
			return $new_options[16];
		} else if ( $clean >= 2000 ) {
			return $new_options[17];
		}

		return null;
	}

	public function get_family_support( $support )
	{
		// TODO: confirm these options -- I just made them up
		$new_options = [
			0 => '1',
			1 => '2',
			2 => '3',
			3 => '4',
			4 => '5',
			5 => '6',
			6 => 'More than 6',
		];

		$map = [
			'1, myself' => $new_options[0],
			'Four' => $new_options[3],
			'n/a' => null,
			'me' => $new_options[0],
			'two' => $new_options[1],
			'9' => $new_options[6],
			'1 (myself)' => $new_options[0],
			'self' => $new_options[0],
			'Three' => $new_options[2],
			'N/A' => null,
			'one' => $new_options[0],
			'Just myself' => $new_options[0],
			'just me' => $new_options[0],
			'none' => null,
			'Me' => $new_options[0],
			'Self' => $new_options[0],
			'Two' => $new_options[1],
			'8' => $new_options[5],
			'One' => $new_options[0],
			'Just me' => $new_options[0],
			'None' => null,
			'myself' => $new_options[0],
			'7' => $new_options[5],
			'Myself' => $new_options[0],
			'6' => $new_options[5],
			'5' => $new_options[4],
			'4' => $new_options[3],
			'0' => null,
			'3' => $new_options[2],
			'2' => $new_options[1],
			'1' => $new_options[0],
		];

		return $map[ $support ] ?? null;
	}

	public function get_family_contributions( $contrib )
	{
		// TODO: confirm these options -- I just made them up
		$new_options = [
			0 => '1',
			1 => '2',
			2 => '3',
			3 => '4',
			4 => '5',
			5 => '6',
			6 => 'More than 6',
		];

		$map = [
			'me' => $new_options[0],
			'1, myself' => $new_options[0],
			'two' => $new_options[1],
			'Myself only' => $new_options[0],
			'Only me' => $new_options[0],
			'N/A' => null,
			'1 (myself)' => $new_options[0],
			'6' => $new_options[5],
			'self' => $new_options[0],
			'none' => null,
			'just me' => $new_options[0],
			'Two' => $new_options[1],
			'one' => $new_options[0],
			'Just myself' => $new_options[0],
			'Me' => $new_options[0],
			'Self' => $new_options[0],
			'5' => $new_options[4],
			'None' => null,
			'One' => $new_options[0],
			'Just me' => $new_options[0],
			'myself' => $new_options[0],
			'4' => $new_options[3],
			'Myself' => $new_options[0],
			'3' => $new_options[2],
			'0' => null,
			'2' => $new_options[1],
			'1' => $new_options[0],
		];

		return $map[ $contrib ] ?? null;
	}

	public function get_income( $income )
	{
		$new_options = [
			'Up to $19,999',
			'$20,000 to $34,999',
			'$35,000 to $49,999',
			'$50,000 to $74,999',
			'$75,000 to $99,999',
			'$100,000 or more',
		];

		$map = [
			'Above $100,000' => $new_options[5],
			'$60,000 - $75,000' => $new_options[3],
			'$35,000 - $60,000' => $new_options[2], // not exact match
			'$75,000 - $100,000' => $new_options[4],
			'$50,000 - $75,000' => $new_options[3],
			'Less than $20,000' => $new_options[0],
			'$35,000 - $50,000' => $new_options[2],
			'$20,000 - $35,000' => $new_options[1],
		];

		return $map[ $income ] ?? null;
	}

	public function get_insurance( $insurance )
	{
		$new_options = [
			'No, I do not have any health insurance',
			'Yes, but I do not have any mental health coverage',
			'Yes, but my deductible and/or copay is too expensive',
			'Yes, but there are no therapists in my area who are available to work with me',
			'Yes, I can use my insurance to access therapy',
			'Other',
		];

		$map = [
			'No' => $new_options[0],
			'Yes' => $new_options[4],
			'but I do not have any mental health coverage.' => $new_options[1],
			'but my deductible and/or copay is too expensive.' => $new_options[2],
			'but there are no therapists in my area who are available to work with me.' => $new_options[3],
			'Yes, I can use my insurance to access therapy.' => $new_options[4],
			'No, I do not have any health insurance.' => $new_options[0],
			'No tengo ningun seguro de salud.' => $new_options[0],
		];

		// [não tenho nenhum seguro de saúde.] => 1
		// [Otro] => 1
		// [Não] => 1
		// [??? ??? ??? ?? ????? ???.] => 1
		// [Client didn\'t have insurance in October 2024 when they registered. They informed us on 11/29/24 that they now have Medicaid.] => 1
		// [Yas] => 1
		// [puedo usar mi seguro para acceder a terapia.] => 2
		// [?????????????????] => 2
		// [??] => 2
		// [puedo utilizar mi seguro para acceder a terapia.] => 2
		// [no tengo ningún seguro de salud.] => 3
		// [pero no tengo ninguna cobertura de salud mental.] => 3
		// [Sí] => 7
		// [no tengo ningún seguro médico.] => 8
		// [Other] => 5567
		// [but there are no therapists in my area who are available to work with me.] => 7521
		// [but my deductible and/or copay is too expensive.] => 15071
		// [but I do not have any mental health coverage.] => 22231
		// [I do not have any health insurance.] => 35240
		// [No] => 35251
		// [Yes] => 44822

		// TODO: Don't map?
		return [
			'insurance' => $map[ $insurance ] ?? null,
			'insurance_other' => $insurance,
		];
	}

	public function get_ethnicity( $ethnicity_original )
	{
		$new_options = [
			0 => 'Asian or Asian American',
			1 => 'Black or African American',
			2 => 'Hispanic or Latino',
			3 => 'Middle Eastern or North African',
			4 => 'Multiracial or Multiethnic',
			5 => 'Native American, Indigenous, or Alaska Native',
			6 => 'Native Hawaiian or other Pacific Islander',
			7 => 'White',
			8 => 'South Asian or South Asian American',
			9 => 'Prefer not to answer',
		];

		$map = [
			'Black' => $new_options[1],
			'white' => $new_options[7],
			'Indian' => $new_options[8],
			'Caucasian' => $new_options[7],
			'Mexican' => $new_options[2],
			'other' => $new_options[9],
			'Mixed' => $new_options[4],
			'N/A' => $new_options[9],
			'Jewish' => $new_options[3],
			'Hispanic' => $new_options[2],
			'Filipino' => $new_options[0],
			'Native Hawaiian or Other Pacific Islander' => $new_options[6],
			'American Indian or Alaska Native' => $new_options[5],
			'Native Hawaiian or other Pacific Islander' => $new_options[6],
			'Native American or Alaska Native' => $new_options[5],
			'Middle Eastern' => $new_options[3],
			'South Asian' => $new_options[8],
			'Other' => $new_options[9],
			'Prefer not to disclose' => $new_options[9],
			'Multi-Racial' => $new_options[4],
			'Hispanic or Latino' => $new_options[2],
			'Asian' => $new_options[0],
			'Latin/Latinx/Latina/Latino/Hispanic' => $new_options[2],
			'White' => $new_options[7],
			'Black or African American' => $new_options[1],
			'White or European American' => $new_options[7],
		];

		$ethnicity = $map[ $ethnicity_original ] ?? null;

		return [
			'ethnicity' => $ethnicity,
			'ethnicity_other' => $ethnicity ? null : $ethnicity_original
		];
	}

	public function get_modality( $modality )
	{
		$new_options = [
			'Individual, couple, or family therapy for myself',
			'Individual therapy on behalf of a minor (parents or legal guardians only)',
		];

		$map = [
			'Familia' => $new_options[0],
			'Group' => $new_options[0],
			'Children' => $new_options[1],
			'Family' => $new_options[0],
			'Individuals' => $new_options[0],
			'Couples' => $new_options[0],
			'Individual' => $new_options[0],
		];

		return $map[ $modality ] ?? $new_options[0];
	}

	public function get_referral( $referral_other )
	{
		$referral = null;

		$new_options = [
			'Blog or publication (please specify)',
			'Colleague (please specify)',
			'Friend or family member',
			'Influencer, please specify name and platform',
			'Paid ad on Facebook',
			'Paid ad on Instagram',
			'Paid ad on Reddit',
			'Podcast (please specify)',
			'Search engine (Google, Yahoo, Bing, etc.)',
			'Social media post from a friend, colleague, or brand',
			'Therapist',
			'Other (please specify)',
		];

		if ( str_contains( strtolower( $referral_other ), 'google' ) ) {
			$referral = $new_options[8];
		} else if ( str_contains ( strtolower( $referral_other ), 'friend' ) ) {
			$referral =  $new_options[2];
		} else if ( str_contains ( strtolower( $referral_other ), 'therapist' ) ) {
			$referral =  $new_options[9];
		} else if ( str_contains ( strtolower( $referral_other ), 'other' ) ) {
			$referral =  $new_options[10];
		}

		if ( ! empty( $referral ) ) {
			$referral_other = null;
		}

		return [
			'referral' => $referral,
			'referral_other' => $referral_other,
		];
	}
}