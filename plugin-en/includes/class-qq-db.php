<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for table names and small shared DB helpers.
 */
class QQ_DB {

	public static function questions_table() {
		global $wpdb;
		return $wpdb->prefix . 'qq_questions';
	}

	public static function users_table() {
		global $wpdb;
		return $wpdb->prefix . 'qq_users';
	}

	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'qq_sessions';
	}

	public static function attempts_table() {
		global $wpdb;
		return $wpdb->prefix . 'qq_attempts';
	}

	public static function answers_table() {
		global $wpdb;
		return $wpdb->prefix . 'qq_attempt_answers';
	}

	/**
	 * Get a plugin setting (stored as a single serialized option for simplicity).
	 */
	public static function get_settings() {
		$defaults = array(
			'google_client_id'   => '',
			'questions_per_quiz' => QQ_DEFAULT_QUESTIONS_PER_QUIZ,
			'time_limit_seconds' => QQ_DEFAULT_TIME_LIMIT_SECONDS,
			'site_name'          => 'GodAlone.in',
			'certificate_title'  => 'Certificate of Achievement',
		);
		$saved = get_option( 'qq_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, $defaults );
	}

	public static function update_settings( $new_settings ) {
		$current = self::get_settings();
		$merged  = wp_parse_args( $new_settings, $current );
		update_option( 'qq_settings', $merged );
		return $merged;
	}
}
