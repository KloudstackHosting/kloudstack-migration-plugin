<?php
/**
 * KloudStack Migration — WordPress Admin Page
 *
 * Adds a "KloudStack Migration" menu item under Settings.
 * Allows site admins to:
 *   - Enter/update the plugin token provided by KloudStack
 *   - View connection status (validated against backend or just stored)
 *   - View the current migration stage (polling Django backend not implemented
 *     here — kept simple — status is informational only)
 *   - See basic troubleshooting guidance
 *
 * @package KloudStackMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KloudStack_Migration_AdminPage {

    const OPTION_TOKEN    = 'kloudstack_migration_token';
    const OPTION_SITE_URL = 'kloudstack_migration_site_url';  // For reference
    const PAGE_SLUG       = 'kloudstack-migration';

    // ------------------------------------------------------------------
    // Menu registration
    // ------------------------------------------------------------------

    public static function register_menu(): void {
        add_options_page(
            'KloudStack Migration',          // Page title
            'KloudStack Migration',          // Menu label
            'manage_options',                // Required capability
            self::PAGE_SLUG,                 // Menu slug
            [ __CLASS__, 'render_page' ]
        );
    }

    // ------------------------------------------------------------------
    // Settings registration
    // ------------------------------------------------------------------

    public static function register_settings(): void {
        register_setting(
            'kloudstack_migration_settings',
            self::OPTION_TOKEN,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );
    }

    // ------------------------------------------------------------------
    // Page render
    // ------------------------------------------------------------------

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'kloudstack-migration' ) );
        }

        // Handle form submission
        $notice = '';
        if ( isset( $_POST['kloudstack_migration_save'] ) ) {
            check_admin_referer( 'kloudstack_migration_save_token' );

            $raw_token = sanitize_text_field( wp_unslash( $_POST[ self::OPTION_TOKEN ] ?? '' ) );
            update_option( self::OPTION_TOKEN, $raw_token );
            $notice = '<div class="notice notice-success is-dismissible"><p>Token saved successfully.</p></div>';
        }

        $stored_token = get_option( self::OPTION_TOKEN, '' );
        $has_token    = ! empty( $stored_token );
        $masked_token = $has_token ? substr( $stored_token, 0, 6 ) . str_repeat( '*', max( 0, strlen( $stored_token ) - 6 ) ) : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'KloudStack Migration', 'kloudstack-migration' ); ?></h1>

            <?php echo wp_kses_post( $notice ); ?>

            <p>
                The KloudStack Migration plugin connects your site to the KloudStack PaaS Platform
                to perform a secure, automated migration. Enter the plugin token provided by your
                KloudStack account manager below.
            </p>

            <form method="post" action="">
                <?php wp_nonce_field( 'kloudstack_migration_save_token' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr( self::OPTION_TOKEN ); ?>">
                                <?php esc_html_e( 'Plugin Token', 'kloudstack-migration' ); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="<?php echo esc_attr( self::OPTION_TOKEN ); ?>"
                                name="<?php echo esc_attr( self::OPTION_TOKEN ); ?>"
                                value="<?php echo esc_attr( $stored_token ); ?>"
                                class="regular-text"
                                autocomplete="off"
                                placeholder="Paste token from KloudStack dashboard"
                            />
                            <?php if ( $has_token ) : ?>
                                <p class="description">
                                    ✅ Token stored: <code><?php echo esc_html( $masked_token ); ?></code>
                                </p>
                            <?php else : ?>
                                <p class="description" style="color:#c00;">
                                    ⚠ No token configured. REST endpoints are disabled until a token is entered.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input
                        type="submit"
                        name="kloudstack_migration_save"
                        class="button button-primary"
                        value="<?php esc_attr_e( 'Save Token', 'kloudstack-migration' ); ?>"
                    />
                </p>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Migration Status', 'kloudstack-migration' ); ?></h2>
            <?php self::render_status_panel(); ?>

            <hr />

            <h2><?php esc_html_e( 'Troubleshooting', 'kloudstack-migration' ); ?></h2>
            <ul>
                <li>Ensure this site is publicly accessible over HTTPS.</li>
                <li>The KloudStack Migration REST API is available at:
                    <code><?php echo esc_html( get_rest_url( null, 'kloudstack/v1' ) ); ?></code>
                </li>
                <li>If WP-Cron is disabled, background exports will not run automatically.
                    Contact your host or configure <code>DISABLE_WP_CRON</code> to false.</li>
                <li>Plugin version: <strong><?php echo esc_html( KLOUDSTACK_MIGRATION_VERSION ); ?></strong></li>
            </ul>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Status panel
    // ------------------------------------------------------------------

    private static function render_status_panel(): void {
        // Check for any active export jobs in queue
        $queue       = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $queue_depth = count( $queue );

        echo '<table class="widefat" style="max-width:600px;">';
        echo '<thead><tr><th>Item</th><th>Value</th></tr></thead>';
        echo '<tbody>';

        self::_status_row( 'Plugin Token Configured', get_option( self::OPTION_TOKEN ) ? '✅ Yes' : '❌ No' );
        self::_status_row( 'REST Endpoint Base', esc_html( get_rest_url( null, 'kloudstack/v1' ) ) );
        self::_status_row( 'WP-Cron Enabled', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? '⚠ Disabled' : '✅ Enabled' );
        self::_status_row( 'Jobs in Queue', (string) $queue_depth );
        self::_status_row( 'PHP Version', PHP_VERSION );
        self::_status_row( 'mysqldump Available', self::_command_exists( 'mysqldump' ) ? '✅ Yes' : '⚠ Not found in PATH' );
        self::_status_row( 'ZipArchive Available', class_exists( 'ZipArchive' ) ? '✅ Yes' : '❌ No — media export unavailable' );

        echo '</tbody></table>';
    }

    private static function _status_row( string $label, string $value ): void {
        printf(
            '<tr><td><strong>%s</strong></td><td>%s</td></tr>',
            esc_html( $label ),
            wp_kses_post( $value )
        );
    }

    private static function _command_exists( string $command ): bool {
        $which = shell_exec( 'which ' . escapeshellarg( $command ) . ' 2>/dev/null' );
        return ! empty( trim( (string) $which ) );
    }
}

// Register settings on init
add_action( 'admin_init', [ 'KloudStack_Migration_AdminPage', 'register_settings' ] );
