<?php
if (!defined('ABSPATH')) exit;

/**
 * ✅ Guard: prevents "Cannot declare class SCM_Shortcode... already in use"
 */
if (class_exists('SCM_Shortcode')) {
    return;
}

class SCM_Shortcode {

    public static function init() {
        add_shortcode('verify_secure_code', array(__CLASS__, 'render_shortcode'));
    }

    public static function render_shortcode() {

        // ✅ Safety: if DB class missing, fallback to default max
        $max = class_exists('SCM_Database') ? SCM_Database::get_max_code() : 999999;

        wp_enqueue_style(
            'scm-frontend-css',
            SCM_PLUGIN_URL . 'assets/frontend.css',
            array(),
            SCM_VERSION
        );

        wp_enqueue_script(
            'scm-frontend-js',
            SCM_PLUGIN_URL . 'assets/frontend.js',
            array(),
            SCM_VERSION,
            true
        );

        wp_localize_script('scm-frontend-js', 'SCM_VERIFY', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('scm_verify_nonce'),
            'min'      => (int) SCM_MIN_CODE,
            'max'      => (int) $max,
        ));

        ob_start();
        ?>
        <div class="scm-wrap">
            <div class="scm-card">
                <div class="scm-title">Verify Code</div>

                <label class="scm-label" for="scm_code_input">Enter your code</label>
                <input
                    id="scm_code_input"
                    class="scm-input"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="Enter 6-digit code"
                />

                <button type="button" class="scm-btn" id="scm_verify_btn">Verify</button>

                <div class="scm-result" id="scm_result" aria-live="polite"></div>

                <div class="scm-hint">
                    Valid range: <?php echo (int) SCM_MIN_CODE; ?> to <?php echo (int) $max; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}