<?php
if (!defined('ABSPATH')) exit;

/**
 * ✅ Guard: prevents "Cannot declare class SCM_Database... already in use"
 */
if (class_exists('SCM_Database')) {
    return;
}

class SCM_Database {

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'scm_codes';
    }

    public static function activate() {
        self::create_table();

        if (!get_option(SCM_OPT_MAX_CODE)) {
            update_option(SCM_OPT_MAX_CODE, 999999, false);
        }
        update_option(SCM_OPT_DB_VERSION, SCM_VERSION, false);
    }

    public static function create_table() {
        global $wpdb;

        $table = self::table_name();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // Added: printed_at (keeps everything else)
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code INT UNSIGNED NOT NULL,
            status TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            printed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY created_at (created_at),
            KEY printed_at (printed_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function get_max_code() {
        $max = (int) get_option(SCM_OPT_MAX_CODE, 999999);
        if ($max < SCM_MIN_CODE) $max = 999999;
        return $max;
    }

    public static function get_current_max_generated_code() {
        global $wpdb;
        $table = self::table_name();
        $val = $wpdb->get_var("SELECT MAX(code) FROM {$table}");
        return $val ? (int)$val : 0;
    }

    public static function count_total_codes_in_range($min, $max) {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE code BETWEEN %d AND %d", $min, $max)
        );
    }

    private static function printed_at_exists() {
        static $has_printed_at = null;
        if ($has_printed_at !== null) return $has_printed_at;

        global $wpdb;
        $table = self::table_name();

        $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'printed_at'");
        $has_printed_at = !empty($col);

        return $has_printed_at;
    }

    public static function insert_code($code) {
        global $wpdb;
        $table = self::table_name();

        $has_printed_at = self::printed_at_exists();

        // Avoid noisy DB errors for duplicate (unique index will reject).
        $wpdb->suppress_errors(true);

        if ($has_printed_at) {
            $inserted = $wpdb->insert(
                $table,
                array(
                    'code'       => (int) $code,
                    'status'     => 0,
                    'created_at' => current_time('mysql'),
                    'verified_at'=> null,
                    'printed_at' => null,
                ),
                array('%d', '%d', '%s', '%s', '%s')
            );
        } else {
            // Backward compatible insert (for DBs that still don't have printed_at column)
            $inserted = $wpdb->insert(
                $table,
                array(
                    'code'       => (int) $code,
                    'status'     => 0,
                    'created_at' => current_time('mysql'),
                    'verified_at'=> null,
                ),
                array('%d', '%d', '%s', '%s')
            );
        }

        $wpdb->suppress_errors(false);

        return $inserted ? true : false;
    }

    /**
     * Existing Export helpers (kept for compatibility).
     */
    public static function export_get_codes($status = 'all', $limit = 2000, $offset = 0) {
        global $wpdb;
        $table = self::table_name();

        $status = in_array($status, array('all', 'used', 'unused'), true) ? $status : 'all';
        $limit  = max(1, min(5000, (int) $limit));
        $offset = max(0, (int) $offset);

        $where = '1=1';
        if ($status === 'used') {
            $where .= ' AND status = 1';
        } elseif ($status === 'unused') {
            $where .= ' AND status = 0';
        }

        $printed_select = self::printed_at_exists() ? 'printed_at' : 'NULL AS printed_at';

        $sql = $wpdb->prepare(
            "SELECT id, code, status, created_at, verified_at, {$printed_select}
             FROM {$table}
             WHERE {$where}
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function export_count_codes($status = 'all') {
        global $wpdb;
        $table = self::table_name();

        $status = in_array($status, array('all', 'used', 'unused'), true) ? $status : 'all';

        $where = '1=1';
        if ($status === 'used') {
            $where .= ' AND status = 1';
        } elseif ($status === 'unused') {
            $where .= ' AND status = 0';
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    }

    /**
     * NEW: Export by print state (unprinted/printed/all)
     * Used for "Unprinted list" and "Printed list" + print action.
     */
    public static function export_get_codes_by_print_state($status = 'all', $print_state = 'unprinted', $limit = 10000, $offset = 0) {
        global $wpdb;
        $table = self::table_name();

        $status = in_array($status, array('all', 'used', 'unused'), true) ? $status : 'all';
        if (!in_array($print_state, array('unprinted', 'printed', 'all'), true)) {
            $print_state = 'unprinted';
        }

        $limit  = max(1, min(10000, (int) $limit));
        $offset = max(0, (int) $offset);

        $where = '1=1';
        if ($status === 'used') {
            $where .= ' AND status = 1';
        } elseif ($status === 'unused') {
            $where .= ' AND status = 0';
        }

        $has_printed_at = self::printed_at_exists();

        // Apply print_state filter only if printed_at exists
        if ($has_printed_at) {
            if ($print_state === 'unprinted') {
                $where .= ' AND printed_at IS NULL';
            } elseif ($print_state === 'printed') {
                $where .= ' AND printed_at IS NOT NULL';
            }
        }

        $printed_select = $has_printed_at ? 'printed_at' : 'NULL AS printed_at';

        $sql = $wpdb->prepare(
            "SELECT id, code, status, created_at, verified_at, {$printed_select}
             FROM {$table}
             WHERE {$where}
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function export_count_codes_by_print_state($status = 'all', $print_state = 'unprinted') {
        global $wpdb;
        $table = self::table_name();

        $status = in_array($status, array('all', 'used', 'unused'), true) ? $status : 'all';
        if (!in_array($print_state, array('unprinted', 'printed', 'all'), true)) {
            $print_state = 'unprinted';
        }

        $where = '1=1';
        if ($status === 'used') {
            $where .= ' AND status = 1';
        } elseif ($status === 'unused') {
            $where .= ' AND status = 0';
        }

        $has_printed_at = self::printed_at_exists();

        if ($has_printed_at) {
            if ($print_state === 'unprinted') {
                $where .= ' AND printed_at IS NULL';
            } elseif ($print_state === 'printed') {
                $where .= ' AND printed_at IS NOT NULL';
            }
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    }

    public static function mark_printed_by_ids(array $ids) {
        global $wpdb;
        $table = self::table_name();

        // If column doesn't exist, don't crash—just do nothing
        if (!self::printed_at_exists()) {
            return 0;
        }

        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (empty($ids)) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = "UPDATE {$table}
                SET printed_at = %s
                WHERE id IN ({$placeholders}) AND printed_at IS NULL";

        $params = array_merge(array(current_time('mysql')), $ids);
        return (int) $wpdb->query($wpdb->prepare($sql, $params));
    }

    /**
     * Find the first missing code in [min, max] by scanning ordered codes (cursor-based).
     * This avoids huge random loops when the pool is nearly full.
     */
    public static function find_first_missing_code($min, $max) {
        global $wpdb;
        $table = self::table_name();

        $expected = (int) $min;
        $chunk = 20000;

        while ($expected <= $max) {
            $codes = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT code FROM {$table}
                     WHERE code >= %d AND code <= %d
                     ORDER BY code ASC
                     LIMIT %d",
                    $expected, $max, $chunk
                )
            );

            if (empty($codes)) {
                return $expected;
            }

            foreach ($codes as $c) {
                $c = (int) $c;

                if ($c > $expected) {
                    return $expected;
                }
                if ($c === $expected) {
                    $expected++;
                } else if ($c < $expected) {
                    // continue
                } else {
                    $expected = $c + 1;
                }

                if ($expected > $max) {
                    return null;
                }
            }
        }

        return null;
    }

    public static function generate_unique_code($min, $max) {
        $min = (int) $min;
        $max = (int) $max;

        if ($max < $min) {
            return new WP_Error('scm_invalid_range', 'Invalid range.');
        }

        $range_size = ($max - $min + 1);
        $total = self::count_total_codes_in_range($min, $max);

        if ($total >= $range_size) {
            return new WP_Error('scm_pool_full', 'All possible codes have been generated for this range.');
        }

        $remaining = $range_size - $total;

        if ($remaining <= 5000) {
            $candidate = self::find_first_missing_code($min, $max);
            if ($candidate === null) {
                return new WP_Error('scm_pool_full', 'All possible codes have been generated for this range.');
            }
            if (self::insert_code($candidate)) {
                return (int) $candidate;
            }

            $candidate = self::find_first_missing_code($min, $max);
            if ($candidate !== null && self::insert_code($candidate)) {
                return (int) $candidate;
            }

            return new WP_Error('scm_generate_failed', 'Could not generate a unique code. Please try again.');
        }

        $attempts = 0;
        $max_attempts = 40;

        while ($attempts < $max_attempts) {
            $attempts++;
            $candidate = random_int($min, $max);

            if (self::insert_code($candidate)) {
                return (int) $candidate;
            }
        }

        $candidate = self::find_first_missing_code($min, $max);
        if ($candidate !== null && self::insert_code($candidate)) {
            return (int) $candidate;
        }

        return new WP_Error('scm_generate_failed', 'Could not generate a unique code. Please try again.');
    }

    public static function bulk_generate_codes($limit) {
        $limit = absint($limit);
        if ($limit < 1) return 0;

        $min = (int) SCM_MIN_CODE;
        $max = (int) self::get_max_code();

        if ($max < $min) {
            throw new \Exception('Invalid range. Please set a valid maximum code.');
        }

        $range_size = ($max - $min + 1);
        $total = self::count_total_codes_in_range($min, $max);

        if ($total >= $range_size) {
            throw new \Exception('All possible codes have been generated for this range.');
        }

        $available = $range_size - $total;

        if ($limit > $available) {
            $limit = $available;
        }

        $inserted = 0;

        if ($available <= 60000) {
            $candidate = self::find_first_missing_code($min, $max);

            while ($inserted < $limit && $candidate !== null) {
                if (self::insert_code($candidate)) {
                    $inserted++;
                    $candidate++;
                    if ($candidate > $max) break;
                } else {
                    $candidate = self::find_first_missing_code($candidate + 1, $max);
                }
            }

            return (int) $inserted;
        }

        $attempts = 0;
        $max_attempts = max(200, $limit * 20);

        while ($inserted < $limit && $attempts < $max_attempts) {
            $attempts++;
            $candidate = random_int($min, $max);

            if (self::insert_code($candidate)) {
                $inserted++;
            }
        }

        if ($inserted < $limit) {
            $need = $limit - $inserted;
            $candidate = self::find_first_missing_code($min, $max);

            while ($need > 0 && $candidate !== null) {
                if (self::insert_code($candidate)) {
                    $inserted++;
                    $need--;
                    $candidate++;
                    if ($candidate > $max) break;
                } else {
                    $candidate = self::find_first_missing_code($candidate + 1, $max);
                }
            }
        }

        return (int) $inserted;
    }
}