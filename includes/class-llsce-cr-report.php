<?php
/**
 * Builds the certificate-earner dataset for a date range.
 *
 * One row per LifterLMS certificate (post type llms_my_certificate) whose
 * post_date falls in the range. Learner profile fields come from usermeta;
 * license, license state, and pharmacist details are collected from every
 * course-specific (`p24038-02_rn_license_num`) and profile-level
 * (`profile_rn_license_num`) meta entry the learner has, then resolved per
 * certificate: the entry saved for the certificate's own course wins, then
 * the most recently saved course entry, then the profile entry.
 *
 * @package LLSCE_Certificate_Report
 */

defined( 'ABSPATH' ) || exit;

class LLSCE_CR_Report {

	const POST_TYPE = 'llms_my_certificate';

	/** Learners per database round-trip; keeps memory flat however large the range. */
	const LEARNERS_PER_CHUNK = 1000;

	const FIELD_LICENSE_NUM   = 'license_num';
	const FIELD_LICENSE_STATE = 'license_state';
	const FIELD_PHARM_DOB     = 'pharm_dob';
	const FIELD_PHARM_NABP    = 'pharm_nabp';

	/** Column headers, in the order the Users sheet has always used. */
	const COLUMNS = array(
		'user_id',
		'first_name',
		'last_name',
		'course_name',
		'certificate_title',
		'certificate_date',
		'email',
		'address_1',
		'address_2',
		'city',
		'state',
		'zip',
		'license_num',
		'license_state',
		'certificate_id',
		'certificate_template_id',
		'LIC_NUM',
		'LIC_STATE',
		'PHARM DOB',
		'PHARM NAPB',
		'MISSING INFO',
		'NEW INFO NUM',
		'NEW INFO STATE',
	);

	/** Excel cell type per column (see LLSCE_CR_Xlsx_Writer::TYPE_*). */
	const COLUMN_TYPES = array(
		'number',
		'string',
		'string',
		'string',
		'string',
		'datetime',
		'string',
		'string',
		'string',
		'string',
		'string',
		'string',
		'string',
		'string',
		'number',
		'number',
		'string',
		'string',
		'string',
		'string',
		'string',
		'string',
		'string',
	);

	/** Column widths (zero-based index => characters), taken from the hand-built workbook. */
	const COLUMN_WIDTHS = array(
		0  => 10,
		1  => 14,
		2  => 16,
		3  => 40,
		4  => 30,
		5  => 12,
		6  => 30,
		7  => 24,
		8  => 14,
		9  => 18,
		10 => 8,
		11 => 10,
		12 => 14,
		13 => 14,
		14 => 13,
		15 => 22,
		16 => 14,
		17 => 14,
		18 => 12,
		19 => 14,
		20 => 15,
		21 => 15,
		22 => 16,
	);

	/** Plain usermeta keys copied straight onto the row. */
	const PROFILE_KEYS = array(
		'first_name'             => 'first_name',
		'last_name'              => 'last_name',
		'llms_billing_address_1' => 'address_1',
		'llms_billing_address_2' => 'address_2',
		'llms_billing_city'      => 'city',
		'llms_billing_state'     => 'state',
		'llms_billing_zip'       => 'zip',
	);

	/** @var wpdb */
	private $db;

	/** @var string|null Inclusive start date, Y-m-d. */
	private $start_date;

	/** @var string|null Inclusive end date, Y-m-d. */
	private $end_date;

	public function __construct( wpdb $db, ?string $start_date = null, ?string $end_date = null ) {
		$this->db         = $db;
		$this->start_date = $start_date;
		$this->end_date   = $end_date;
	}

	/**
	 * Stream every report row, learner by learner, in user_id then date order.
	 *
	 * @return Generator<int, array<string, mixed>> Rows keyed by self::COLUMNS.
	 */
	public function rows(): Generator {
		foreach ( array_chunk( $this->learner_ids(), self::LEARNERS_PER_CHUNK ) as $learner_ids ) {
			$certificates = $this->certificates( $learner_ids );
			$emails       = $this->emails( $learner_ids );
			list( $profiles, $entries ) = $this->user_meta( $learner_ids );

			foreach ( $certificates as $certificate ) {
				yield $this->compose_row( $certificate, $emails, $profiles, $entries );
			}

			$this->db->flush();
		}
	}

	/**
	 * Every row at once. Convenient for small ranges and tests; use rows() for exports.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function build(): array {
		return iterator_to_array( $this->rows(), false );
	}

	/**
	 * Headline counts for the admin preview.
	 *
	 * @param iterable<array<string, mixed>> $rows
	 * @return array<string, mixed>
	 */
	public static function summarize( iterable $rows ): array {
		$summary = array(
			'certificates' => 0,
			'learners'     => 0,
			'missing_info' => 0,
			'new_info'     => 0,
			'no_address'   => 0,
			'first_date'   => null,
			'last_date'    => null,
			'by_title'     => array(),
		);

		$learners = array();
		foreach ( $rows as $row ) {
			++$summary['certificates'];
			$learners[ $row['user_id'] ] = true;

			if ( '' !== $row['MISSING INFO'] ) {
				++$summary['missing_info'];
			}
			if ( '' !== $row['NEW INFO NUM'] || '' !== $row['NEW INFO STATE'] ) {
				++$summary['new_info'];
			}
			if ( '' === $row['address_1'] && '' === $row['city'] && '' === $row['zip'] ) {
				++$summary['no_address'];
			}

			$date = substr( (string) $row['certificate_date'], 0, 10 );
			if ( null === $summary['first_date'] || $date < $summary['first_date'] ) {
				$summary['first_date'] = $date;
			}
			if ( null === $summary['last_date'] || $date > $summary['last_date'] ) {
				$summary['last_date'] = $date;
			}

			$title = '' !== $row['certificate_title'] ? $row['certificate_title'] : '(no certificate template)';
			$summary['by_title'][ $title ] = ( $summary['by_title'][ $title ] ?? 0 ) + 1;
		}

		$summary['learners'] = count( $learners );
		arsort( $summary['by_title'] );

		return $summary;
	}

	/**
	 * SQL fragment (and its values) selecting certificates in range. Aliased as `c`.
	 *
	 * @param int[] $learner_ids When given, restrict to these authors.
	 * @return array{0: string, 1: array<int, string|int>}
	 */
	private function certificate_where( array $learner_ids = array() ): array {
		$sql    = "c.post_type = '" . self::POST_TYPE . "' AND c.post_status <> 'trash'";
		$values = array();

		if ( null !== $this->start_date ) {
			$sql     .= ' AND c.post_date >= %s';
			$values[] = $this->start_date . ' 00:00:00';
		}

		if ( null !== $this->end_date ) {
			$sql     .= ' AND c.post_date < %s';
			$values[] = gmdate( 'Y-m-d', strtotime( $this->end_date . ' +1 day' ) ) . ' 00:00:00';
		}

		if ( ! empty( $learner_ids ) ) {
			$sql   .= ' AND c.post_author IN (' . implode( ',', array_fill( 0, count( $learner_ids ), '%d' ) ) . ')';
			$values = array_merge( $values, array_map( 'intval', $learner_ids ) );
		}

		return array( $sql, $values );
	}

	/**
	 * Every learner with a certificate in range, ascending.
	 *
	 * @return int[]
	 */
	private function learner_ids(): array {
		list( $where, $values ) = $this->certificate_where();

		$sql = "SELECT DISTINCT c.post_author FROM {$this->db->posts} c WHERE {$where} ORDER BY c.post_author ASC";

		return array_map( 'intval', (array) $this->db->get_col( $this->prepare( $sql, $values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function prepare( string $sql, array $values ): string {
		if ( empty( $values ) ) {
			return $sql;
		}

		return $this->db->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int[] $learner_ids
	 * @return object[] certificate_id, user_id, course_name, certificate_date, certificate_template_id, certificate_title
	 */
	private function certificates( array $learner_ids ): array {
		list( $where, $values ) = $this->certificate_where( $learner_ids );

		$sql = "SELECT c.ID AS certificate_id, c.post_author AS user_id, c.post_title AS course_name,
				c.post_date AS certificate_date, c.post_parent AS certificate_template_id,
				t.post_title AS certificate_title
			FROM {$this->db->posts} c
			LEFT JOIN {$this->db->posts} t ON t.ID = c.post_parent
			WHERE {$where}
			ORDER BY c.post_author ASC, c.post_date ASC, c.ID ASC";

		return (array) $this->db->get_results( $this->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int[] $learner_ids
	 * @return array<int, string> user_id => email
	 */
	private function emails( array $learner_ids ): array {
		$sql = "SELECT u.ID, u.user_email
			FROM {$this->db->users} u
			WHERE u.ID IN (" . implode( ',', array_fill( 0, count( $learner_ids ), '%d' ) ) . ')';
		$values = array_map( 'intval', $learner_ids );

		$emails = array();
		foreach ( (array) $this->db->get_results( $this->prepare( $sql, $values ) ) as $user ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$emails[ (int) $user->ID ] = (string) $user->user_email;
		}

		return $emails;
	}

	/**
	 * Load profile fields and license/pharmacist entries for a chunk of learners.
	 *
	 * @param int[] $learner_ids
	 * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, array<int, array<string, mixed>>>>}
	 */
	private function user_meta( array $learner_ids ): array {
		$values     = array_map( 'intval', $learner_ids );
		$plain_keys = "'" . implode( "','", array_map( array( $this->db, '_real_escape' ), array_keys( self::PROFILE_KEYS ) ) ) . "'";
		$suffixes   = implode( '|', array( self::FIELD_LICENSE_NUM, self::FIELD_LICENSE_STATE, self::FIELD_PHARM_DOB, self::FIELD_PHARM_NABP ) );

		$sql = "SELECT m.umeta_id, m.user_id, m.meta_key, m.meta_value
			FROM {$this->db->usermeta} m
			WHERE m.user_id IN (" . implode( ',', array_fill( 0, count( $learner_ids ), '%d' ) ) . ")
			AND (m.meta_key IN ({$plain_keys}) OR m.meta_key REGEXP '({$suffixes})$')
			ORDER BY m.umeta_id ASC";

		$profiles = array();
		$entries  = array();

		foreach ( (array) $this->db->get_results( $this->prepare( $sql, $values ) ) as $meta ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$user_id = (int) $meta->user_id;
			$key     = (string) $meta->meta_key;
			$value   = trim( (string) $meta->meta_value );

			if ( isset( self::PROFILE_KEYS[ $key ] ) ) {
				$profiles[ $user_id ][ self::PROFILE_KEYS[ $key ] ] = $value;
				continue;
			}

			$parsed = self::parse_entry_key( $key );
			if ( null === $parsed || '' === $value ) {
				continue;
			}

			$entries[ $user_id ][ $parsed['field'] ][] = array(
				'umeta_id' => (int) $meta->umeta_id,
				'scope'    => $parsed['scope'],
				'code'     => $parsed['code'],
				'value'    => $value,
			);
		}

		return array( $profiles, $entries );
	}

	/**
	 * Split a license/pharmacist meta key into its parts.
	 *
	 * Handles `p24038-02_rn_license_num`, `kg24011-06_license_state`,
	 * `p23020-04_pharm_dob`, and `profile_np_license_num`.
	 *
	 * @return array{scope: string, code: string|null, type: string, field: string}|null
	 */
	public static function parse_entry_key( string $key ): ?array {
		$fields = implode( '|', array( self::FIELD_LICENSE_NUM, self::FIELD_LICENSE_STATE, self::FIELD_PHARM_DOB, self::FIELD_PHARM_NABP ) );

		if ( ! preg_match( '/^(profile|[a-z]+\d+(?:-\d+)*)_(?:([a-z]+)_)?(' . $fields . ')$/i', $key, $match ) ) {
			return null;
		}

		$prefix = strtolower( $match[1] );

		return array(
			'scope' => 'profile' === $prefix ? 'profile' : 'course',
			'code'  => 'profile' === $prefix ? null : $prefix,
			'type'  => strtolower( $match[2] ),
			'field' => strtolower( $match[3] ),
		);
	}

	/**
	 * Activity codes a certificate title can be matched against, most specific first.
	 *
	 * "KG24003-2 | Social Worker" yields kg24003-2, kg24003-02, kg24003 so that it
	 * finds the `kg24003-02_*` fields the course actually stored.
	 *
	 * @return string[]
	 */
	public static function codes_from_title( ?string $title ): array {
		if ( ! preg_match( '/^\s*([A-Za-z]+\d+(?:-\d+)*)/', (string) $title, $match ) ) {
			return array();
		}

		$code  = strtolower( $match[1] );
		$codes = array( $code );

		$segments = explode( '-', $code );
		$padded   = array_shift( $segments );
		foreach ( $segments as $segment ) {
			$padded .= '-' . str_pad( $segment, 2, '0', STR_PAD_LEFT );
		}
		$codes[] = $padded;

		while ( preg_match( '/^(.*\d)-\d+$/', $code, $shorter ) ) {
			$code    = $shorter[1];
			$codes[] = $code;
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Pick the value to report for one field on one certificate.
	 *
	 * @param array<int, array<string, mixed>> $field_entries Every non-empty entry the learner has for the field.
	 * @param string[]                         $codes         Candidate activity codes for the certificate.
	 * @return array{0: string, 1: bool} The value and whether it came from the certificate's own course.
	 */
	public static function resolve( array $field_entries, array $codes ): array {
		if ( empty( $field_entries ) ) {
			return array( '', false );
		}

		foreach ( $codes as $code ) {
			$match = self::newest(
				array_filter(
					$field_entries,
					static function ( array $entry ) use ( $code ): bool {
						return 'course' === $entry['scope'] && $entry['code'] === $code;
					}
				)
			);
			if ( null !== $match ) {
				return array( $match['value'], true );
			}
		}

		foreach ( array( 'course', 'profile' ) as $scope ) {
			$fallback = self::newest(
				array_filter(
					$field_entries,
					static function ( array $entry ) use ( $scope ): bool {
						return $scope === $entry['scope'];
					}
				)
			);
			if ( null !== $fallback ) {
				return array( $fallback['value'], false );
			}
		}

		return array( '', false );
	}

	private static function newest( array $entries ): ?array {
		$newest = null;
		foreach ( $entries as $entry ) {
			if ( null === $newest || $entry['umeta_id'] > $newest['umeta_id'] ) {
				$newest = $entry;
			}
		}

		return $newest;
	}

	/**
	 * @param object                                   $certificate
	 * @param array<int, string>                       $emails
	 * @param array<int, array<string, string>>        $profiles
	 * @param array<int, array<string, array>>         $entries
	 * @return array<string, mixed>
	 */
	private function compose_row( object $certificate, array $emails, array $profiles, array $entries ): array {
		$user_id = (int) $certificate->user_id;
		$profile = $profiles[ $user_id ] ?? array();
		$fields  = $entries[ $user_id ] ?? array();
		$codes   = self::codes_from_title( $certificate->certificate_title );

		list( $license_num, $num_matched )     = self::resolve( $fields[ self::FIELD_LICENSE_NUM ] ?? array(), $codes );
		list( $license_state, $state_matched ) = self::resolve( $fields[ self::FIELD_LICENSE_STATE ] ?? array(), $codes );
		list( $pharm_dob )                     = self::resolve( $fields[ self::FIELD_PHARM_DOB ] ?? array(), $codes );
		list( $pharm_nabp )                    = self::resolve( $fields[ self::FIELD_PHARM_NABP ] ?? array(), $codes );

		return array(
			'user_id'                 => $user_id,
			'first_name'              => $profile['first_name'] ?? '',
			'last_name'               => $profile['last_name'] ?? '',
			'course_name'             => (string) $certificate->course_name,
			'certificate_title'       => (string) $certificate->certificate_title,
			'certificate_date'        => (string) $certificate->certificate_date,
			'email'                   => $emails[ $user_id ] ?? '',
			'address_1'               => $profile['address_1'] ?? '',
			'address_2'               => $profile['address_2'] ?? '',
			'city'                    => $profile['city'] ?? '',
			'state'                   => $profile['state'] ?? '',
			'zip'                     => $profile['zip'] ?? '',
			'license_num'             => $num_matched ? $license_num : '',
			'license_state'           => $state_matched ? $license_state : '',
			'certificate_id'          => (int) $certificate->certificate_id,
			'certificate_template_id' => (int) $certificate->certificate_template_id,
			'LIC_NUM'                 => $license_num,
			'LIC_STATE'               => $license_state,
			'PHARM DOB'               => $pharm_dob,
			'PHARM NAPB'              => $pharm_nabp,
			'MISSING INFO'            => ( '' === $license_num || '' === $license_state ) ? 'MISSING INFO' : '',
			'NEW INFO NUM'            => ( ! $num_matched && '' !== $license_num ) ? 'NEW INFO' : '',
			'NEW INFO STATE'          => ( ! $state_matched && '' !== $license_state ) ? 'NEW INFO' : '',
		);
	}
}
