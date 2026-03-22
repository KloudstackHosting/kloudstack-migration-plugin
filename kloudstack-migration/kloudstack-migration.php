<?php
/**
 * Plugin Name: KloudStack Migration
 * Plugin URI:  https://kloudstack.com.au
 * Description: Enables secure WordPress site migration to the KloudStack PaaS Platform.
 *              Provides REST API endpoints for database export, media upload, and site validation.
 * Version:     1.2.0
 * Author:      KloudStack
 * Author URI:  https://kloudstack.com.au
 * License:     Proprietary
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Text Domain: kloudstack-migration
 *
 * @package KloudStackMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // WordPress required — never load directly.
}

define( 'KLOUDSTACK_MIGRATION_VERSION', '1.0.0' );
define( 'KLOUDSTACK_MIGRATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KLOUDSTACK_MIGRATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// -------------------------------------------------------------------------
// Autoload includes
// -------------------------------------------------------------------------

require_once KLOUDSTACK_MIGRATION_PLUGIN_DIR . 'includes/RestEndpoints.php';
require_once KLOUDSTACK_MIGRATION_PLUGIN_DIR . 'includes/BackgroundExport.php';
require_once KLOUDSTACK_MIGRATION_PLUGIN_DIR . 'admin/AdminPage.php';

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

add_action( 'rest_api_init', [ 'KloudStack_Migration_RestEndpoints', 'register_routes' ] );
add_action( 'init',          [ 'KloudStack_Migration_BackgroundExport', 'setup_cron' ] );
add_action( 'admin_menu',    [ 'KloudStack_Migration_AdminPage', 'register_menu' ] );

// -------------------------------------------------------------------------
// Activation / deactivation hooks
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, 'kloudstack_migration_activate' );
register_deactivation_hook( __FILE__, 'kloudstack_migration_deactivate' );

function kloudstack_migration_activate(): void {
    // Store plugin token placeholder on activation (customer configures it in admin UI)
    if ( ! get_option( 'kloudstack_migration_token' ) ) {
        add_option( 'kloudstack_migration_token', '' );
    }
    // Ensure background export cron is scheduled
    KloudStack_Migration_BackgroundExport::setup_cron();
    flush_rewrite_rules();
}

function kloudstack_migration_deactivate(): void {
    // Remove scheduled cron events
    $timestamp = wp_next_scheduled( 'kloudstack_process_export_queue' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'kloudstack_process_export_queue' );
    }
    flush_rewrite_rules();
}
