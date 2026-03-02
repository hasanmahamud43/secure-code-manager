<?php
if (!defined('ABSPATH')) exit;

/**
 * ✅ Guard: prevents "Cannot declare class SCM_Admin... already in use"
 */
if (class_exists('SCM_Admin')) {
    return;
}

class SCM_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_scm_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_scm_generate_code', array(__CLASS__, 'handle_generate_code'));

        // Print / Export handler (10,000 per print)
        add_action('admin_post_scm_print_codes', array(__CLASS__, 'handle_print_codes'));

        add_action('admin_notices', array(__CLASS__, 'admin_notices'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
    }

    public static function admin_menu() {
        add_menu_page(
            'Secure Code Manager',
            'Secure Codes',
            'manage_options',
            'scm-secure-codes',
            array(__CLASS__, 'render_admin_page'),
            'dashicons-shield',
            58
        );
    }

    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_scm-secure-codes') return;

        wp_enqueue_style(
            'scm-admin-css',
            SCM_PLUGIN_URL . 'assets/admin.css',
            array(),
            SCM_VERSION
        );

        wp_register_script('scm-admin-inline-js', '', array('jquery'), SCM_VERSION, true);
        wp_enqueue_script('scm-admin-inline-js');

        $nonce = wp_create_nonce('scm_bulk_generate_codes_nonce');

        $inline = "
jQuery(function($){

    // Bulk generate (10k) JS
    var btn  = $('#scm-bulk-generate-btn');
    var wrap = $('#scm-bulk-progress');
    var bar  = $('#scm-bulk-progress-bar');
    var txt  = $('#scm-bulk-progress-text');
    var log  = $('#scm-bulk-log');

    if(!btn.length) return;

    var TOTAL = parseInt(btn.data('total'), 10) || 10000;
    var BATCH = parseInt(btn.data('batch'), 10) || 500;
    var running = false;
    var done = 0;

    // ✅ NEW: when true, button click will ONLY refresh page (no more generation)
    var refreshMode = false;

    function uiUpdate(){
        var pct = Math.min(100, (done / TOTAL) * 100);
        wrap.show();
        bar.css('width', pct + '%');
        txt.text(done + ' / ' + TOTAL);
    }

    function appendLog(message){
        if(!log.length) return;
        var current = log.val() || '';
        log.val(current + message + \"\\n\");
        log.scrollTop(log[0].scrollHeight);
    }

    function stopWithError(message){
        running = false;
        refreshMode = false;
        btn.prop('disabled', false).text('Generate 10,000 Codes');
        appendLog('ERROR: ' + message);
        alert(message);
    }

    function runBatch(){
        if(!running) return;

        // ✅ If already done, stop making requests
        if(done >= TOTAL){
            running = false;
            refreshMode = true;

            btn.prop('disabled', false).text('Done (Refresh Now)');
            appendLog('DONE: Generated ' + done + ' codes.');
            return;
        }

        var remaining = TOTAL - done;
        var limit = Math.min(BATCH, remaining);

        $.post(ajaxurl, {
            action: 'scm_bulk_generate_codes',
            nonce: '{$nonce}',
            limit: limit
        })
        .done(function(res){
            if(!res || !res.success){
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Bulk generate failed.';
                stopWithError(msg);
                return;
            }

            var inserted = parseInt(res.data.inserted, 10) || 0;

            if(inserted <= 0){
                stopWithError('Inserted 0 codes in this batch. Increase max range or check DB constraints.');
                return;
            }

            done += inserted;
            uiUpdate();
            appendLog('Batch inserted: ' + inserted + ' | Total: ' + done);
            runBatch();
        })
        .fail(function(xhr){
            var msg = 'Request failed. ';
            if(xhr && xhr.status){
                msg += 'HTTP ' + xhr.status + '. ';
            }
            if(xhr && xhr.responseText){
                msg += 'Response: ' + xhr.responseText.toString().substring(0, 300);
            }
            stopWithError(msg);
        });
    }

    btn.on('click', function(e){
        e.preventDefault();

        // ✅ If done already, click should ONLY refresh (no more generation)
        if(refreshMode){
            window.location.reload();
            return;
        }

        if(running) return;

        running = true;
        done = 0;
        refreshMode = false;

        btn.prop('disabled', true).text('Generating...');
        wrap.show();
        bar.css('width', '0%');
        txt.text('0 / ' + TOTAL);
        if(log.length) log.val('');

        appendLog('START: Bulk generation started...');
        runBatch();
    });

});
        ";

        wp_add_inline_script('scm-admin-inline-js', $inline);
    }

    private static function notice_key() {
        return 'scm_admin_notice_' . get_current_user_id();
    }

    private static function last_code_key() {
        return 'scm_last_code_' . get_current_user_id();
    }

    public static function admin_notices() {
        $notice = get_transient(self::notice_key());
        if (!$notice || empty($notice['message'])) return;

        delete_transient(self::notice_key());

        $class = (!empty($notice['type']) && $notice['type'] === 'error') ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) wp_die('Access denied.');
        check_admin_referer('scm_save_settings_nonce');

        $new_max = isset($_POST['scm_max_code']) ? (int) $_POST['scm_max_code'] : 999999;

        if ($new_max <= SCM_MIN_CODE) {
            set_transient(self::notice_key(), array('type' => 'error', 'message' => 'Max must be greater than 100000.'), 30);
            wp_safe_redirect(admin_url('admin.php?page=scm-secure-codes'));
            exit;
        }

        if (!class_exists('SCM_Database')) {
            set_transient(self::notice_key(), array('type' => 'error', 'message' => 'Database class missing. Please reinstall the plugin files.'), 30);
            wp_safe_redirect(admin_url('admin.php?page=scm-secure-codes'));
            exit;
        }

        $existing_max_code = SCM_Database::get_current_max_generated_code();
        if ($existing_max_code > 0 && $new_max < $existing_max_code) {
            set_transient(self::notice_key(), array('type' => 'error', 'message' => 'Max cannot be lower than the highest generated code (' . $existing_max_code . ').'), 30);
            wp_safe_redirect(admin_url('admin.php?page=scm-secure-codes'));
            exit;
        }

        update_option(SCM_OPT_MAX_CODE, $new_max, false);
        set_transient(self::notice_key(), array('type' => 'success', 'message' => 'Settings saved successfully.'), 30);

        wp_safe_redirect(admin_url('admin.php?page=scm-secure-codes'));
        exit;
    }

    public static function handle_generate_code() {
        if (!current_user_can('manage_options')) wp_die('Access denied.');
        check_admin_referer('scm_generate_code_nonce');

        if (!class_exists('SCM_Database')) {
            set_transient(self::notice_key(), array('type' => 'error', 'message' => 'Database class missing. Please reinstall the plugin files.'), 30);
            wp_safe_redirect(admin_url('admin.php?page=scm-secure-codes'));
            exit;
        }

        $max = SCM_Database::get_max_code();
        $generated = SCM_Database::generate_unique_code(SCM_MIN_CODE, $max);

        if (is_wp_error($generated)) {
            set_transient(self::notice_key(), array('type' => 'error', 'message' => $generated->get_error_message()), 30);
        } else {
            set_transient(self::last_code_key(), (int) $generated, 60);
            set_transient(self::notice_key(), array('type' => 'success', 'message' => 'New code generated: ' . (int) $generated), 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=scm-secure-codes'));
        exit;
    }

    // Print (10,000) and auto move to Printed by marking printed_at for that batch
    public static function handle_print_codes() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'scm_print_codes_nonce')) {
            wp_die('The link you followed has expired. Please try again.');
        }

        if (!class_exists('SCM_Database')) {
            wp_die('Database class missing. Please reinstall the plugin files.');
        }

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all';
        if (!in_array($status, array('all', 'used', 'unused'), true)) $status = 'all';

        $print_state = isset($_GET['print_state']) ? sanitize_text_field(wp_unslash($_GET['print_state'])) : 'unprinted';
        if (!in_array($print_state, array('unprinted', 'printed', 'all'), true)) $print_state = 'unprinted';

        $limit = isset($_GET['limit']) ? absint($_GET['limit']) : 10000;
        if ($limit < 1) $limit = 10000;
        if ($limit > 10000) $limit = 10000;

        $rows = SCM_Database::export_get_codes_by_print_state($status, $print_state, $limit, 0);

        if (!empty($rows) && ($print_state === 'unprinted' || $print_state === 'all')) {
            $ids = array();
            foreach ($rows as $r) {
                if ($print_state === 'all') {
                    if (!empty($r['printed_at'])) continue;
                }
                if (!empty($r['id'])) $ids[] = (int) $r['id'];
            }
            if (!empty($ids)) {
                SCM_Database::mark_printed_by_ids($ids);
            }
        }

        $total = count($rows);

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));

        $title = 'Secure Codes Export (' . strtoupper($status) . ' | ' . strtoupper($print_state) . ') - ' . current_time('Y-m-d H:i');

        echo '<!doctype html><html><head><meta charset="utf-8">';
        echo '<title>' . esc_html($title) . '</title>';

        echo '<style>
            body{font-family: Arial, sans-serif; margin:20px; color:#111;}
            h1{font-size:18px; margin:0 0 6px;}
            .meta{font-size:12px; margin:0 0 14px; color:#444;}
            table{width:100%; border-collapse:collapse; font-size:12px;}
            th,td{border:1px solid #ddd; padding:6px 8px; text-align:left;}
            th{background:#f5f5f5;}
            .badge{display:inline-block; padding:2px 6px; border-radius:10px; font-size:11px;}
            .used{background:#e6ffed; border:1px solid #b7ebc6;}
            .unused{background:#f2f2f2; border:1px solid #d9d9d9;}
            @media print {
                .no-print{display:none !important;}
                body{margin:0.5in;}
                table{page-break-inside:auto;}
                tr{page-break-inside:avoid; page-break-after:auto;}
                thead{display: table-header-group;}
            }
        </style>';

        echo '<script>
            window.addEventListener("load", function(){
                setTimeout(function(){ window.print(); }, 300);
            });
        </script>';

        echo '</head><body>';

        echo '<div class="no-print" style="margin-bottom:12px;padding:10px;border:1px solid #ddd;background:#fafafa;">
                <strong>Print dialog will open automatically.</strong> Choose <em>Save as PDF</em> to export.
                <br><small>Note: Unprinted items in this batch are already marked as Printed.</small>
              </div>';

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<p class="meta">This export contains: <strong>' . (int) $total . '</strong> code(s)</p>';

        echo '<table><thead><tr>
                <th style="width:70px;">ID</th>
                <th style="width:140px;">Code</th>
                <th style="width:120px;">Status</th>
                <th style="width:160px;">Created</th>
                <th style="width:160px;">Verified</th>
                <th style="width:160px;">Printed At</th>
              </tr></thead><tbody>';

        foreach ($rows as $r) {
            $is_used = ((int)($r['status'] ?? 0) === 1);
            $badge = $is_used ? '<span class="badge used">Verified</span>' : '<span class="badge unused">Not Verified</span>';

            echo '<tr>';
            echo '<td>' . esc_html((string)($r['id'] ?? '')) . '</td>';
            echo '<td><strong>' . esc_html((string)($r['code'] ?? '')) . '</strong></td>';
            echo '<td>' . $badge . '</td>';
            echo '<td>' . esc_html((string)($r['created_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($r['verified_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($r['printed_at'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</body></html>';
        exit;
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) return;

        if (!class_exists('SCM_Database')) {
            echo '<div class="notice notice-error"><p>Secure Code Manager: Database class missing. Please reinstall the plugin files.</p></div>';
            return;
        }

        $max = SCM_Database::get_max_code();
        $last_code = get_transient(self::last_code_key());

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        if (!in_array($status, array('all', 'used', 'unused'), true)) $status = 'all';

        $print_state = isset($_GET['print_state']) ? sanitize_text_field($_GET['print_state']) : 'unprinted';
        if (!in_array($print_state, array('unprinted', 'printed', 'all'), true)) $print_state = 'unprinted';

        $unprinted_count = SCM_Database::export_count_codes_by_print_state($status, 'unprinted');
        $printed_count   = SCM_Database::export_count_codes_by_print_state($status, 'printed');

        $print_unprinted_url = wp_nonce_url(
            admin_url('admin-post.php?action=scm_print_codes&status=' . $status . '&print_state=unprinted&limit=10000'),
            'scm_print_codes_nonce'
        );

        $print_printed_url = wp_nonce_url(
            admin_url('admin-post.php?action=scm_print_codes&status=' . $status . '&print_state=printed&limit=10000'),
            'scm_print_codes_nonce'
        );
        ?>
        <div class="wrap scm-admin-wrap">
            <h1>Secure Code Manager</h1>

            <div class="scm-admin-cards">

                <div class="scm-admin-card">
                    <h2>Range Settings</h2>
                    <p>Minimum is fixed at <strong><?php echo (int) SCM_MIN_CODE; ?></strong> (true 6-digit). Set the maximum range below.</p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('scm_save_settings_nonce'); ?>
                        <input type="hidden" name="action" value="scm_save_settings">

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label>Minimum</label></th>
                                <td><input type="text" class="regular-text" value="<?php echo (int) SCM_MIN_CODE; ?>" readonly></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="scm_max_code">Maximum</label></th>
                                <td>
                                    <input type="number" class="regular-text" id="scm_max_code" name="scm_max_code" value="<?php echo (int) $max; ?>" min="<?php echo (int) (SCM_MIN_CODE + 1); ?>" step="1" required>
                                    <p class="description">Default: 999999</p>
                                </td>
                            </tr>
                        </table>

                        <p><button type="submit" class="button button-primary">Save Settings</button></p>
                    </form>
                </div>

                <div class="scm-admin-card">
                    <h2>Generate Code</h2>
                    <p>Generates one unique code at a time. It will appear in the table below.</p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('scm_generate_code_nonce'); ?>
                        <input type="hidden" name="action" value="scm_generate_code">
                        <p><button type="submit" class="button button-secondary">Generate New Code</button></p>
                    </form>

                    <div class="scm-last-code">
                        <label>Last Generated Code</label>
                        <input type="text" value="<?php echo $last_code ? (int) $last_code : ''; ?>" readonly placeholder="No code generated yet">
                    </div>
                </div>

                <div class="scm-admin-card">
                    <h2>Random Generate (10,000)</h2>
                    <p>One click will generate <strong>10,000</strong> unique codes. It runs in batches automatically.</p>

                    <p>
                        <button type="button" class="button button-primary" id="scm-bulk-generate-btn" data-total="10000" data-batch="500">
                            Generate 10,000 Codes
                        </button>
                    </p>

                    <div id="scm-bulk-progress" style="display:none; margin-top:10px;">
                        <div style="background:#e5e5e5; height:14px; border-radius:6px; overflow:hidden; max-width:520px;">
                            <div id="scm-bulk-progress-bar" style="height:14px; width:0%; background:#2271b1;"></div>
                        </div>
                        <p id="scm-bulk-progress-text" style="margin:8px 0 0;">0 / 10000</p>
                        <textarea id="scm-bulk-log" readonly style="width:100%; max-width:520px; height:110px; margin-top:10px; font-family:monospace;"></textarea>
                    </div>
                </div>

            </div>

            <hr>

            <h2>Print / Export PDF</h2>

            <p style="margin: 10px 0;">
                <a class="button button-primary" href="<?php echo esc_url($print_unprinted_url); ?>" target="_blank">
                    Print Unprinted (10,000) — <?php echo (int) $unprinted_count; ?> available
                </a>

                <a class="button button-secondary" href="<?php echo esc_url($print_printed_url); ?>" target="_blank" style="margin-left:8px;">
                    Print Printed (10,000) — <?php echo (int) $printed_count; ?> available
                </a>
            </p>

            <hr>

            <h2>Codes List</h2>

            <?php
            if (class_exists('SCM_Admin_Table')) {
                $table = new SCM_Admin_Table($status, $print_state);
                $table->prepare_items();
            }
            ?>

            <form method="get">
                <input type="hidden" name="page" value="scm-secure-codes">
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                <input type="hidden" name="print_state" value="<?php echo esc_attr($print_state); ?>">

                <?php
                if (isset($table) && $table instanceof SCM_Admin_Table) {
                    $table->views();
                    $table->display();
                } else {
                    echo '<div class="notice notice-error"><p>Secure Code Manager: Admin table class missing. Please reinstall the plugin files.</p></div>';
                }
                ?>
            </form>

        </div>
        <?php
    }
}