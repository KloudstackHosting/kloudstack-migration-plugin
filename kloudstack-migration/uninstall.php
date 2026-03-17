<?php
/**
 * KloudStack Migration — Uninstall Cleanup
 *
 * Runs automatically when the plugin is deleted from the WordPress admin
 * (Plugins > Delete). Removes all stored options and scheduled cron events
 * so the site is left in a clean state.
 *
 * @package KloudStackMigration
 */

// WordPress safety check — must be called from within WordPress uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Remove stored options ─────────────────────────────────────────────────────

delete_option( 'kloudstack_migration_token' );
delete_option( 'kloudstack_migration_site_url' );
delete_option( 'kloudstack_export_queue' );

// ── Clear scheduled cron events ───────────────────────────────────────────────

$timestamp = wp_next_scheduled( 'kloudstack_process_export_queue' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'kloudstack_process_export_queue' );
}
wp_clear_scheduled_hook( 'kloudstack_process_export_queue' );
