<?php
/**
 * Admin page: CF7 to Gravity Forms Migrator
 * Menu: Tools → CF7 to GF Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CF7GF_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() {
        add_management_page(
            'CF7 to Gravity Forms Migrator',
            'CF7 → GF Migrator',
            'manage_options',
            'cf7-gf-migrator',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'tools_page_cf7-gf-migrator' ) {
            return;
        }

        wp_enqueue_style( 'cf7gfm-admin', CF7GFM_PLUGIN_URL . 'assets/admin.css', [], CF7GFM_VERSION );
        wp_enqueue_script( 'cf7gfm-admin', CF7GFM_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], CF7GFM_VERSION, true );

        wp_localize_script( 'cf7gfm-admin', 'cf7gfm', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cf7gfm_nonce' ),
            'strings' => [
                'loading'             => 'Loading forms...',
                'migrating'           => 'Migrating...',
                'migrate_done'        => 'Migration complete!',
                'migrate_error'       => 'Migration error:',
                'confirm_all'         => 'Are you sure you want to migrate ALL un-migrated forms?',
                'confirm_selected'    => 'Are you sure you want to migrate the selected forms?',
                'select_first'        => 'Please select at least one form.',
                'no_forms'            => 'No Contact Form 7 forms found.',
                'view_gf'             => 'View in GF',
                'remigrate'           => 'Re-migrate',
                'migrate'             => 'Migrate',
                'already_migrated'    => 'Already migrated',
                'migrate_entries'     => 'Migrate Entries',
                'remigrate_entries'   => 'Re-migrate entries',
                'view_entries'        => 'View Entries',
                'migrating_entries'   => 'Migrating entries...',
                'no_entries'          => 'No entries',
                'entries_migrated'    => 'entries migrated',
                'confirm_entries'     => 'Migrate all entries from this form to Gravity Forms?',
                'no_cf7db'            => 'CF7DB not available',
                'skipped'             => 'skipped',
                'errors'              => 'errors',
            ],
        ] );
    }

    public function render_page() {
        ?>
        <div class="wrap cf7gfm-wrap">
            <div class="cf7gfm-header">
                <div class="cf7gfm-header-inner">
                    <h1 class="cf7gfm-title">
                        <span class="dashicons dashicons-migrate"></span>
                        CF7 → Gravity Forms Migrator
                    </h1>
                    <p class="cf7gfm-subtitle">Migrate Contact Form 7 forms to Gravity Forms — including all fields, email notifications, autoresponders, messages, and submission entries.</p>
                </div>
            </div>

            <div class="cf7gfm-legend">
                <span class="cf7gfm-badge cf7gfm-badge--info">ℹ️ GF title format: <code>[CF7-{id}] Original Form Name</code></span>
                <span class="cf7gfm-badge cf7gfm-badge--success">✅ Migrated</span>
                <span class="cf7gfm-badge cf7gfm-badge--pending">⏳ Pending</span>
            </div>

            <div class="cf7gfm-toolbar">
                <div class="cf7gfm-toolbar-left">
                    <label>
                        <input type="checkbox" id="cf7gfm-select-all" />
                        Select all
                    </label>
                    <span class="cf7gfm-selected-count" id="cf7gfm-selected-count">0 forms selected</span>
                </div>
                <div class="cf7gfm-toolbar-right">
                    <button id="cf7gfm-migrate-selected" class="button button-primary cf7gfm-btn cf7gfm-btn--primary" disabled>
                        <span class="dashicons dashicons-migrate"></span>
                        Migrate Selected
                    </button>
                    <button id="cf7gfm-migrate-all" class="button cf7gfm-btn cf7gfm-btn--secondary">
                        <span class="dashicons dashicons-controls-play"></span>
                        Migrate All
                    </button>
                    <button id="cf7gfm-refresh" class="button cf7gfm-btn cf7gfm-btn--ghost">
                        <span class="dashicons dashicons-update"></span>
                        Refresh
                    </button>
                </div>
            </div>

            <div class="cf7gfm-progress-wrap" id="cf7gfm-progress-wrap" style="display:none;">
                <div class="cf7gfm-progress-bar">
                    <div class="cf7gfm-progress-fill" id="cf7gfm-progress-fill"></div>
                </div>
                <div class="cf7gfm-progress-text" id="cf7gfm-progress-text">0 / 0</div>
            </div>

            <div class="cf7gfm-result" id="cf7gfm-result" style="display:none;"></div>

            <div id="cf7gfm-table-wrap">
                <div class="cf7gfm-loading" id="cf7gfm-loading">
                    <span class="spinner is-active"></span>
                    Loading forms...
                </div>
                <table class="widefat cf7gfm-table" id="cf7gfm-table" style="display:none;">
                    <thead>
                        <tr>
                            <th class="cf7gfm-col-check"><input type="checkbox" id="cf7gfm-check-header" /></th>
                            <th>CF7 ID</th>
                            <th>Form Name (CF7)</th>
                            <th>Fields</th>
                            <th>Field Types</th>
                            <th>Status</th>
                            <th>GF Form</th>
                            <th>Used On</th>
                            <th>Entries (CF7DB)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cf7gfm-tbody">
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
