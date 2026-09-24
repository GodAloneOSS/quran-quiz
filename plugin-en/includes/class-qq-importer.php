<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles importing questions — both the bundled seed data (JSON) and
 * admin re-imports via CSV. Never invents question text or answers: rows
 * that fail validation are skipped and reported back to the admin.
 */
class QQ_Importer {

	const DIFFICULTY_MAP = array(
		'E' => 'easy', 'EASY' => 'easy', 'EASY (E)' => 'easy',
		'M' => 'medium', 'MEDIUM' => 'medium',
		'H' => 'hard', 'HARD' => 'hard',
	);

	/**
	 * Normalize one raw row (from JSON seed or CSV) into a clean record, or
	 * return a WP_Error describing why it was rejected.
	 */
	public static function normalize_row( $row, $row_number = 0 ) {
		$question = isset( $row['question'] ) ? trim( wp_strip_all_tags( (string) $row['question'] ) ) : '';
		if ( '' === $question ) {
			return new WP_Error( 'qq_bad_row', "Row {$row_number}: missing question text." );
		}

		// Options can arrive either as an "options" array (JSON seed) or
		// option_a..option_d (CSV).
		$options = array();
		if ( ! empty( $row['options'] ) && is_array( $row['options'] ) ) {
			$options = array_values( array_map( 'strval', $row['options'] ) );
		} else {
			foreach ( array( 'option_a', 'option_b', 'option_c', 'option_d' ) as $key ) {
				if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$options[] = trim( (string) $row[ $key ] );
				}
			}
		}
		$options = array_values( array_filter( $options, function ( $o ) {
			return '' !== trim( (string) $o );
		} ) );

		$qtype = isset( $row['type'] ) ? strtolower( trim( (string) $row['type'] ) ) : ( isset( $row['qtype'] ) ? strtolower( trim( (string) $row['qtype'] ) ) : '' );
		if ( '' === $qtype ) {
			$qtype = ( count( $options ) === 4 ) ? 'mcq' : 'tf';
		}

		if ( 'mcq' === $qtype && count( $options ) !== 4 ) {
			return new WP_Error( 'qq_bad_row', "Row {$row_number}: MCQ questions need exactly 4 options (found " . count( $options ) . ')' );
		}
		if ( 'tf' === $qtype && ( count( $options ) < 2 || count( $options ) > 4 ) ) {
			return new WP_Error( 'qq_bad_row', "Row {$row_number}: True/False style questions need 2-4 options (found " . count( $options ) . ')' );
		}

		$correct_raw = isset( $row['correct'] ) ? trim( (string) $row['correct'] ) : ( isset( $row['correct_answer'] ) ? trim( (string) $row['correct_answer'] ) : '' );
		$correct_letter = self::resolve_correct_letter( $correct_raw, count( $options ) );
		if ( ! $correct_letter ) {
			return new WP_Error( 'qq_bad_row', "Row {$row_number}: correct answer '{$correct_raw}' does not match any option." );
		}

		$difficulty_raw = isset( $row['difficulty'] ) ? strtoupper( trim( (string) $row['difficulty'] ) ) : '';
		$difficulty = isset( self::DIFFICULTY_MAP[ $difficulty_raw ] ) ? self::DIFFICULTY_MAP[ $difficulty_raw ] : strtolower( $difficulty_raw );
		if ( ! in_array( $difficulty, array( 'easy', 'medium', 'hard' ), true ) ) {
			return new WP_Error( 'qq_bad_row', "Row {$row_number}: unrecognised difficulty '{$difficulty_raw}' (use Easy/Medium/Hard)." );
		}

		// Pad options to 4 slots (C/D empty for tf-style questions is fine).
		while ( count( $options ) < 4 ) {
			$options[] = null;
		}

		return array(
			'question'       => $question,
			'qtype'          => $qtype,
			'option_a'       => $options[0],
			'option_b'       => $options[1],
			'option_c'       => $options[2],
			'option_d'       => $options[3],
			'correct_option' => $correct_letter,
			'difficulty'     => $difficulty,
			'category'       => isset( $row['category'] ) ? sanitize_text_field( $row['category'] ) : null,
			'reference'      => isset( $row['reference'] ) ? sanitize_text_field( $row['reference'] ) : null,
			'explanation'    => isset( $row['explanation'] ) ? sanitize_textarea_field( $row['explanation'] ) : null,
			'active'         => isset( $row['active'] ) ? ( $row['active'] ? 1 : 0 ) : 1,
			'source_row'     => isset( $row['source_row'] ) ? intval( $row['source_row'] ) : $row_number,
		);
	}

	/**
	 * Accepts "A".."D", or a 1-4 index, and maps it to a letter that is
	 * actually within range of how many options this question has.
	 */
	private static function resolve_correct_letter( $raw, $option_count ) {
		$raw = strtoupper( trim( $raw ) );
		$letters = array( 'A', 'B', 'C', 'D' );

		if ( in_array( $raw, $letters, true ) ) {
			$idx = array_search( $raw, $letters, true );
			return ( $idx < $option_count ) ? $raw : false;
		}
		if ( ctype_digit( $raw ) ) {
			$n = intval( $raw );
			if ( $n >= 1 && $n <= $option_count ) {
				return $letters[ $n - 1 ];
			}
		}
		return false;
	}

	/**
	 * Insert an array of raw rows. Returns a summary: inserted count and a
	 * list of WP_Error rejection messages (nothing is ever invented — a bad
	 * row is skipped and reported, not guessed at).
	 */
	public static function bulk_insert_questions( $rows, $replace_existing = false ) {
		global $wpdb;
		$table = QQ_DB::questions_table();

		if ( $replace_existing ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
		}

		$inserted = 0;
		$errors   = array();
		$seen     = array();

		foreach ( $rows as $i => $row ) {
			$clean = self::normalize_row( $row, $i + 1 );
			if ( is_wp_error( $clean ) ) {
				$errors[] = $clean->get_error_message();
				continue;
			}

			$dupe_key = strtolower( $clean['question'] ) . '|' . $clean['option_a'] . $clean['option_b'];
			// Duplicate question+options combos are allowed (the source data
			// legitimately reuses question phrasing for different verses),
			// so we do not skip them — just insert as-is.
			unset( $seen[ $dupe_key ] );

			$now = current_time( 'mysql' );
			$wpdb->insert( // phpcs:ignore
				$table,
				array(
					'question'       => $clean['question'],
					'qtype'          => $clean['qtype'],
					'option_a'       => $clean['option_a'],
					'option_b'       => $clean['option_b'],
					'option_c'       => $clean['option_c'],
					'option_d'       => $clean['option_d'],
					'correct_option' => $clean['correct_option'],
					'difficulty'     => $clean['difficulty'],
					'category'       => $clean['category'],
					'reference'      => $clean['reference'],
					'explanation'    => $clean['explanation'],
					'active'         => $clean['active'],
					'source_row'     => $clean['source_row'],
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			$inserted++;
		}

		return array(
			'inserted' => $inserted,
			'errors'   => $errors,
		);
	}

	/**
	 * Parse an uploaded CSV file (expected header row: question, option_a,
	 * option_b, option_c, option_d, correct_answer, difficulty[, category,
	 * reference, explanation]) into normalized rows ready for
	 * bulk_insert_questions().
	 */
	public static function parse_csv( $file_path ) {
		$rows = array();
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'qq_csv', 'Uploaded file could not be read.' );
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore
		if ( ! $handle ) {
			return new WP_Error( 'qq_csv', 'Uploaded file could not be opened.' );
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore
			return new WP_Error( 'qq_csv', 'CSV file appears to be empty.' );
		}
		$header = array_map( function ( $h ) {
			return strtolower( trim( preg_replace( '/\s+/', '_', (string) $h ) ) );
		}, $header );

		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( count( array_filter( $data, function ( $v ) { return trim( (string) $v ) !== ''; } ) ) === 0 ) {
				continue; // skip fully blank lines
			}
			$row = array();
			foreach ( $header as $idx => $key ) {
				$row[ $key ] = isset( $data[ $idx ] ) ? $data[ $idx ] : '';
			}
			// Common header aliases.
			if ( isset( $row['correct_answer'] ) && ! isset( $row['correct'] ) ) {
				$row['correct'] = $row['correct_answer'];
			}
			if ( isset( $row['questions'] ) && ! isset( $row['question'] ) ) {
				$row['question'] = $row['questions'];
			}
			$rows[] = $row;
		}
		fclose( $handle ); // phpcs:ignore

		return $rows;
	}
}
