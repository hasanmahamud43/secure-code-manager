<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// ✅ Guard: prevent "Cannot declare class ... already in use"
if (class_exists('SCM_Admin_Table')) {
    return;
}

class SCM_Admin_Table extends WP_List_Table {

    private $status_filter = 'all';
    private $print_state = 'unprinted'; // unprinted|printed|all
    private $has_printed_at = null; // cache

    public function __construct($status_filter = 'all', $print_state = 'unprinted') {
        parent::__construct(array(
            'singular' => 'scm_code',
            'plural'   => 'scm_codes',
            'ajax'     => false,
        ));

        $this->status_filter = $status_filter ?: 'all';

        $print_state = $print_state ?: 'unprinted';
        if (!in_array($print_state, array('unprinted', 'printed', 'all'), true)) {
            $print_state = 'unprinted';
        }
        $this->print_state = $print_state;
    }

    private function printed_at_exists() {
        if ($this->has_printed_at !== null) return $this->has_printed_at;

        // If Database class not loaded, treat as missing
        if (!class_exists('SCM_Database')) {
            $this->has_printed_at = false;
            return $this->has_printed_at;
        }

        global $wpdb;
        $table = SCM_Database::table_name();

        $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'printed_at'");
        $this->has_printed_at = !empty($col);

        return $this->has_printed_at;
    }

    public function get_columns() {
        return array(
            'id'         => 'ID',
            'code'       => 'Code',
            'status'     => 'Status',
            'created_at' => 'Created',
            'verified_at'=> 'Verified At',
            'printed_at' => 'Printed At',
        );
    }

    protected function get_sortable_columns() {
        return array(
            'id'         => array('id', true),
            'code'       => array('code', false),
            'status'     => array('status', false),
            'created_at' => array('created_at', false),
            'verified_at'=> array('verified_at', false),
            'printed_at' => array('printed_at', false),
        );
    }

    public function get_views() {
        $current_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        if (!in_array($current_status, array('all', 'unused', 'used'), true)) {
            $current_status = 'all';
        }

        $current_print = isset($_GET['print_state']) ? sanitize_text_field($_GET['print_state']) : 'unprinted';
        if (!in_array($current_print, array('unprinted', 'printed', 'all'), true)) {
            $current_print = 'unprinted';
        }

        // If DB doesn't have printed_at yet, keep UI but avoid forcing broken filters
        $has_printed_at = $this->printed_at_exists();
        if (!$has_printed_at) {
            $current_print = 'all';
        }

        $base_url = admin_url('admin.php?page=scm-secure-codes');
        $views = array();

        // Printed filter group (same as your version)
        $views['unprinted'] = sprintf(
            '<a href="%s" class="%s">Unprinted</a>',
            esc_url(add_query_arg(array('status' => $current_status, 'print_state' => 'unprinted'), $base_url)),
            ($current_print === 'unprinted' ? 'current' : '')
        );

        $views['printed'] = sprintf(
            '<a href="%s" class="%s">Printed</a>',
            esc_url(add_query_arg(array('status' => $current_status, 'print_state' => 'printed'), $base_url)),
            ($current_print === 'printed' ? 'current' : '')
        );

        $views['print_all'] = sprintf(
            '<a href="%s" class="%s">All (Printed+Unprinted)</a>',
            esc_url(add_query_arg(array('status' => $current_status, 'print_state' => 'all'), $base_url)),
            ($current_print === 'all' ? 'current' : '')
        );

        /**
         * ✅ FIX ONLY HERE:
         * Used/Unused must work purely by verification status (status=1/0),
         * so we force print_state=all in these links.
         */
        $views['all'] = sprintf(
            '<a href="%s" class="%s">All</a>',
            esc_url(add_query_arg(array('status' => 'all', 'print_state' => 'all'), $base_url)),
            ($current_status === 'all' ? 'current' : '')
        );

        $views['unused'] = sprintf(
            '<a href="%s" class="%s">Unused</a>',
            esc_url(add_query_arg(array('status' => 'unused', 'print_state' => 'all'), $base_url)),
            ($current_status === 'unused' ? 'current' : '')
        );

        $views['used'] = sprintf(
            '<a href="%s" class="%s">Used</a>',
            esc_url(add_query_arg(array('status' => 'used', 'print_state' => 'all'), $base_url)),
            ($current_status === 'used' ? 'current' : '')
        );

        return $views;
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
            case 'code':
            case 'created_at':
            case 'verified_at':
            case 'printed_at':
                return esc_html($item[$column_name] ?? '');
            case 'status':
                $is_used = ((int)($item['status'] ?? 0) === 1);
                $label = $is_used ? 'Verified' : 'Not Verified Yet';
                $class = $is_used ? 'scm-badge scm-badge-green' : 'scm-badge scm-badge-gray';
                return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
            default:
                return '';
        }
    }

    public function prepare_items() {
        global $wpdb;

        if (!class_exists('SCM_Database')) {
            $this->items = array();
            $this->set_pagination_args(array(
                'total_items' => 0,
                'per_page'    => 100,
                'total_pages' => 0,
            ));
            $this->_column_headers = array(
                $this->get_columns(),
                array(),
                $this->get_sortable_columns(),
            );
            return;
        }

        $table = SCM_Database::table_name();

        $per_page = 100;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $has_printed_at = $this->printed_at_exists();

        $orderby_allowed = array('id', 'code', 'status', 'created_at', 'verified_at', 'printed_at');
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'id';
        if (!in_array($orderby, $orderby_allowed, true)) $orderby = 'id';

        // If printed_at doesn't exist, don't allow ordering by it
        if (!$has_printed_at && $orderby === 'printed_at') $orderby = 'id';

        $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
        if (!in_array($order, array('ASC', 'DESC'), true)) $order = 'DESC';

        $where = '1=1';

        if ($this->status_filter === 'used') {
            $where .= ' AND status = 1';
        } else if ($this->status_filter === 'unused') {
            $where .= ' AND status = 0';
        }

        // Apply printed filter only if printed_at exists
        if ($has_printed_at) {
            if ($this->print_state === 'unprinted') {
                $where .= ' AND printed_at IS NULL';
            } else if ($this->print_state === 'printed') {
                $where .= ' AND printed_at IS NOT NULL';
            }
        }

        $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");

        // Safe select of printed_at
        $printed_select = $has_printed_at ? 'printed_at' : 'NULL AS printed_at';

        $query = $wpdb->prepare(
            "SELECT id, code, status, created_at, verified_at, {$printed_select}
             FROM {$table}
             WHERE {$where}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $items = $wpdb->get_results($query, ARRAY_A);
        $this->items = is_array($items) ? $items : array();

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total_items / $per_page),
        ));

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );
    }
}