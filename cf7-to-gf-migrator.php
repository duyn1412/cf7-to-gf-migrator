<?php
/**
 * Plugin Name: CF7 to GF Migrator
 * Plugin URI:  https://github.com/duyn1412/cf7-to-gf-migrator
 * Description: Migrate forms from Contact Form 7 (CF7) to Gravity Forms (GF). Supports batch migration with full settings (email notifications, autoresponder, confirmations) and entry migration from CF7DB.
 * Version:     1.0.0
 * Author:      Duy Nguyen
 * Author URI:  https://wptopd3v.com/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-to-gf-migrator
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CF7GFM_VERSION', '1.0.0' );
define( 'CF7GFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF7GFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize plugin after all plugins are loaded.
 */
function cf7gfm_init() {
    $cf7_active = class_exists( 'WPCF7' ) || post_type_exists( 'wpcf7_contact_form' );
    if ( ! $cf7_active ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>CF7 to GF Migrator:</strong> Requires <strong>Contact Form 7</strong> to be active.</p></div>';
        } );
        return;
    }

    if ( ! class_exists( 'GFAPI' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>CF7 to GF Migrator:</strong> Requires <strong>Gravity Forms</strong> to be active.</p></div>';
        } );
        return;
    }

    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-migrator.php';
    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-entry-migrator.php';
    require_once CF7GFM_PLUGIN_DIR . 'admin/class-cf7-gf-admin.php';

    if ( is_admin() ) {
        new CF7GF_Admin();
    }
}
add_action( 'plugins_loaded', 'cf7gfm_init' );

// ──────────────────────────────────────────────────────
// CF7 Admin List Table: "Used On" column
// Hooks into the CF7 post type list at:
//   /wp-admin/edit.php?post_type=wpcf7_contact_form
// ──────────────────────────────────────────────────────

/**
 * Register the "Used On" column in the CF7 forms list table.
 */
add_filter( 'manage_wpcf7_contact_form_posts_columns', function ( $columns ) {
    // Insert after the 'title' column
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'title' ) {
            $new['cf7gfm_used_on'] = '📄 Used On';
        }
    }
    return $new;
} );

/**
 * Render the "Used On" column cell for each CF7 form.
 */
add_action( 'manage_wpcf7_contact_form_posts_custom_column', function ( $column, $post_id ) {
    if ( $column !== 'cf7gfm_used_on' ) {
        return;
    }

    // Reuse our migrator's get_form_usage() method
    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-migrator.php';
    $migrator = new CF7GF_Migrator();
    $pages    = $migrator->get_form_usage( $post_id );

    if ( empty( $pages ) ) {
        echo '<span style="color:#9ca3af;font-size:12px;">—</span>';
        return;
    }

    echo '<ul class="cf7gfm-col-used-on">';
    foreach ( $pages as $page ) {
        $status_dot = $page['status'] === 'publish' ? '&#x1F7E2;' : ( $page['status'] === 'draft' ? '&#x1F7E1;' : '&#x26AA;' );
        $type_label = ! in_array( $page['type'], [ 'page', 'post' ], true )
            ? ' <em>(' . esc_html( $page['type'] ) . ')</em>'
            : '';

        printf(
            '<li>%s <a href="%s" target="_blank">%s</a>%s <a href="%s" title="Edit" style="opacity:.5;text-decoration:none;" target="_blank">&#x270F;&#xFE0F;</a></li>',
            esc_html( $status_dot ),
            esc_url( $page['url'] ),
            esc_html( $page['title'] ),
            wp_kses( $type_label, [ 'em' => [] ] ),
            esc_url( $page['edit_url'] )
        );
    }
    echo '</ul>';
}, 10, 2 );

/**
 * Enqueue minimal inline CSS for the Used On column on CF7 list page.
 */
add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-wpcf7_contact_form' ) {
        return;
    }
    echo '<style>
        .column-cf7gfm_used_on { width: 220px; }
        .cf7gfm-col-used-on { margin: 0; padding: 0; list-style: none; }
        .cf7gfm-col-used-on li { display: flex; align-items: center; gap: 4px; font-size: 12px; margin-bottom: 4px; flex-wrap: wrap; }
        .cf7gfm-col-used-on a { color: #4f46e5; text-decoration: none; font-weight: 500; }
        .cf7gfm-col-used-on a:hover { text-decoration: underline; }
    </style>';
} );

/**
 * AJAX: Migrate a single CF7 form.
 */
function cf7gfm_ajax_migrate_form() {
    check_ajax_referer( 'cf7gfm_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }

    $cf7_id = intval( $_POST['cf7_id'] ?? 0 );
    if ( ! $cf7_id ) {
        wp_send_json_error( [ 'message' => 'Invalid form ID.' ] );
    }

    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-migrator.php';
    $migrator = new CF7GF_Migrator();
    $result   = $migrator->migrate_form( $cf7_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    $post = get_post( $cf7_id );
    wp_send_json_success( [
        'gf_id'    => $result,
        'gf_title' => sprintf( '[CF7-%d] %s', $cf7_id, $post->post_title ),
        'edit_url' => admin_url( 'admin.php?page=gf_edit_forms&id=' . $result ),
    ] );
}
add_action( 'wp_ajax_cf7gfm_migrate_form', 'cf7gfm_ajax_migrate_form' );

/**
 * AJAX: Migrate multiple CF7 forms at once.
 */
function cf7gfm_ajax_migrate_multiple() {
    check_ajax_referer( 'cf7gfm_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }

    $cf7_ids = array_map( 'intval', (array) ( $_POST['cf7_ids'] ?? [] ) );
    $cf7_ids = array_filter( $cf7_ids );

    if ( empty( $cf7_ids ) ) {
        wp_send_json_error( [ 'message' => 'Please select at least one form.' ] );
    }

    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-migrator.php';
    $migrator = new CF7GF_Migrator();
    $results  = $migrator->migrate_multiple( $cf7_ids );

    wp_send_json_success( $results );
}
add_action( 'wp_ajax_cf7gfm_migrate_multiple', 'cf7gfm_ajax_migrate_multiple' );

/**
 * AJAX: Get list of all CF7 forms.
 */
function cf7gfm_ajax_get_forms() {
    check_ajax_referer( 'cf7gfm_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }

    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-migrator.php';
    $migrator = new CF7GF_Migrator();
    wp_send_json_success( $migrator->get_all_cf7_forms() );
}
add_action( 'wp_ajax_cf7gfm_get_forms', 'cf7gfm_ajax_get_forms' );

/**
 * AJAX: Migrate entries for a CF7 form (from CF7DB) to Gravity Forms.
 */
function cf7gfm_ajax_migrate_entries() {
    check_ajax_referer( 'cf7gfm_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }

    $cf7_id     = intval( $_POST['cf7_id'] ?? 0 );
    $gf_id      = intval( $_POST['gf_id'] ?? 0 );
    $form_name  = sanitize_text_field( wp_unslash( $_POST['form_name'] ?? '' ) );
    $re_migrate = (bool) rest_sanitize_boolean( wp_unslash( $_POST['re_migrate'] ?? false ) );

    if ( ! $cf7_id || ! $gf_id || ! $form_name ) {
        wp_send_json_error( [ 'message' => 'Missing required form data.' ] );
    }

    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-entry-migrator.php';
    $migrator = new CF7GF_Entry_Migrator();

    if ( ! $migrator->table_exists() ) {
        wp_send_json_error( [ 'message' => 'CF7DB table does not exist. The contact-form-7-to-database-extension plugin may not be installed or active.' ] );
    }

    if ( $re_migrate ) {
        $migrator->reset_entry_log( $cf7_id );
    }

    $result = $migrator->migrate_entries( $cf7_id, $gf_id, $form_name, ! $re_migrate );

    wp_send_json_success( [
        'migrated'     => $result['migrated'],
        'skipped'      => $result['skipped'],
        'errors'       => $result['errors'],
        'total_in_gf'  => $migrator->get_migrated_count( $cf7_id ),
        'entries_url'  => admin_url( 'admin.php?page=gf_entries&id=' . $gf_id ),
    ] );
}
add_action( 'wp_ajax_cf7gfm_migrate_entries', 'cf7gfm_ajax_migrate_entries' );

/**
 * AJAX: Get entry counts from CF7DB for all forms.
 */
function cf7gfm_ajax_get_entry_counts() {
    check_ajax_referer( 'cf7gfm_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }

    require_once CF7GFM_PLUGIN_DIR . 'includes/class-cf7-gf-entry-migrator.php';
    $migrator = new CF7GF_Entry_Migrator();

    if ( ! $migrator->table_exists() ) {
        wp_send_json_success( [ 'has_cf7db' => false, 'counts' => [] ] );
        return;
    }

    wp_send_json_success( [
        'has_cf7db' => true,
        'counts'    => $migrator->get_forms_with_entries(),
    ] );
}
add_action( 'wp_ajax_cf7gfm_get_entry_counts', 'cf7gfm_ajax_get_entry_counts' );
