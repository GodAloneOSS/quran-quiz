<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QQ_Activator {

	public static function activate() {
		self::create_tables();
		self::maybe_seed_questions();
		update_option( 'qq_db_version', QQ_DB_VERSION );
	}

	public static function deactivate() {
		// Intentionally left blank: we keep all quiz data (questions, users,
		// attempts) on deactivation. Data is only removed if the site owner
		// deletes the plugin AND uninstall.php runs (also non-destructive by
		// default — see uninstall.php).
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$questions = QQ_DB::questions_table();
		$users     = QQ_DB::users_table();
		$sessions  = QQ_DB::sessions_table();
		$attempts  = QQ_DB::attempts_table();
		$answers   = QQ_DB::answers_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$questions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			question TEXT NOT NULL,
			qtype VARCHAR(10) NOT NULL DEFAULT 'mcq',
			option_a TEXT NULL,
			option_b TEXT NULL,
			option_c TEXT NULL,
			option_d TEXT NULL,
			correct_option CHAR(1) NOT NULL,
			difficulty VARCHAR(10) NOT NULL DEFAULT 'medium',
			category VARCHAR(100) NULL,
			reference VARCHAR(191) NULL,
			explanation TEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			source_row INT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY difficulty (difficulty),
			KEY active (active)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$users} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			google_id VARCHAR(64) NOT NULL,
			name VARCHAR(191) NULL,
			email VARCHAR(191) NULL,
			photo_url TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_login_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY google_id (google_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token CHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$attempts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			difficulty VARCHAR(10) NOT NULL DEFAULT 'mixed',
			question_ids TEXT NOT NULL,
			total_questions INT NOT NULL DEFAULT 0,
			answered_count INT NOT NULL DEFAULT 0,
			correct_count INT NOT NULL DEFAULT 0,
			incorrect_count INT NOT NULL DEFAULT 0,
			unanswered_count INT NOT NULL DEFAULT 0,
			score INT NOT NULL DEFAULT 0,
			percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
			time_limit_seconds INT NOT NULL DEFAULT 600,
			time_taken_seconds INT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
			ended_reason VARCHAR(20) NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$answers} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attempt_id BIGINT UNSIGNED NOT NULL,
			question_id BIGINT UNSIGNED NOT NULL,
			selected_option CHAR(1) NULL,
			correct_option CHAR(1) NOT NULL,
			is_correct TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY attempt_id (attempt_id)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Load the bundled, pre-validated question bank on first install only.
	 * Re-importing afterwards is done deliberately from the admin screen so
	 * we never silently overwrite an admin's edits.
	 */
	private static function maybe_seed_questions() {
		global $wpdb;
		$table = QQ_DB::questions_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		$seed_file = QQ_PLUGIN_DIR . 'data/seed-questions.json';
		if ( ! file_exists( $seed_file ) ) {
			return;
		}

		$json = file_get_contents( $seed_file );
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			return;
		}

		require_once QQ_PLUGIN_DIR . 'includes/class-qq-importer.php';
		QQ_Importer::bulk_insert_questions( $rows );
	}
}
