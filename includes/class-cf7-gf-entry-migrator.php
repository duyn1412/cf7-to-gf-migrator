<?php
/**
 * CF7DB Entry Migrator
 *
 * Reads entries from CF7DB plugin's table (wp_cf7dbplugin_submits)
 * and imports them as Gravity Forms entries via GFAPI::add_entry().
 *
 * CF7DB Schema:
 *   submit_time  DECIMAL(16,4)  -- unique submission identifier (unix timestamp)
 *   form_name    VARCHAR(127)   -- CF7 form title stored by CF7DB
 *   field_name   VARCHAR(127)   -- field name
 *   field_value  LONGTEXT       -- field value
 *   field_order  INTEGER
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CF7GF_Entry_Migrator {

    const ENTRY_LOG_OPTION = 'cf7gfm_entry_migration_log';
    const CF7DB_TABLE      = 'cf7dbplugin_submits';

    private function get_table() {
        global $wpdb;
        return $wpdb->prefix . self::CF7DB_TABLE;
    }

    public function table_exists() {
        global $wpdb;
        $table = $this->get_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    public function get_entry_count( $form_name ) {
        global $wpdb;
        $table = esc_sql( $this->get_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(DISTINCT submit_time) FROM `{$table}` WHERE form_name = %s", $form_name ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Get all CF7 form names that have entries in CF7DB with their counts.
     *
     * @return array [ form_name => entry_count ]
     */
    public function get_forms_with_entries() {
        global $wpdb;
        $table = esc_sql( $this->get_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $rows  = $wpdb->get_results(
            "SELECT form_name, COUNT(DISTINCT submit_time) as entry_count FROM `{$table}` GROUP BY form_name ORDER BY form_name" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row->form_name ] = (int) $row->entry_count;
        }
        return $result;
    }

    /**
     * Migrate all entries for a specific form from CF7DB to Gravity Forms.
     *
     * @param int    $cf7_id         CF7 form post ID.
     * @param int    $gf_form_id     Target GF form ID.
     * @param string $form_name      CF7 form name (title) as stored in CF7DB.
     * @param bool   $skip_existing  Skip already-migrated entries.
     * @return array { migrated: int, skipped: int, errors: string[] }
     */
    public function migrate_entries( $cf7_id, $gf_form_id, $form_name, $skip_existing = true ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return [ 'migrated' => 0, 'skipped' => 0, 'errors' => [ 'CF7DB table does not exist.' ] ];
        }

        $table    = esc_sql( $this->get_table() );
        $gf_form  = GFAPI::get_form( $gf_form_id );
        $log      = get_option( self::ENTRY_LOG_OPTION, [] );
        $form_log = $log[ $cf7_id ] ?? [];

        $result = [ 'migrated' => 0, 'skipped' => 0, 'errors' => [] ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $submit_times = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT submit_time FROM `{$table}` WHERE form_name = %s ORDER BY submit_time ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $form_name
            )
        );

        if ( empty( $submit_times ) ) {
            return $result;
        }

        foreach ( $submit_times as $submit_time ) {
            if ( $skip_existing && isset( $form_log[ $submit_time ] ) ) {
                $result['skipped']++;
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT field_name, field_value FROM `{$table}` WHERE form_name = %s AND submit_time = %s ORDER BY field_order ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $form_name,
                    $submit_time
                )
            );

            $submission = [];
            foreach ( $rows as $row ) {
                $submission[ $row->field_name ] = $row->field_value;
            }

            $gf_entry = $this->build_gf_entry( $submission, $gf_form, $gf_form_id, $submit_time );
            $entry_id = GFAPI::add_entry( $gf_entry );

            if ( is_wp_error( $entry_id ) ) {
                $result['errors'][] = 'submit_time=' . $submit_time . ': ' . $entry_id->get_error_message();
            } else {
                $result['migrated']++;
                $form_log[ $submit_time ] = $entry_id;
            }
        }

        $log[ $cf7_id ] = $form_log;
        update_option( self::ENTRY_LOG_OPTION, $log );

        return $result;
    }

    /**
     * Build a GF entry array from a CF7DB submission.
     */
    private function build_gf_entry( $submission, $gf_form, $gf_form_id, $submit_time ) {
        // Build field name → GF field map
        $name_to_field = [];
        if ( ! empty( $gf_form['fields'] ) ) {
            foreach ( $gf_form['fields'] as $field ) {
                $input_name = $field['inputName'] ?? '';
                if ( $input_name ) {
                    $name_to_field[ $input_name ] = $field;
                }
            }
        }

        $entry = [
            'form_id'      => $gf_form_id,
            'date_created' => gmdate( 'Y-m-d H:i:s', (int) $submit_time ),
            'is_read'      => 0,
            'is_starred'   => 0,
            'status'       => 'active',
            'currency'     => 'USD',
            'ip'           => $submission['_remote_ip'] ?? $submission['_ip'] ?? '',
            'source_url'   => $submission['_url'] ?? $submission['_page_url'] ?? '',
            'created_by'   => '',
        ];

        foreach ( $submission as $field_name => $field_value ) {
            // Skip CF7 internal meta fields
            if ( str_starts_with( $field_name, '_' ) || str_starts_with( $field_name, 'wpcf7' ) ) {
                continue;
            }

            $gf_field = $name_to_field[ $field_name ] ?? null;

            if ( $gf_field ) {
                $field_id = $gf_field['id'];
                $gf_type  = $gf_field['type'];

                if ( $gf_type === 'checkbox' && ! empty( $gf_field['choices'] ) ) {
                    $selected = array_map( 'trim', explode( ',', $field_value ) );
                    foreach ( $gf_field['choices'] as $c_idx => $choice ) {
                        $sub_id        = $field_id . '.' . ( $c_idx + 1 );
                        $entry[ $sub_id ] = in_array( $choice['value'], $selected, true ) ? $choice['value'] : '';
                    }
                } else {
                    $entry[ $field_id ] = $field_value;
                }
            }
        }

        return $entry;
    }

    public function reset_entry_log( $cf7_id ) {
        $log = get_option( self::ENTRY_LOG_OPTION, [] );
        unset( $log[ $cf7_id ] );
        update_option( self::ENTRY_LOG_OPTION, $log );
    }

    public function get_migrated_count( $cf7_id ) {
        $log = get_option( self::ENTRY_LOG_OPTION, [] );
        return count( $log[ $cf7_id ] ?? [] );
    }
}
