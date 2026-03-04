<?php
/**
 * CF7 to Gravity Forms Migrator - Core Migration Logic
 *
 * - GF form title format: "[CF7-{id}] Original Title"
 * - Migrates: form fields, _mail (notification), _mail_2 (autoresponder),
 *             _messages (custom confirmations)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CF7GFM_Migrator {

    const MIGRATION_LOG_OPTION = 'cf7gfm_migration_log';

    /**
     * Get all CF7 forms with their migration status.
     */
    public function get_all_cf7_forms() {
        $posts = get_posts( [
            'post_type'      => 'wpcf7_contact_form',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $log   = get_option( self::MIGRATION_LOG_OPTION, [] );
        $forms = [];

        foreach ( $posts as $post ) {
            $form_content = get_post_meta( $post->ID, '_form', true );
            $tags         = $this->parse_cf7_tags( $form_content );

            $gf_id       = $log[ $post->ID ] ?? null;
            $gf_edit_url = null;
            $gf_title    = null;

            if ( $gf_id && class_exists( 'GFAPI' ) ) {
                $gf_form = GFAPI::get_form( $gf_id );
                if ( ! $gf_form ) {
                    unset( $log[ $post->ID ] );
                    update_option( self::MIGRATION_LOG_OPTION, $log );
                    $gf_id = null;
                } else {
                    $gf_edit_url = admin_url( 'admin.php?page=gf_edit_forms&id=' . $gf_id );
                    $gf_title    = $gf_form['title'];
                }
            }

            $forms[] = [
                'cf7_id'      => $post->ID,
                'title'       => $post->post_title,
                'post_name'   => $post->post_name,
                'field_count' => count( $tags ),
                'fields'      => $tags,
                'gf_id'       => $gf_id,
                'gf_title'    => $gf_title,
                'gf_edit_url' => $gf_edit_url,
                'migrated'    => ! empty( $gf_id ),
                'used_on'     => $this->get_form_usage( $post->ID ),
            ];
        }

        return $forms;
    }

    /**
     * Find all published posts/pages that use a specific CF7 form shortcode.
     *
     * Searches `post_content` for:
     *   [contact-form-7 id="X" ...]
     *   [contact-form-7 id='X' ...]
     *   [contact-form-7 id=X ...]
     *
     * Also searches common page builder serialized meta (Elementor, Divi, Beaver Builder)
     * stored in post_meta.
     *
     * @param int $cf7_id CF7 form post ID.
     * @return array [ [ 'id' => post_id, 'title' => '...', 'url' => '...', 'edit_url' => '...', 'type' => 'page' ] ]
     */
    public function get_form_usage( $cf7_id ) {
        global $wpdb;

        $like_double = '%[contact-form-7 id="' . $cf7_id . '"%';
        $like_single = "%[contact-form-7 id='" . $cf7_id . "'%";
        $like_bare   = '%[contact-form-7 id=' . $cf7_id . '%';

        // Search in post_content (classic editor, blocks, Gutenberg, raw shortcodes)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT ID, post_title, post_type, post_status
                 FROM {$wpdb->posts}
                 WHERE post_status IN ('publish','private','draft')
                   AND post_type NOT IN ('wpcf7_contact_form','revision','attachment','nav_menu_item')
                   AND (
                       post_content LIKE %s
                    OR post_content LIKE %s
                    OR post_content LIKE %s
                   )
                 ORDER BY post_title ASC
                 LIMIT 50",
                $like_double,
                $like_single,
                $like_bare
            )
        );

        // Also search post_meta for page builders (Elementor stores JSON in _elementor_data, etc.)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_status IN ('publish','private','draft')
                   AND p.post_type NOT IN ('wpcf7_contact_form','revision','attachment','nav_menu_item')
                   AND pm.meta_key IN ('_elementor_data','et_pb_post_type_layout','fl_builder_data','_fusion_builder_content')
                   AND (
                       pm.meta_value LIKE %s
                    OR pm.meta_value LIKE %s
                    OR pm.meta_value LIKE %s
                   )
                 ORDER BY p.post_title ASC
                 LIMIT 50",
                $like_double,
                $like_single,
                $like_bare
            )
        );

        // Merge and deduplicate
        $all_rows = array_merge( $rows, $meta_rows );
        $seen     = [];
        $result   = [];

        foreach ( $all_rows as $row ) {
            if ( isset( $seen[ $row->ID ] ) ) continue;
            $seen[ $row->ID ] = true;

            $result[] = [
                'id'       => (int) $row->ID,
                'title'    => $row->post_title,
                'type'     => $row->post_type,
                'status'   => $row->post_status,
                'url'      => get_permalink( $row->ID ),
                'edit_url' => get_edit_post_link( $row->ID, 'raw' ),
            ];
        }

        return $result;
    }

    /**
     * Migrate a single CF7 form to Gravity Forms.
     * Title format: "[CF7-{cf7_id}] Original Title"
     *
     * @param int $cf7_id CF7 form post ID.
     * @return int|WP_Error GF form ID on success, WP_Error on failure.
     */
    public function migrate_form( $cf7_id ) {
        $post = get_post( $cf7_id );
        if ( ! $post || $post->post_type !== 'wpcf7_contact_form' ) {
            return new WP_Error( 'invalid_form', 'CF7 form not found with ID: ' . $cf7_id );
        }

        $form_content        = get_post_meta( $cf7_id, '_form', true );
        $mail_meta           = get_post_meta( $cf7_id, '_mail', true );
        $mail2_meta          = get_post_meta( $cf7_id, '_mail_2', true );
        $messages_meta       = get_post_meta( $cf7_id, '_messages', true );
        $additional_settings = get_post_meta( $cf7_id, '_additional_settings', true );

        $cf7_tags  = $this->parse_cf7_tags( $form_content );
        $gf_fields = $this->convert_tags_to_gf_fields( $cf7_tags );

        $gf_title     = sprintf( '[CF7-%d] %s', $cf7_id, $post->post_title );
        $submit_label = $this->get_submit_label( $form_content );

        $confirmation_msg = '<p>Thank you for your message. We will get back to you as soon as possible.</p>';
        if ( ! empty( $messages_meta['mail_sent_ok'] ) ) {
            $confirmation_msg = '<p>' . esc_html( $messages_meta['mail_sent_ok'] ) . '</p>';
        }

        // Check for redirect page set by cf7-grid-layout (_cf7sg_page_redirect)
        $redirect_page_id = (int) get_post_meta( $cf7_id, '_cf7sg_page_redirect', true );
        $redirect_url     = $redirect_page_id > 0 ? get_permalink( $redirect_page_id ) : '';

        if ( $redirect_page_id > 0 ) {
            // GF "Page" confirmation: pageId must be a STRING, and "page" key is also required
            // Exact format from GF export JSON
            $confirmation = [
                'id'              => uniqid(),
                'name'            => 'Default Confirmation',
                'isDefault'       => true,
                'type'            => 'page',
                'message'         => '',
                'url'             => '',
                'pageId'          => (string) $redirect_page_id,  // must be string
                'page'            => (string) $redirect_page_id,  // GF also expects this duplicate key
                'event'           => '',
                'disableAutoformat' => false,
                'queryString'     => '',
                'conditionalLogic' => [],
            ];
        } else {
            $confirmation = [
                'id'              => uniqid(),
                'name'            => 'Default Confirmation',
                'isDefault'       => true,
                'type'            => 'message',
                'message'         => $confirmation_msg,
                'url'             => '',
                'pageId'          => '',
                'page'            => '',
                'event'           => '',
                'disableAutoformat' => false,
                'queryString'     => '',
                'conditionalLogic' => [],
            ];
        }

        $gf_form = [
            'title'                => $gf_title,
            'description'          => sprintf( 'Migrated from Contact Form 7 (ID: %d)', $cf7_id ),
            'labelPlacement'       => 'top_label',
            'descriptionPlacement' => 'below',
            'button'               => [
                'type'     => 'text',
                'text'     => $submit_label,
                'imageUrl' => '',
            ],
            'fields'               => $gf_fields,
            'version'              => '2.5',
            'notifications'        => $this->build_notifications( $mail_meta, $mail2_meta, $cf7_tags ),
            'confirmations'        => [ $confirmation ],
        ];

        // Check if this CF7 form was already migrated — if so, UPDATE the existing GF form
        $log            = get_option( self::MIGRATION_LOG_OPTION, [] );
        // Cast to string to handle PHP int/string array key mismatch after serialization
        $existing_gf_id = isset( $log[ (string) $cf7_id ] ) ? (int) $log[ (string) $cf7_id ] : 0;
        // Also try int key as fallback
        if ( ! $existing_gf_id && isset( $log[ $cf7_id ] ) ) {
            $existing_gf_id = (int) $log[ $cf7_id ];
        }

        if ( $existing_gf_id ) {
            $existing_form = GFAPI::get_form( $existing_gf_id );
            if ( $existing_form ) {
                // Delete any duplicate GF forms with the same [CF7-X] title prefix
                $this->delete_duplicate_gf_forms( $cf7_id, $existing_gf_id );

                // UPDATE the canonical GF form
                $gf_form['id'] = $existing_gf_id;
                $result = GFAPI::update_form( $gf_form );
                if ( $result === false ) {
                    return new WP_Error( 'update_failed', 'Failed to update Gravity Forms form ID: ' . $existing_gf_id );
                }
                $gf_id = $existing_gf_id;
            } else {
                // Canonical GF form was deleted — create a fresh one (no duplicates expected)
                $gf_id = GFAPI::add_form( $gf_form );
                if ( is_wp_error( $gf_id ) ) {
                    return $gf_id;
                }
            }
        } else {
            // First migration — just create, no cleanup needed
            $gf_id = GFAPI::add_form( $gf_form );
            if ( is_wp_error( $gf_id ) ) {
                return $gf_id;
            }
        }

        $log[ $cf7_id ] = $gf_id;
        update_option( self::MIGRATION_LOG_OPTION, $log );

        return $gf_id;
    }

    /**
     * Delete duplicate GF forms that have the same CF7 ID in their title.
     * Title format: "[CF7-{id}] ..." — any form matching this pattern
     * except $keep_gf_id will be deleted.
     *
     * @param int $cf7_id       CF7 form post ID.
     * @param int $keep_gf_id  GF form ID to keep (0 = delete all matches).
     */
    private function delete_duplicate_gf_forms( $cf7_id, $keep_gf_id = 0 ) {
        if ( ! class_exists( 'GFAPI' ) ) return;

        $prefix = sprintf( '[CF7-%d]', $cf7_id );
        $forms  = GFAPI::get_forms();

        foreach ( $forms as $form ) {
            if ( (int) $form['id'] === $keep_gf_id ) {
                continue; // keep the canonical form
            }
            // Match title starting with [CF7-{id}]
            if ( isset( $form['title'] ) && strpos( $form['title'], $prefix ) === 0 ) {
                GFAPI::delete_form( $form['id'] );
            }
        }
    }

    /**
     * Migrate multiple CF7 forms at once.
     *
     * @param array $cf7_ids
     * @return array { success: [], errors: [] }
     */
    public function migrate_multiple( $cf7_ids ) {
        $results = [ 'success' => [], 'errors' => [] ];

        foreach ( $cf7_ids as $cf7_id ) {
            $cf7_id = intval( $cf7_id );
            $result = $this->migrate_form( $cf7_id );

            if ( is_wp_error( $result ) ) {
                $results['errors'][] = [
                    'cf7_id'  => $cf7_id,
                    'message' => $result->get_error_message(),
                ];
            } else {
                $post = get_post( $cf7_id );
                $results['success'][] = [
                    'cf7_id'   => $cf7_id,
                    'gf_id'    => $result,
                    'title'    => sprintf( '[CF7-%d] %s', $cf7_id, $post->post_title ),
                    'edit_url' => admin_url( 'admin.php?page=gf_edit_forms&id=' . $result ),
                ];
            }
        }

        return $results;
    }

    /**
     * Parse CF7 shortcode tags from form content.
     */
    public function parse_cf7_tags( $content ) {
        $tags = [];
        $pattern = '/\[([a-zA-Z0-9_\-\*]+)\s*([a-zA-Z0-9_\-]*)\s*([^\]]*)\]/';
        preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER );

        $skip_types = [ 'submit', 'response', 'recaptcha', 'recaptcha_v3', 'quiz', 'acceptance_as_validation' ];

        foreach ( $matches as $match ) {
            $full_tag = $match[0];
            $type_raw = $match[1];
            $type     = rtrim( $type_raw, '*' );
            $required = str_ends_with( $type_raw, '*' );
            $name     = trim( $match[2] );
            $options  = trim( $match[3] );

            if ( in_array( $type, $skip_types, true ) ) {
                continue;
            }

            $label_text    = $this->extract_label_for_tag( $content, $full_tag, $name );
            $has_html_label = $this->tag_has_html_label( $content, $full_tag );
            $choices        = $this->extract_choices( $options );
            $placeholder    = $this->extract_placeholder( $options );
            $css_class      = $this->extract_option_pattern( $options, '/class:([^\s"]+)/' );
            $css_id         = $this->extract_option_pattern( $options, '/id:([^\s"]+)/' );

            $tags[] = [
                'type'           => $type,
                'name'           => $name ?: sanitize_key( $type . '_' . count( $tags ) ),
                'required'       => $required,
                'label'          => $label_text ?: ucwords( str_replace( [ '-', '_' ], ' ', $name ) ),
                'has_html_label' => $has_html_label,
                'choices'        => $choices,
                'placeholder'    => $placeholder,
                'css_class'      => $css_class,
                'css_id'         => $css_id,
            ];
        }

        return array_values( array_filter( $tags, fn( $t ) => ! empty( $t['name'] ) ) );
    }

    private function convert_tags_to_gf_fields( $tags ) {
        $fields   = [];
        $field_id = 1;

        foreach ( $tags as $tag ) {
            $gf_field = $this->map_tag_to_gf_field( $tag, $field_id );
            if ( $gf_field ) {
                $fields[] = $gf_field;
                $field_id++;
            }
        }

        return $fields;
    }

    private function map_tag_to_gf_field( $tag, $field_id ) {
        $type_map = [
            'text'       => 'text',
            'email'      => 'email',
            'textarea'   => 'textarea',
            'tel'        => 'phone',
            'select'     => 'select',
            'checkbox'   => 'checkbox',
            'radio'      => 'radio',
            'file'       => 'fileupload',
            'url'        => 'website',
            'number'     => 'number',
            'date'       => 'date',
            'hidden'     => 'hidden',
            'acceptance' => 'checkbox',
            'password'   => 'password',
        ];

        $gf_type = $type_map[ $tag['type'] ] ?? 'text';

        $field = [
            'id'                   => $field_id,
            'type'                 => $gf_type,
            'label'                => $tag['label'],
            'adminLabel'           => '',
            'isRequired'           => $tag['required'],
            'size'                 => 'large',
            'errorMessage'         => '',
            'visibility'           => $gf_type === 'hidden' ? 'hidden' : 'visible',
            'inputs'               => null,
            'formId'               => 0,
            'pageNumber'           => 1,
            'cssClass'             => $tag['css_class'] ?? '',
            'inputName'            => $tag['name'],
            'description'          => '',
            'descriptionPlacement' => 'below',
            'labelPlacement'       => '',
        ];

        // If CF7 field has no HTML label and uses a placeholder, hide the GF label
        if ( ! ( $tag['has_html_label'] ?? true ) && ! empty( $tag['placeholder'] ) ) {
            $field['labelPlacement'] = 'hidden_label';
            // Use placeholder as label text too (so GF admin still shows something meaningful)
            if ( empty( $field['label'] ) || $field['label'] === ucwords( str_replace( [ '-', '_' ], ' ', $tag['name'] ) ) ) {
                $field['label'] = $tag['placeholder'];
            }
        }

        if ( ! empty( $tag['placeholder'] ) ) {
            $field['placeholder'] = $tag['placeholder'];
        }

        if ( in_array( $gf_type, [ 'select', 'checkbox', 'radio' ], true ) && ! empty( $tag['choices'] ) ) {
            $field['choices'] = array_map( fn( $c ) => [
                'text'       => $c,
                'value'      => $c,
                'isSelected' => false,
                'price'      => '',
            ], $tag['choices'] );
        }

        if ( $tag['type'] === 'acceptance' ) {
            $field['choices'] = [ [
                'text'       => $tag['label'],
                'value'      => '1',
                'isSelected' => false,
                'price'      => '',
            ] ];
        }

        if ( $gf_type === 'textarea' )    { $field['useRichTextEditor'] = false; $field['size'] = 'medium'; }
        if ( $gf_type === 'email' )       { $field['emailConfirmEnabled'] = false; }
        if ( $gf_type === 'phone' )       { $field['phoneFormat'] = 'standard'; }
        if ( $gf_type === 'date' )        { $field['dateFormat'] = 'mdy'; $field['dateType'] = 'datepicker'; }
        if ( $gf_type === 'fileupload' )  { $field['multipleFiles'] = false; $field['maxFiles'] = ''; $field['maxFileSize'] = ''; $field['allowedExtensions'] = ''; }
        if ( $gf_type === 'number' )      { $field['numberFormat'] = 'decimal_dot'; }

        if ( $gf_type === 'checkbox' && ! empty( $field['choices'] ) ) {
            $inputs = [];
            foreach ( $field['choices'] as $idx => $choice ) {
                $inputs[] = [ 'id' => $field_id . '.' . ( $idx + 1 ), 'label' => $choice['text'], 'name' => '' ];
            }
            $field['inputs'] = $inputs;
        }

        return $field;
    }

    /**
     * Build GF notifications from CF7 _mail and _mail_2 metadata.
     */
    private function build_notifications( $mail_meta, $mail2_meta, $tags ) {
        $notifications = [];
        $name_to_id    = $this->build_name_to_id_map( $tags );

        $to      = is_array( $mail_meta ) ? ( $mail_meta['recipient'] ?? get_option( 'admin_email' ) ) : get_option( 'admin_email' );
        $subject = is_array( $mail_meta ) ? ( $mail_meta['subject'] ?? 'New Form Submission' ) : 'New Form Submission';
        $body    = is_array( $mail_meta ) ? ( $mail_meta['body'] ?? '{all_fields}' ) : '{all_fields}';

        $reply_to = '';
        foreach ( $tags as $idx => $tag ) {
            if ( $tag['type'] === 'email' ) {
                $field_id = $idx + 1;
                $label    = ! empty( $tag['label'] ) ? $tag['label'] : 'Email';
                $reply_to = '{' . $label . ':' . $field_id . '}';
                break;
            }
        }

        $notifications[] = [
            'id'                => uniqid(),
            'name'              => 'Admin Notification',
            'event'             => 'form_submission',
            'to'                => $this->convert_mail_tags( $to, $name_to_id ),
            'toType'            => 'email',
            'from'              => '{admin_email}',
            'fromName'          => get_bloginfo( 'name' ),
            'replyTo'           => $reply_to,
            'subject'           => $this->convert_mail_tags( $subject, $name_to_id ),
            'message'           => '{all_fields}',
            'isActive'          => true,
            'disableAutoformat' => false,
            'enableAttachments' => false,
        ];

        if ( ! empty( $mail2_meta ) && is_array( $mail2_meta ) && ! empty( $mail2_meta['active'] ) ) {
            $auto_to      = $mail2_meta['recipient'] ?? '';
            $auto_subject = $mail2_meta['subject'] ?? 'Thank you for contacting us';
            $auto_body    = $mail2_meta['body'] ?? 'Thank you for your message.';

            $notifications[] = [
                'id'                => uniqid(),
                'name'              => 'Autoresponder',
                'event'             => 'form_submission',
                'to'                => $this->convert_mail_tags( $auto_to, $name_to_id ),
                'toType'            => 'email',
                'from'              => '{admin_email}',
                'fromName'          => get_bloginfo( 'name' ),
                'replyTo'           => '{admin_email}',
                'subject'           => $this->convert_mail_tags( $auto_subject, $name_to_id ),
                'message'           => '{all_fields}',
                'isActive'          => true,
                'disableAutoformat' => false,
                'enableAttachments' => false,
            ];
        }

        return $notifications;
    }

    private function build_name_to_id_map( $tags ) {
        $map = [];
        foreach ( $tags as $idx => $tag ) {
            $map[ $tag['name'] ] = $idx + 1;
        }
        return $map;
    }

    private function convert_mail_tags( $text, $name_to_id ) {
        $cf7_meta_map = [
            '_site_title'  => '{site_title}',
            '_site_url'    => '{site_url}',
            '_date'        => '{date_mdy}',
            '_time'        => '{time_12}',
            '_user_name'   => '{user_display_name}',
            '_user_email'  => '{user_email}',
            '_all_fields_' => '{all_fields}',
            '_all_fields'  => '{all_fields}',
        ];

        $text = preg_replace_callback( '/\[([a-zA-Z0-9_\-]+)\]/', function ( $m ) use ( $name_to_id, $cf7_meta_map ) {
            $key = $m[1];
            if ( isset( $name_to_id[ $key ] ) ) return '{' . $name_to_id[ $key ] . '}';
            return $cf7_meta_map[ $key ] ?? $m[0];
        }, $text );

        return $text ?: '{all_fields}';
    }

    private function get_submit_label( $content ) {
        if ( preg_match( '/\[submit\s+"([^"]+)"\]/', $content, $m ) ) return $m[1];
        if ( preg_match( "/\[submit\s+'([^']+)'\]/", $content, $m ) ) return $m[1];
        return 'Send';
    }

    /**
     * Check whether a CF7 tag is wrapped in an HTML <label> in the form content.
     */
    private function tag_has_html_label( $content, $full_tag ) {
        $escaped = preg_quote( $full_tag, '/' );
        return (bool) preg_match( '/<label[^>]*>.*?' . $escaped . '.*?<\/label>/si', $content );
    }

    private function extract_label_for_tag( $content, $full_tag, $name ) {
        $escaped = preg_quote( $full_tag, '/' );
        if ( preg_match( '/<label[^>]*>\s*(.*?)\s*' . $escaped . '.*?<\/label>/si', $content, $m ) ) {
            $label = trim( wp_strip_all_tags( $m[1] ) );
            if ( $label ) return $label;
        }
        if ( preg_match( '/<label[^>]*>\s*' . $escaped . '\s*(.*?)\s*<\/label>/si', $content, $m ) ) {
            $label = trim( wp_strip_all_tags( $m[1] ) );
            if ( $label ) return $label;
        }
        return '';
    }

    /**
     * Extract placeholder from CF7 tag options.
     * Supports both formats:
     *   placeholder:value          (colon format)
     *   placeholder "value"        (CF7 native: space + quoted string after 'placeholder' keyword)
     */
    private function extract_placeholder( $options ) {
        // Format 1: placeholder:value
        if ( preg_match( '/\bplaceholder:([^\s\]]+)/', $options, $m ) ) {
            return trim( $m[1], '"\' ' );
        }
        // Format 2: placeholder "text" or placeholder 'text' (CF7 native format)
        if ( preg_match( '/\bplaceholder\s+["\']([^"\']+)["\']/', $options, $m ) ) {
            return $m[1];
        }
        return '';
    }

    private function extract_choices( $options ) {
        preg_match_all( '/["\']([^"\']+)["\']/', $options, $m );
        return $m[1] ?? [];
    }

    private function extract_option( $options, $name ) {
        if ( preg_match( '/' . preg_quote( $name, '/' ) . ':([^\s\]]+)/', $options, $m ) ) {
            return trim( $m[1], '"\' ' );
        }
        return '';
    }

    private function extract_option_pattern( $options, $pattern ) {
        if ( preg_match( $pattern, $options, $m ) ) return $m[1];
        return '';
    }
}
