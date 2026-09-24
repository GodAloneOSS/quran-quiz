<?php
/**
 * Uninstall handler.
 *
 * By default we do NOT delete quiz data when the plugin is removed — a site
 * owner who accidentally deletes the plugin should not lose 200+ curated
 * questions or every user's quiz history. To fully remove all plugin data
 * and tables, define QQ_REMOVE_DATA_ON_UNINSTALL as true in wp-config.php
 * before deleting the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'QQ_REMOVE_DATA_ON_UNINSTALL' ) && QQ_REMOVE_DATA_ON_UNINSTALL ) {
	global $wpdb;
	$prefix = $wpdb->prefix;
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}qq_attempt_answers" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}qq_attempts" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}qq_sessions" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}qq_users" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}qq_questions" ); // phpcs:ignore
	delete_option( 'qq_settings' );
	delete_option( 'qq_db_version' );
}
