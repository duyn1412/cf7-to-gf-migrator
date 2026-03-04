<?php
/**
 * CF7 Redirect on Submission
 *
 * Ports the redirect-on-submit feature from the cf7-grid-layout plugin.
 * Stores a redirect page ID in post meta `_cf7sg_page_redirect` (same key
 * as cf7-grid-layout for compatibility — existing data is reused).
 *
 * Features:
 *  - Adds a meta box on the CF7 form edit screen.
 *  - Saves the selected page ID on form save.
 *  - Enqueues a tiny inline script on the frontend that listens to
 *    `wpcf7mailsent` and performs `window.location.href = redirect_url`.
 *
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CF7GFM_Redirect {

    const META_REDIRECT  = '_cf7sg_page_redirect';   // compatible with cf7-grid-layout
    const META_CUSTOM_URL = '_cf7gfm_redirect_custom_url'; // our own: custom URL

    public function __construct() {
        // Admin: meta box on CF7 form edit screen
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_wpcf7_contact_form', [ $this, 'save_meta' ], 10, 2 );

        // Frontend: inject redirect JS when a CF7 form is rendered
        add_filter( 'do_shortcode_tag', [ $this, 'maybe_enqueue_redirect_script' ], 10, 3 );

        // Admin CSS scoped to CF7 edit screen only
        add_action( 'admin_head', [ $this, 'admin_inline_css' ] );
    }

    // ──────────────────────────────────────────────────
    // Admin Meta Box
    // ──────────────────────────────────────────────────

    public function add_meta_box() {
        add_meta_box(
            'cf7gfm-redirect',
            '🔀 Redirect on Submission',
            [ $this, 'render_meta_box' ],
            'wpcf7_contact_form',
            'side',
            'default'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'cf7gfm_redirect_save', 'cf7gfm_redirect_nonce' );

        $page_id    = (int) get_post_meta( $post->ID, self::META_REDIRECT, true );
        $custom_url = get_post_meta( $post->ID, self::META_CUSTOM_URL, true );

        // Determine current mode
        $mode = 'none';
        if ( $page_id > 0 ) $mode = 'page';
        if ( $custom_url )  $mode = 'custom';
        ?>
        <div class="cf7gfm-redirect-box">

            <p>
                <label>
                    <input type="radio" name="cf7gfm_redirect_mode" value="none" <?php checked( $mode, 'none' ); ?>>
                    <strong>No redirect</strong> — show confirmation message
                </label>
            </p>

            <p>
                <label>
                    <input type="radio" name="cf7gfm_redirect_mode" value="page" <?php checked( $mode, 'page' ); ?>>
                    <strong>Redirect to a page</strong>
                </label>
            </p>

            <div class="cf7gfm-redirect-option" id="cf7gfm-redirect-page-wrap" <?php echo esc_attr( $mode ) === 'page' ? '' : 'style="display:none"'; ?>>
                <?php
                wp_dropdown_pages( [
                    'name'             => 'cf7gfm_redirect_page_id',
                    'id'               => 'cf7gfm-redirect-page-id',
                    'selected'         => absint( $page_id ),
                    'show_option_none' => '— Select a page —',
                    'option_none_value' => '0',
                ] );
                ?>
            </div>

            <p>
                <label>
                    <input type="radio" name="cf7gfm_redirect_mode" value="custom" <?php checked( $mode, 'custom' ); ?>>
                    <strong>Redirect to a custom URL</strong>
                </label>
            </p>

            <div class="cf7gfm-redirect-option" id="cf7gfm-redirect-custom-wrap" <?php echo $mode === 'custom' ? '' : 'style="display:none"'; ?>>
                <input type="url"
                       name="cf7gfm_redirect_custom_url"
                       id="cf7gfm-redirect-custom-url"
                       value="<?php echo esc_attr( $custom_url ); ?>"
                       placeholder="https://example.com/thank-you"
                       class="widefat">
                <p class="description">Absolute URL. You can use CF7 mail tags like <code>[your-name]</code> in the URL (e.g., for query strings).</p>
            </div>

            <p class="description" style="margin-top:10px;">
                ℹ️ The confirmation message will be replaced by a redirect after a successful submission.
            </p>

        </div><!-- .cf7gfm-redirect-box -->

        <script>
        (function($){
            $('input[name="cf7gfm_redirect_mode"]').on('change', function(){
                $('.cf7gfm-redirect-option').hide();
                if( this.value === 'page' )   $('#cf7gfm-redirect-page-wrap').show();
                if( this.value === 'custom' ) $('#cf7gfm-redirect-custom-wrap').show();
            });
        })(jQuery);
        </script>
        <?php
    }

    // ──────────────────────────────────────────────────
    // Save Meta
    // ──────────────────────────────────────────────────

    public function save_meta( $post_id, $post ) {
        if (
            ! isset( $_POST['cf7gfm_redirect_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cf7gfm_redirect_nonce'] ) ), 'cf7gfm_redirect_save' )
        ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $mode = sanitize_key( $_POST['cf7gfm_redirect_mode'] ?? 'none' );

        if ( $mode === 'page' ) {
            $page_id = absint( $_POST['cf7gfm_redirect_page_id'] ?? 0 );
            if ( $page_id > 0 ) {
                update_post_meta( $post_id, self::META_REDIRECT, $page_id );
            } else {
                delete_post_meta( $post_id, self::META_REDIRECT );
            }
            delete_post_meta( $post_id, self::META_CUSTOM_URL );

        } elseif ( $mode === 'custom' ) {
            $custom_url = esc_url_raw( wp_unslash( $_POST['cf7gfm_redirect_custom_url'] ?? '' ) );
            update_post_meta( $post_id, self::META_CUSTOM_URL, $custom_url );
            delete_post_meta( $post_id, self::META_REDIRECT );

        } else {
            delete_post_meta( $post_id, self::META_REDIRECT );
            delete_post_meta( $post_id, self::META_CUSTOM_URL );
        }
    }

    // ──────────────────────────────────────────────────
    // Frontend: inject redirect script into shortcode output
    // ──────────────────────────────────────────────────

    /**
     * Hooked on `do_shortcode_tag` — fires after every [contact-form-7] shortcode.
     * Injects a small inline script to perform the redirect on success.
     */
    public function maybe_enqueue_redirect_script( $output, $tag, $attr ) {
        if ( 'contact-form-7' !== $tag ) {
            return $output;
        }

        $cf7_id = isset( $attr['id'] ) ? (int) $attr['id'] : 0;
        if ( ! $cf7_id ) {
            return $output;
        }

        $redirect_url = $this->get_redirect_url( $cf7_id );

        if ( empty( $redirect_url ) ) {
            return $output; // no redirect configured
        }

        // Inject a unique JS snippet bound to this specific form instance.
        // We use the form's nonce field to identify it uniquely.
        $encoded_url = esc_js( $redirect_url );

        // CF7 fires `wpcf7mailsent` on the form's container element.
        // The event detail contains `contactFormId` (the CF7 post ID).
        $output .= sprintf(
            '<script>
(function(){
    "use strict";
    var cf7Id = %d;
    var redirectUrl = "%s";
    document.addEventListener("wpcf7mailsent", function(event){
        if( parseInt(event.detail.contactFormId, 10) === cf7Id ){
            setTimeout(function(){ window.location.href = redirectUrl; }, 300);
        }
    }, false);
})();
</script>',
            $cf7_id,
            $encoded_url
        );

        return $output;
    }

    /**
     * Resolve the redirect URL for a given CF7 form ID.
     * Priority: custom URL > page ID.
     *
     * @param int $cf7_id
     * @return string Absolute URL or empty string.
     */
    public function get_redirect_url( $cf7_id ) {
        // 1. Custom URL (our own meta key)
        $custom_url = get_post_meta( $cf7_id, self::META_CUSTOM_URL, true );
        if ( ! empty( $custom_url ) ) {
            return $custom_url;
        }

        // 2. Page ID (compatible with cf7-grid-layout's _cf7sg_page_redirect)
        $page_id = (int) get_post_meta( $cf7_id, self::META_REDIRECT, true );
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            return $url ? $url : '';
        }

        return '';
    }

    // ──────────────────────────────────────────────────
    // Admin CSS
    // ──────────────────────────────────────────────────

    public function admin_inline_css() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'wpcf7_contact_form' ) {
            return;
        }
        echo '<style>
            .cf7gfm-redirect-box p { margin: 6px 0; }
            .cf7gfm-redirect-box label { display: flex; align-items: flex-start; gap: 6px; cursor: pointer; font-size: 13px; }
            .cf7gfm-redirect-box input[type="radio"] { margin-top: 2px; flex-shrink: 0; }
            .cf7gfm-redirect-option { margin: 6px 0 10px 22px; }
            .cf7gfm-redirect-option select,
            .cf7gfm-redirect-option input[type="url"] { width: 100%; max-width: 100%; box-sizing: border-box; }
            .cf7gfm-redirect-option .description { font-size: 11px; margin-top: 4px; }
        </style>';
    }
}
