<?php
/*
 * Plugin Name:      Secure Code Manager (One-time Verification)
 * Plugin URI:        https://github.com/shakib6472/
 * Description:       Admin generates unique 6-digit+ codes within a configurable range. Frontend verifies once and locks permanently. Shortcode: [verify_secure_code]
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Shakib Shown
 * Author URI:        https://github.com/shakib6472/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       secure-code-manager
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) exit;

define('SCM_VERSION', '1.0.0');
define('SCM_MIN_CODE', 100000);
define('SCM_OPT_MAX_CODE', 'scm_max_code');
define('SCM_OPT_DB_VERSION', 'scm_db_version');

define('SCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Safe require helper: prevents fatal if file missing.
 */
function scm_safe_require($relative_path) {
    $file = SCM_PLUGIN_DIR . ltrim($relative_path, '/');
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    error_log('[SCM] Missing file: ' . $file);
    return false;
}

// Safe includes (no fatal if something is missing)
scm_safe_require('includes/class-scm-database.php');
scm_safe_require('includes/class-scm-admin.php');
scm_safe_require('includes/class-scm-admin-table.php');
scm_safe_require('includes/class-scm-ajax.php');
scm_safe_require('includes/class-scm-shortcode.php');

register_activation_hook(__FILE__, function () {
    if (class_exists('SCM_Database')) {
        SCM_Database::activate();
    } else {
        error_log('[SCM] Activation failed: SCM_Database class not found.');
    }
});

add_action('plugins_loaded', function () {

    if (class_exists('SCM_Admin')) {
        SCM_Admin::init();
    } else {
        error_log('[SCM] SCM_Admin class not found on plugins_loaded.');
    }

    if (class_exists('SCM_Ajax')) {
        SCM_Ajax::init();
    } else {
        error_log('[SCM] SCM_Ajax class not found on plugins_loaded.');
    }

    if (class_exists('SCM_Shortcode')) {
        SCM_Shortcode::init();
    } else {
        error_log('[SCM] SCM_Shortcode class not found on plugins_loaded.');
    }

});