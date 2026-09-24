<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for the quiz. Correct answers are never sent to the browser
 * before an attempt is submitted — the server keeps the answer key and
 * grades server-side, so the quiz can't be cheated by reading page source
 * or network responses.
 */
class QQ_REST {

	const NAMESPACE_ = 'quranic-quiz/v1';
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
		return self::$instance;
	}

	private function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route( self::NAMESPACE_, '/auth/google', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'auth_google' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'credential' => array( 'required' => true ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/auth/guest', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'auth_guest' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'name' => array( 'required' => false ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/auth/logout', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'auth_logout' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE_, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'me' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NAMESPACE_, '/questions/random', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'start_attempt' ),
			'permission_callback' => array( $this, 'require_login' ),
			'args'                => array(
				'difficulty' => array( 'default' => 'mixed' ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/attempts/(?P<id>\d+)/submit', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'submit_attempt' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NAMESPACE_, '/attempts/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'history' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NAMESPACE_, '/attempts/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'attempt_detail' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );
	}

	/* ---------------------------------------------------------------- */
	/* Auth                                                              */
	/* ---------------------------------------------------------------- */

	public function require_login( WP_REST_Request $request ) {
		$user = QQ_Auth::get_current_user( $request );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$request->set_param( '_qq_user', $user );
		return true;
	}

	public function auth_google( WP_REST_Request $request ) {
		$credential = $request->get_param( 'credential' );
		$result     = QQ_Auth::login_with_google( $credential );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	public function auth_guest( WP_REST_Request $request ) {
		$name   = $request->get_param( 'name' );
		$result = QQ_Auth::login_as_guest( $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	public function auth_logout( WP_REST_Request $request ) {
		QQ_Auth::logout( $request );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function me( WP_REST_Request $request ) {
		$user = $request->get_param( '_qq_user' );
		global $wpdb;
		$attempts_table = QQ_DB::attempts_table();
		$stats = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
			"SELECT COUNT(*) as total_attempts, MAX(percentage) as best_percentage, AVG(percentage) as avg_percentage
			 FROM {$attempts_table} WHERE user_id = %d AND status = 'completed'",
			$user['id']
		), ARRAY_A );

		return new WP_REST_Response( array(
			'id'       => (int) $user['id'],
			'name'     => $user['name'],
			'email'    => $user['email'],
			'photo'    => $user['photo_url'],
			'is_guest' => QQ_Auth::is_guest_row( $user ),
			'stats'  => array(
				'total_attempts'  => (int) ( $stats['total_attempts'] ?? 0 ),
				'best_percentage' => $stats['best_percentage'] !== null ? round( (float) $stats['best_percentage'], 1 ) : null,
				'avg_percentage'  => $stats['avg_percentage'] !== null ? round( (float) $stats['avg_percentage'], 1 ) : null,
			),
		), 200 );
	}

	/* ---------------------------------------------------------------- */
	/* Quiz flow                                                         */
	/* ---------------------------------------------------------------- */

	public function start_attempt( WP_REST_Request $request ) {
		$user       = $request->get_param( '_qq_user' );
		$difficulty = strtolower( sanitize_text_field( $request->get_param( 'difficulty' ) ) );
		if ( ! in_array( $difficulty, array( 'easy', 'medium', 'hard', 'mixed' ), true ) ) {
			$difficulty = 'mixed';
		}

		$settings = QQ_DB::get_settings();
		$count    = max( 1, intval( $settings['questions_per_quiz'] ) );
		$time_limit = max( 30, intval( $settings['time_limit_seconds'] ) );

		global $wpdb;
		$q_table = QQ_DB::questions_table();

		if ( 'mixed' === $difficulty ) {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
				"SELECT * FROM {$q_table} WHERE active = 1 ORDER BY RAND() LIMIT %d",
				$count
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
				"SELECT * FROM {$q_table} WHERE active = 1 AND difficulty = %s ORDER BY RAND() LIMIT %d",
				$difficulty, $count
			), ARRAY_A );
		}

		if ( count( $rows ) < 1 ) {
			return new WP_Error( 'qq_no_questions', 'No questions are available for this difficulty yet. Please try another category.', array( 'status' => 404 ) );
		}

		$question_ids = wp_list_pluck( $rows, 'id' );

		$attempts_table = QQ_DB::attempts_table();
		$now = current_time( 'mysql' );
		$wpdb->insert( // phpcs:ignore
			$attempts_table,
			array(
				'user_id'            => $user['id'],
				'difficulty'         => $difficulty,
				'question_ids'       => wp_json_encode( $question_ids ),
				'total_questions'    => count( $rows ),
				'time_limit_seconds' => $time_limit,
				'status'             => 'in_progress',
				'started_at'         => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		$attempt_id = (int) $wpdb->insert_id;

		$questions_out = array_map( array( $this, 'question_for_client' ), $rows );

		return new WP_REST_Response( array(
			'attempt_id'         => $attempt_id,
			'difficulty'         => $difficulty,
			'time_limit_seconds' => $time_limit,
			'questions'          => $questions_out,
		), 200 );
	}

	private function question_for_client( $row ) {
		$options = array();
		foreach ( array( 'A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d' ) as $letter => $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				$options[] = array( 'key' => $letter, 'text' => $row[ $key ] );
			}
		}
		return array(
			'id'         => (int) $row['id'],
			'type'       => $row['qtype'],
			'question'   => $row['question'],
			'options'    => $options,
			'difficulty' => $row['difficulty'],
			// correct_option intentionally omitted.
		);
	}

	public function submit_attempt( WP_REST_Request $request ) {
		$user       = $request->get_param( '_qq_user' );
		$attempt_id = intval( $request->get_param( 'id' ) );

		global $wpdb;
		$attempts_table = QQ_DB::attempts_table();
		$attempt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempts_table} WHERE id = %d", $attempt_id ), ARRAY_A ); // phpcs:ignore

		if ( ! $attempt ) {
			return new WP_Error( 'qq_not_found', 'Quiz attempt not found.', array( 'status' => 404 ) );
		}
		if ( (int) $attempt['user_id'] !== (int) $user['id'] ) {
			return new WP_Error( 'qq_forbidden', 'This quiz attempt does not belong to you.', array( 'status' => 403 ) );
		}
		if ( 'in_progress' !== $attempt['status'] ) {
			return new WP_Error( 'qq_already_submitted', 'This quiz has already been submitted.', array( 'status' => 409 ) );
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$answers_in = isset( $body['answers'] ) && is_array( $body['answers'] ) ? $body['answers'] : array();
		// Normalize to question_id => selected_option map.
		$answer_map = array();
		foreach ( $answers_in as $a ) {
			if ( isset( $a['question_id'] ) ) {
				$answer_map[ intval( $a['question_id'] ) ] = isset( $a['selected'] ) ? strtoupper( substr( trim( $a['selected'] ), 0, 1 ) ) : null;
			}
		}

		$time_taken   = isset( $body['time_taken_seconds'] ) ? max( 0, intval( $body['time_taken_seconds'] ) ) : 0;
		$ended_reason = isset( $body['ended_reason'] ) && in_array( $body['ended_reason'], array( 'submitted', 'time_up' ), true ) ? $body['ended_reason'] : 'submitted';

		$question_ids = json_decode( $attempt['question_ids'], true );
		if ( ! is_array( $question_ids ) || empty( $question_ids ) ) {
			return new WP_Error( 'qq_corrupt_attempt', 'This attempt could not be graded.', array( 'status' => 500 ) );
		}

		$placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
		$q_table = QQ_DB::questions_table();
		$questions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q_table} WHERE id IN ({$placeholders})", $question_ids ), ARRAY_A ); // phpcs:ignore
		$questions_by_id = array();
		foreach ( $questions as $q ) {
			$questions_by_id[ (int) $q['id'] ] = $q;
		}

		$answers_table = QQ_DB::answers_table();
		$correct = 0; $incorrect = 0; $unanswered = 0;
		$breakdown = array();

		foreach ( $question_ids as $qid ) {
			$qid = intval( $qid );
			if ( ! isset( $questions_by_id[ $qid ] ) ) {
				continue;
			}
			$q = $questions_by_id[ $qid ];
			$selected = isset( $answer_map[ $qid ] ) ? $answer_map[ $qid ] : null;
			$is_correct = ( $selected !== null && $selected === $q['correct_option'] ) ? 1 : 0;

			if ( null === $selected || '' === $selected ) {
				$unanswered++;
			} elseif ( $is_correct ) {
				$correct++;
			} else {
				$incorrect++;
			}

			$wpdb->insert( // phpcs:ignore
				$answers_table,
				array(
					'attempt_id'      => $attempt_id,
					'question_id'     => $qid,
					'selected_option' => $selected,
					'correct_option'  => $q['correct_option'],
					'is_correct'      => $is_correct,
				),
				array( '%d', '%d', '%s', '%s', '%d' )
			);

			$breakdown[] = array(
				'question_id'     => $qid,
				'question'        => $q['question'],
				'type'            => $q['qtype'],
				'options'         => $this->question_for_client( $q )['options'],
				'selected'        => $selected,
				'correct'         => $q['correct_option'],
				'is_correct'      => (bool) $is_correct,
				'reference'       => $q['reference'],
				'explanation'     => $q['explanation'],
			);
		}

		$total      = count( $question_ids );
		$score      = $correct;
		$percentage = $total > 0 ? round( ( $correct / $total ) * 100, 2 ) : 0;

		$wpdb->update( // phpcs:ignore
			$attempts_table,
			array(
				'answered_count'    => $correct + $incorrect,
				'correct_count'     => $correct,
				'incorrect_count'   => $incorrect,
				'unanswered_count'  => $unanswered,
				'score'             => $score,
				'percentage'        => $percentage,
				'time_taken_seconds'=> $time_taken,
				'status'            => 'completed',
				'ended_reason'      => $ended_reason,
				'finished_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $attempt_id ),
			array( '%d', '%d', '%d', '%d', '%d', '%f', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return new WP_REST_Response( array(
			'attempt_id'    => $attempt_id,
			'difficulty'    => $attempt['difficulty'],
			'total'         => $total,
			'score'         => $score,
			'percentage'    => $percentage,
			'correct'       => $correct,
			'incorrect'     => $incorrect,
			'unanswered'    => $unanswered,
			'time_taken_seconds' => $time_taken,
			'ended_reason'  => $ended_reason,
			'user_name'     => $user['name'],
			'breakdown'     => $breakdown,
		), 200 );
	}

	public function history( WP_REST_Request $request ) {
		$user = $request->get_param( '_qq_user' );
		global $wpdb;
		$attempts_table = QQ_DB::attempts_table();
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT id, difficulty, total_questions, score, percentage, time_taken_seconds, status, ended_reason, started_at, finished_at
			 FROM {$attempts_table} WHERE user_id = %d AND status = 'completed' ORDER BY finished_at DESC LIMIT 100",
			$user['id']
		), ARRAY_A );

		return new WP_REST_Response( array( 'attempts' => $rows ), 200 );
	}

	public function attempt_detail( WP_REST_Request $request ) {
		$user       = $request->get_param( '_qq_user' );
		$attempt_id = intval( $request->get_param( 'id' ) );

		global $wpdb;
		$attempts_table = QQ_DB::attempts_table();
		$attempt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempts_table} WHERE id = %d", $attempt_id ), ARRAY_A ); // phpcs:ignore

		if ( ! $attempt || (int) $attempt['user_id'] !== (int) $user['id'] ) {
			return new WP_Error( 'qq_not_found', 'Quiz attempt not found.', array( 'status' => 404 ) );
		}

		$answers_table = QQ_DB::answers_table();
		$q_table       = QQ_DB::questions_table();
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT a.selected_option, a.correct_option, a.is_correct, q.question, q.qtype, q.option_a, q.option_b, q.option_c, q.option_d, q.reference, q.explanation, q.id as question_id
			 FROM {$answers_table} a INNER JOIN {$q_table} q ON q.id = a.question_id
			 WHERE a.attempt_id = %d ORDER BY a.id ASC",
			$attempt_id
		), ARRAY_A );

		$breakdown = array_map( function ( $r ) {
			return array(
				'question_id' => (int) $r['question_id'],
				'question'    => $r['question'],
				'type'        => $r['qtype'],
				'options'     => $this->question_for_client( $r )['options'],
				'selected'    => $r['selected_option'],
				'correct'     => $r['correct_option'],
				'is_correct'  => (bool) $r['is_correct'],
				'reference'   => $r['reference'],
				'explanation' => $r['explanation'],
			);
		}, $rows );

		$attempt['breakdown'] = $breakdown;
		return new WP_REST_Response( $attempt, 200 );
	}
}
