<?php
/**
 * Opruimen bij verwijderen van de plugin.
 *
 * @package StagingSafety
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'staging_safety_settings' );
delete_option( 'staging_safety_version' );
delete_transient( 'staging_safety_paused' );

$timestamp = wp_next_scheduled( 'staging_safety_cleanup_log' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'staging_safety_cleanup_log' );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'staging_safety_log' );

// De transients van de cron-dempers.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ss_cron_%' OR option_name LIKE '_transient_timeout_ss_cron_%'" );
