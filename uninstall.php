<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

$table = $wpdb->prefix . 'scm_codes';

$wpdb->query("DROP TABLE IF EXISTS {$table}");

delete_option('scm_max_code');
delete_option('scm_db_version');