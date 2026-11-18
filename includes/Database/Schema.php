<?php
namespace ZeriseTaxo\Database;

defined('ABSPATH') || exit;

/**
 * Handles database schema creation and table management.
 */
class Schema {

    /**
     * DB schema version.
     */
    const DB_VERSION = '1.2.0';

    /**
     * DB version option name.
     */
    const OPTION_DB_VERSION = 'zerisetaxo_db_version';

    /**
     * Base table name without prefix (category suggestions).
     */
    const TABLE_NAME = 'zerisetaxo_category_suggestions';

    /**
     * Base table name without prefix (ML feedback durability log — Phase C).
     */
    const FEEDBACK_LOG_TABLE = 'zerisetaxo_feedback_log';

    /**
     * Get the full table name with WordPress prefix.
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Get the full feedback log table name with WordPress prefix.
     *
     * @return string
     */
    public static function get_feedback_log_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::FEEDBACK_LOG_TABLE;
    }

    /**
     * Create or update database tables using dbDelta.
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // ── Table 1: Category Suggestions ──────────────────────────────────
        $suggestions_table = self::get_table_name();

        // Note: dbDelta requires two spaces after PRIMARY KEY and specific casing
        $sql_suggestions = "CREATE TABLE {$suggestions_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            suggested_category varchar(191) NOT NULL,
            reason text NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            merged_into_term_id bigint(20) unsigned NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            reviewed_at datetime NULL DEFAULT NULL,
            reviewed_by bigint(20) unsigned NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY status (status)
        ) {$charset_collate};";

        // ── Table 2: ML Feedback Durability Log (Phase C) ──────────────────
        $feedback_table = self::get_feedback_log_table();

        // event_id: immutable random UUID, UNIQUE to prevent duplicate inserts.
        // synced_at NULL = not yet dispatched to FastAPI.
        $sql_feedback = "CREATE TABLE {$feedback_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id varchar(36) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            category varchar(191) NOT NULL,
            source varchar(64) NOT NULL,
            predicted_category varchar(191) NULL DEFAULT NULL,
            confidence decimal(5,4) NULL DEFAULT NULL,
            decision_source varchar(64) NULL DEFAULT NULL,
            accepted tinyint(1) NOT NULL DEFAULT 1,
            corrected_category varchar(191) NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            synced_at datetime NULL DEFAULT NULL,
            sync_error text NULL DEFAULT NULL,
            last_retry_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY post_id (post_id),
            KEY synced_at (synced_at)
        ) {$charset_collate};";

        if (!function_exists('dbDelta') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (function_exists('dbDelta')) {
            dbDelta($sql_suggestions);
            dbDelta($sql_feedback);
        }

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Check if table needs to be created on update.
     */
    public static function maybe_create_tables() {
        $installed_ver = get_option(self::OPTION_DB_VERSION);
        if ($installed_ver !== self::DB_VERSION) {
            self::create_tables();
        }
    }

    /**
     * Drop plugin custom tables.
     */
    public static function drop_tables() {
        global $wpdb;

        $suggestions_table = esc_sql(self::get_table_name());
        $feedback_table    = esc_sql(self::get_feedback_log_table());

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Explicit drop tables on uninstall/reset
        $wpdb->query("DROP TABLE IF EXISTS {$feedback_table}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Explicit drop tables on uninstall/reset
        $wpdb->query("DROP TABLE IF EXISTS {$suggestions_table}");

        delete_option(self::OPTION_DB_VERSION);
    }
}
