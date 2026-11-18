<?php
namespace ZeriseTaxo\Admin;

use ZeriseTaxo\Database\Schema;

defined('ABSPATH') || exit;

/**
 * Admin page controller for AI Category Suggestions (Legacy handler & URL redirector).
 */
class SuggestionsPage {

    /**
     * Legacy menu slug.
     */
    const MENU_SLUG = 'zerisetaxo-category-suggestions';

    /**
     * Register hooks.
     */
    public function register() {
        add_action('admin_init', [$this, 'handle_legacy_redirect']);
    }

    /**
     * Redirect legacy page visits (edit.php?page=zerisetaxo-category-suggestions)
     * to the unified tabbed dashboard (edit.php?page=zerisetaxo&tab=suggestions).
     */
    public function handle_legacy_redirect() {
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL redirection for legacy bookmarks
        if ('edit.php' === $pagenow && isset($_GET['page']) && self::MENU_SLUG === sanitize_key(wp_unslash($_GET['page']))) {
            $allowed_keys = ['paged', 'status', 'taxonomy', 'orderby', 'order'];
            $query_args = ['page' => SettingsPage::SUGGESTIONS_SLUG];
            foreach ($allowed_keys as $key) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL redirection for legacy bookmarks
                if (isset($_GET[$key])) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL redirection for legacy bookmarks
                    $query_args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
                }
            }

            $redirect_url = add_query_arg($query_args, admin_url('admin.php'));
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Get the count of pending category suggestions.
     *
     * @return int
     */
    public function get_pending_count(): int {
        global $wpdb;
        if (empty($wpdb) || !is_object($wpdb)) {
            return 0;
        }
        $table = esc_sql(Schema::get_table_name());

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table name escaped via esc_sql
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
        return (int) $count;
    }
}
