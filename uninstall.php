<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up options, transients, post meta, tables, and scheduled crons.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Execute cleanup tasks for a single site/blog.
 */
function zerisetaxo_uninstall_single_blog() {
    global $wpdb;

    // 1. ALWAYS PURGE SECRETS & LLM TRANSIENTS (even if delete-data option is off)
    delete_option('zerisetaxo_llm_api_key_enc');
    delete_option('zerisetaxo_llm_privacy_consent');
    delete_option('zerisetaxo_api_token');

    // Delete transients starting with zerisetaxo_llm_ (transient and timeout rows)
    $llm_transient_pattern = $wpdb->esc_like('_transient_zerisetaxo_llm_') . '%';
    $llm_timeout_pattern   = $wpdb->esc_like('_transient_timeout_zerisetaxo_llm_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup: delete LLM transients
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $llm_transient_pattern,
            $llm_timeout_pattern
        )
    );

    // 2. PURGE ALL PLUGIN DATA ONLY IF EXPLICITLY OPTED IN
    $delete_data = (bool) get_option('zerisetaxo_delete_data_on_uninstall', false);
    if (!$delete_data) {
        return;
    }

    // Drop custom tables
    $suggestions_table = esc_sql($wpdb->prefix . 'zerisetaxo_category_suggestions');
    $feedback_table    = esc_sql($wpdb->prefix . 'zerisetaxo_feedback_log');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup: drop custom tables
    $wpdb->query("DROP TABLE IF EXISTS {$suggestions_table}, {$feedback_table}");

    // Delete all options starting with zerisetaxo_
    $options_pattern = $wpdb->esc_like('zerisetaxo_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup: delete plugin options
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $options_pattern
        )
    );

    // Delete all transients starting with zerisetaxo_
    $all_transient_pattern = $wpdb->esc_like('_transient_zerisetaxo_') . '%';
    $all_timeout_pattern   = $wpdb->esc_like('_transient_timeout_zerisetaxo_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup: delete all plugin transients
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $all_transient_pattern,
            $all_timeout_pattern
        )
    );

    // Delete post meta keys starting with _zerisetaxo_
    $meta_pattern = $wpdb->esc_like('_zerisetaxo_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup: delete plugin post meta
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $meta_pattern
        )
    );

    // Delete legacy _ml_* post meta keys
    $legacy_meta_keys = [
        '_ml_predicted_category',
        '_ml_predicted_category_id',
        '_ml_prediction_confidence',
        '_ml_runner_up_category',
        '_ml_runner_up_category_id',
        '_ml_runner_up_confidence',
    ];
    foreach ($legacy_meta_keys as $meta_key) {
        delete_post_meta_by_key($meta_key);
    }

    // Clear scheduled cron events
    wp_clear_scheduled_hook('zerisetaxo_feedback_retry');
    wp_clear_scheduled_hook('zerisetaxo_cron_rebuild_site_model');
}

if (is_multisite()) {
    $sites = get_sites(['number' => 0]);
    foreach ($sites as $site) {
        switch_to_blog((int) $site->blog_id);
        zerisetaxo_uninstall_single_blog();
        restore_current_blog();
    }
} else {
    zerisetaxo_uninstall_single_blog();
}
