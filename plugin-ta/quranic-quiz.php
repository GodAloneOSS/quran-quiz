<?php
/**
 * Plugin Name: Quranic Quiz (Tamil)
 * Plugin URI: https://kadavulmattum.org/quran-qa/
 * Description: A beautiful, modern Quranic Quiz in Tamil with Google sign-in, timed 10-question rounds (Easy/Medium/Hard/Mixed), live scoring, quiz history and a downloadable certificate. Place [quranic_quiz] on any page.
 * Version: 1.0.0
 * Author: kadavulmattum.org
 * Text Domain: quranic-quiz-ta
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * Bismillah. "And say: My Lord, increase me in knowledge." (Quran 20:114)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'QQ_VERSION', '1.0.0' );
define( 'QQ_PLUGIN_FILE', __FILE__ );
define( 'QQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QQ_DB_VERSION', '1.0.0' );

// Default settings the plugin relies on if not yet configured in admin.
define( 'QQ_DEFAULT_QUESTIONS_PER_QUIZ', 10 );
define( 'QQ_DEFAULT_TIME_LIMIT_SECONDS', 600 ); // 10 minutes.

require_once QQ_PLUGIN_DIR . 'includes/class-qq-db.php';
require_once QQ_PLUGIN_DIR . 'includes/class-qq-activator.php';
require_once QQ_PLUGIN_DIR . 'includes/class-qq-auth.php';
require_once QQ_PLUGIN_DIR . 'includes/class-qq-importer.php';
require_once QQ_PLUGIN_DIR . 'includes/class-qq-rest.php';
require_once QQ_PLUGIN_DIR . 'includes/class-qq-shortcode.php';

if ( is_admin() ) {
	require_once QQ_PLUGIN_DIR . 'admin/class-qq-admin.php';
}

register_activation_hook( __FILE__, array( 'QQ_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'QQ_Activator', 'deactivate' ) );

/**
 * Boot the plugin.
 */
function qq_run_plugin() {
	QQ_REST::instance();
	QQ_Shortcode::instance();

	if ( is_admin() ) {
		QQ_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'qq_run_plugin' );

/**
 * Upgrade check: if the plugin was updated, re-run the schema/seed logic safely (idempotent).
 */
add_action( 'plugins_loaded', function () {
	$installed = get_option( 'qq_db_version' );
	if ( $installed !== QQ_DB_VERSION ) {
		QQ_Activator::activate();
	}
} );
