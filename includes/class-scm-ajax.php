<?php
if (!defined('ABSPATH')) exit;

/**
 * ✅ Guard: prevents "Cannot declare class SCM_Ajax... already in use"
 */
if (class_exists('SCM_Ajax')) {
    return;
}

class SCM_Ajax {

    public static function init() {
        add_action('wp_ajax_scm_verify_code', array(__CLASS__, 'verify_code'));
        add_action('wp_ajax_nopriv_scm_verify_code', array(__CLASS__, 'verify_code'));

        // Admin-only bulk generate (10,000 in batches)
        add_action('wp_ajax_scm_bulk_generate_codes', array(__CLASS__, 'bulk_generate_codes'));
    }

    public static function verify_code() {
        check_ajax_referer('scm_verify_nonce', 'nonce');

        if (!class_exists('SCM_Database')) {
            wp_send_json_error(array('message' => 'Server error: Database class missing.'), 500);
        }

        $max = SCM_Database::get_max_code();
        $raw = isset($_POST['code']) ? trim((string) $_POST['code']) : '';

        // Validate: digits only, at least 6 digits (min = 100000).
        if (!preg_match('/^\d{6,}$/', $raw)) {
            wp_send_json_error(array('message' => 'Invalid Code'));
        }

        $code = (int) $raw;

        if ($code < SCM_MIN_CODE || $code > $max) {
            wp_send_json_error(array('message' => 'Invalid Code'));
        }

        global $wpdb;
        $table = SCM_Database::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT code, status FROM {$table} WHERE code = %d LIMIT 1", $code),
            ARRAY_A
        );

        if (!$row) {
            wp_send_json_error(array('message' => 'Invalid Code'));
        }

        if ((int)$row['status'] === 1) {
            wp_send_json_error(array('message' => 'This number is already used'));
        }

        // Atomic lock to prevent race conditions.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 1, verified_at = %s
                 WHERE code = %d AND status = 0",
                current_time('mysql'),
                $code
            )
        );

        if ((int)$updated === 1) {
            wp_send_json_success(array('message' => 'Verified Original'));
        }

        // If not updated, someone else already verified it.
        wp_send_json_error(array('message' => 'This number is already used'));
    }

    public static function bulk_generate_codes() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }

        check_ajax_referer('scm_bulk_generate_codes_nonce', 'nonce');

        if (!class_exists('SCM_Database')) {
            wp_send_json_error(array('message' => 'Server error: Database class missing.'), 500);
        }

        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 0;

        // Basic safety: do not allow insane limit in one request.
        if ($limit < 1 || $limit > 5000) {
            wp_send_json_error(array('message' => 'Invalid batch size.'), 400);
        }

        try {
            $inserted = SCM_Database::bulk_generate_codes($limit);

            wp_send_json_success(array(
                'inserted' => (int) $inserted,
            ));
        } catch (\Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }
}