<?php
/**
 * KloudStack Migration REST API Endpoints
 *
 * Registers the following endpoints under /wp-json/kloudstack/v1/:
 *
 *   GET  /discover           — return site profile (WP version, plugins, theme, DB info)
 *   POST /validate           — validate plugin token, confirm connectivity
 *   POST /export-db          — start async DB export job (adds to BackgroundExport queue)
 *   GET  /job-status/{id}    — poll job status and progress percentage
 *   POST /upload-media       — start async media ZIP upload to Azure Blob (SAS URL provided)
 *   POST /media-files        — paginated list of media file paths (for incremental upload)
 *
 * Authentication:
 *   All endpoints require the X-KloudStack-Token header matching the stored plugin token.
 *   Constant-time comparison used to prevent timing attacks.
 *
 * @package KloudStackMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KloudStack_Migration_RestEndpoints {

    const NAMESPACE = 'kloudstack/v1';

    /** Transient prefix for async job records */
    const JOB_TRANSIENT_PREFIX = 'ks_mig_job_';

    /** Job TTL — 24 hours */
    const JOB_TTL = 86400;

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public static function register_routes(): void {
        $ns = self::NAMESPACE;

        register_rest_route( $ns, '/discover', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'discover' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'validate' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/export-db', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'export_db' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/job-status/(?P<job_id>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'job_status' ],
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/upload-media', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'upload_media' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/media-files', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'media_files' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/cancel-jobs', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'cancel_jobs' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );
    }

    // ------------------------------------------------------------------
    // Authentication middleware
    // ------------------------------------------------------------------

    /**
     * Verify the X-KloudStack-Token header using constant-time comparison.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function verify_token( WP_REST_Request $request ) {
        $stored_token = get_option( 'kloudstack_migration_token', '' );

        if ( empty( $stored_token ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Plugin token not configured. Visit Settings → KloudStack Migration.',
                [ 'status' => 403 ]
            );
        }

        $provided = $request->get_header( 'X-KloudStack-Token' );
        if ( empty( $provided ) ) {
            return new WP_Error(
                'rest_forbidden',
                'X-KloudStack-Token header is required.',
                [ 'status' => 403 ]
            );
        }

        // Constant-time comparison to prevent timing attacks
        if ( ! hash_equals( $stored_token, $provided ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Invalid token.',
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Endpoint: GET /discover
    // ------------------------------------------------------------------

    /**
     * Return the site profile for migration risk assessment.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function discover( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        // WordPress version
        $wp_version = get_bloginfo( 'version' );

        // Active plugins list
        $active_plugins  = get_option( 'active_plugins', [] );
        $plugin_count    = count( $active_plugins );
        $plugin_slugs    = array_map( function ( $path ) {
            return dirname( $path ) ?: basename( $path, '.php' );
        }, $active_plugins );

        // Active theme
        $theme = wp_get_theme();

        // Database size (MB)
        $db_size_result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ROUND( SUM( data_length + index_length ) / 1024 / 1024, 2 )
                 FROM information_schema.TABLES
                 WHERE table_schema = %s",
                DB_NAME
            )
        );
        $db_size_mb = (float) ( $db_size_result ?? 0 );

        // DB storage engine (InnoDB / MyISAM) from most-used table
        $db_engine_result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ENGINE, VERSION FROM information_schema.TABLES
                 WHERE table_schema = %s
                 ORDER BY data_length DESC
                 LIMIT 1",
                DB_NAME
            ),
            ARRAY_A
        );
        $db_engine  = $db_engine_result['ENGINE']  ?? 'InnoDB';
        $db_version = $db_engine_result['VERSION'] ?? '';

        // Actual DB server type and version (MySQL vs MariaDB)
        $db_server_version_raw = $wpdb->get_var( 'SELECT VERSION()' ) ?? '';
        // MariaDB reports e.g. "10.11.4-MariaDB" or "11.2.2-MariaDB"
        // MySQL reports e.g. "8.0.35"
        if ( stripos( $db_server_version_raw, 'mariadb' ) !== false ) {
            $db_server_type = 'MariaDB';
        } else {
            $db_server_type = 'MySQL';
        }
        $db_server_version = $db_server_version_raw;

        // Media library count
        $media_count = wp_count_attachments()->total ?? 0;

        // wp-content/uploads size estimate
        $uploads_dir  = wp_upload_dir();
        $uploads_size = self::_dir_size_mb( $uploads_dir['basedir'] );

        // Multisite check
        $is_multisite = is_multisite();

        // WP-Cron health
        $wp_cron_enabled   = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
        $cron_next_time    = wp_next_scheduled( KloudStack_Migration_BackgroundExport::CRON_HOOK );
        $export_queue      = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $jobs_in_queue     = count( $export_queue );

        return new WP_REST_Response( [
            'site_url'      => get_site_url(),
            'home_url'      => get_home_url(),
            'wp_version'    => $wp_version,
            'php_version'   => PHP_VERSION,
            'plugins'       => $plugin_slugs,
            'plugin_count'  => $plugin_count,
            'theme'         => $theme->get( 'Name' ),
            'theme_version' => $theme->get( 'Version' ),
            'db_engine'          => $db_engine,
            'db_version'         => $db_version,
            'db_server_type'     => $db_server_type,
            'db_server_version'  => $db_server_version,
            'db_size_mb'         => $db_size_mb,
            'db_name'       => DB_NAME,
            'media_count'   => (int) $media_count,
            'uploads_size_mb' => $uploads_size,
            'is_multisite'  => $is_multisite,
            'table_prefix'  => $wpdb->prefix,
            'wp_cron_enabled'      => $wp_cron_enabled,
            'cron_next_scheduled'  => $cron_next_time ? (int) $cron_next_time : null,
            'jobs_in_queue'        => $jobs_in_queue,
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /validate
    // ------------------------------------------------------------------

    /**
     * Validate that the plugin can connect and the token is correct.
     * Called by the Django backend after pairing to confirm connectivity.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function validate( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( [
            'valid'      => true,
            'site_url'   => get_site_url(),
            'wp_version' => get_bloginfo( 'version' ),
            'timestamp'  => time(),
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /export-db
    // ------------------------------------------------------------------

    /**
     * Start an async database export job.
     *
     * The actual export is performed by BackgroundExport::process_queue()
     * which runs via WP-Cron every minute. This endpoint enqueues the job
     * and returns a job_id for polling.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function export_db( WP_REST_Request $request ): WP_REST_Response {
        $sas_url = sanitize_url( $request->get_json_params()['sas_url'] ?? '' );

        $job_id = 'db_' . wp_generate_uuid4();

        $job = [
            'type'       => 'db_export',
            'status'     => 'queued',
            'progress'   => 0,
            'sas_url'    => $sas_url,
            'created_at' => time(),
            'blob_path'  => '',
            'error'      => null,
        ];

        set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );

        // Enqueue into BackgroundExport queue
        KloudStack_Migration_BackgroundExport::enqueue( $job_id, 'db_export', $sas_url );

        // Kick WP-Cron immediately so the job starts processing without waiting
        // for the next organic page visit to the source site.
        wp_remote_get( site_url( '/?doing_wp_cron' ), [
            'blocking'  => false,
            'timeout'   => 0.01,
            'sslverify' => false,
        ] );

        return new WP_REST_Response( [ 'job_id' => $job_id ], 202 );
    }

    // ------------------------------------------------------------------
    // Endpoint: GET /job-status/{job_id}
    // ------------------------------------------------------------------

    /**
     * Return current status and progress for an async job.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function job_status( WP_REST_Request $request ) {
        $job_id = $request->get_param( 'job_id' );
        $job    = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );

        if ( false === $job ) {
            return new WP_Error(
                'not_found',
                "Job {$job_id} not found or expired.",
                [ 'status' => 404 ]
            );
        }

        return new WP_REST_Response( array_merge( $job, [ 'job_id' => $job_id ] ), 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /upload-media
    // ------------------------------------------------------------------

    /**
     * Start an async media ZIP creation + upload to the provided SAS URL.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function upload_media( WP_REST_Request $request ): WP_REST_Response {
        $params  = $request->get_json_params();
        $sas_url = sanitize_url( $params['sas_url'] ?? '' );

        $job_id = 'media_' . wp_generate_uuid4();

        $job = [
            'type'       => 'media_upload',
            'status'     => 'queued',
            'progress'   => 0,
            'sas_url'    => $sas_url,
            'created_at' => time(),
            'error'      => null,
        ];

        set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );

        // Enqueue into BackgroundExport queue
        KloudStack_Migration_BackgroundExport::enqueue( $job_id, 'media_upload', $sas_url );

        // Kick WP-Cron immediately — same reason as export_db above.
        wp_remote_get( site_url( '/?doing_wp_cron' ), [
            'blocking'  => false,
            'timeout'   => 0.01,
            'sslverify' => false,
        ] );

        return new WP_REST_Response( [ 'job_id' => $job_id ], 202 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /media-files
    // ------------------------------------------------------------------

    /**
     * Return a paginated list of media file paths in wp-content/uploads.
     * Used for incremental upload verification.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function media_files( WP_REST_Request $request ): WP_REST_Response {
        $params   = $request->get_json_params();
        $page     = max( 1, (int) ( $params['page'] ?? 1 ) );
        $per_page = min( 500, max( 1, (int) ( $params['per_page'] ?? 100 ) ) );

        $uploads_dir = wp_upload_dir();
        $base_dir    = $uploads_dir['basedir'];
        $all_files   = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                // Return path relative to uploads dir
                $all_files[] = str_replace( $base_dir . DIRECTORY_SEPARATOR, '', $file->getPathname() );
            }
        }

        $total  = count( $all_files );
        $offset = ( $page - 1 ) * $per_page;
        $slice  = array_slice( $all_files, $offset, $per_page );

        return new WP_REST_Response( [
            'files'      => $slice,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'has_more'   => ( $offset + $per_page ) < $total,
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Cancel all queued/in-flight export jobs.
     *
     * Called by the KloudStack backend when a migration fails or is cancelled
     * so that the export queue is cleared and orphaned jobs do not keep running.
     *
     * Accepts an optional JSON body: { "job_ids": ["db_xxx", "media_xxx"] }
     * When job_ids is provided only those specific transients are cancelled;
     * when omitted the entire queue is flushed.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function cancel_jobs( WP_REST_Request $request ): WP_REST_Response {
        $params      = $request->get_json_params() ?? [];
        $target_ids  = isset( $params['job_ids'] ) && is_array( $params['job_ids'] )
            ? array_map( 'sanitize_key', $params['job_ids'] )
            : [];

        $queue    = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $removed  = [];
        $kept     = [];

        foreach ( $queue as $item ) {
            $job_id = $item['job_id'] ?? '';
            $should_remove = empty( $target_ids ) || in_array( $job_id, $target_ids, true );

            if ( $should_remove ) {
                // Mark the transient as cancelled so job-status polling gets a
                // definitive answer rather than returning 'queued' indefinitely.
                $existing = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
                if ( false !== $existing ) {
                    set_transient(
                        self::JOB_TRANSIENT_PREFIX . $job_id,
                        array_merge( $existing, [ 'status' => 'cancelled', 'error' => 'Cancelled by KloudStack platform.' ] ),
                        self::JOB_TTL
                    );
                }
                $removed[] = $job_id;
            } else {
                $kept[] = $item;
            }
        }

        // Persist the pruned queue.
        update_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, $kept, false );

        return new WP_REST_Response( [
            'cancelled' => $removed,
            'remaining' => count( $kept ),
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Recursively calculate directory size in MB.
     * Returns 0 if directory does not exist.
     */
    private static function _dir_size_mb( string $dir ): float {
        if ( ! is_dir( $dir ) ) {
            return 0.0;
        }
        $size     = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }
        return round( $size / 1024 / 1024, 2 );
    }
}
