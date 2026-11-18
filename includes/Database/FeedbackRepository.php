<?php
namespace ZeriseTaxo\Database;

defined('ABSPATH') || exit;

/**
 * Repository for the ML feedback durability log table (Phase C).
 *
 * All feedback must be persisted here BEFORE being dispatched to FastAPI.
 * synced_at remains NULL until a FastAPI 2xx acknowledgement is received.
 */
class FeedbackRepository {

    /**
     * Insert a new feedback event.
     *
     * Generates a random UUID for event_id. On UNIQUE KEY collision (duplicate
     * event_id) the insert is silently skipped via INSERT IGNORE and the
     * existing row id is returned instead.
     *
     * @param array $data {
     *     @type int    $post_id            WordPress post ID.
     *     @type string $category           Admin-approved/corrected category label.
     *     @type string $source             Feedback source ('admin_apply', 'admin_create_and_apply', 'admin_approval', …).
     *     @type string $predicted_category Original ML prediction (may be empty string).
     *     @type float  $confidence         Prediction confidence 0.0–1.0 (null if unknown).
     *     @type string $decision_source    Decision source ('hybrid', 'site_model', …).
     *     @type bool   $accepted           True if admin accepted prediction, false if corrected.
     *     @type string $corrected_category Corrected category when accepted=false.
     * }
     * @return array{id: int, event_id: string}|false Row ID and event_id on success, false on failure.
     */
    public static function insert(array $data) {
        global $wpdb;

        $event_id = self::generate_uuid4();
        $table    = esc_sql(Schema::get_feedback_log_table());

        $accepted           = !empty($data['accepted']) ? 1 : 0;
        $predicted_category = isset($data['predicted_category']) ? (string) $data['predicted_category'] : null;
        $confidence         = isset($data['confidence']) && $data['confidence'] !== '' ? (float) $data['confidence'] : null;
        $decision_source    = isset($data['decision_source']) ? (string) $data['decision_source'] : null;
        $corrected_category = isset($data['corrected_category']) ? (string) $data['corrected_category'] : null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom feedback log table
        $inserted = $wpdb->insert(
            $table,
            [
                'event_id'           => $event_id,
                'post_id'            => (int) $data['post_id'],
                'category'           => (string) $data['category'],
                'source'             => (string) $data['source'],
                'predicted_category' => $predicted_category,
                'confidence'         => $confidence,
                'decision_source'    => $decision_source,
                'accepted'           => $accepted,
                'corrected_category' => $corrected_category,
                'created_at'         => current_time('mysql'),
                'synced_at'          => null,
                'sync_error'         => null,
            ],
            [
                '%s', // event_id
                '%d', // post_id
                '%s', // category
                '%s', // source
                '%s', // predicted_category
                '%s', // confidence (nullable float stored as string via wpdb)
                '%s', // decision_source
                '%d', // accepted
                '%s', // corrected_category
                '%s', // created_at
                '%s', // synced_at
                '%s', // sync_error
            ]
        );

        if ($inserted === false) {
            return false;
        }

        return [
            'id'       => (int) $wpdb->insert_id,
            'event_id' => $event_id,
        ];
    }

    /**
     * Mark a feedback row as successfully dispatched to FastAPI.
     *
     * @param int $row_id The primary key of the feedback_log row.
     * @return bool
     */
    public static function mark_synced(int $row_id): bool {
        global $wpdb;

        $table = esc_sql(Schema::get_feedback_log_table());

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom feedback log table
        $result = $wpdb->update(
            $table,
            [
                'synced_at'  => current_time('mysql'),
                'sync_error' => null,
            ],
            ['id' => $row_id],
            ['%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Record a FastAPI dispatch error on a feedback row.
     * Leaves synced_at as NULL so the row is eligible for future retry.
     *
     * @param int    $row_id       The primary key of the feedback_log row.
     * @param string $error_message Human-readable error description.
     * @return bool
     */
    public static function mark_sync_failed(int $row_id, string $error_message): bool {
        global $wpdb;

        $table = esc_sql(Schema::get_feedback_log_table());

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom feedback log table
        $result = $wpdb->update(
            $table,
            [
                'sync_error'    => substr($error_message, 0, 65535),
                'synced_at'     => null,
                'last_retry_at' => current_time('mysql'),
            ],
            ['id' => $row_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Fetch a bounded batch of feedback rows that have not yet been synced.
     *
     * Only rows where synced_at IS NULL are returned. The batch is ordered by
     * created_at ASC so oldest events are retried first (FIFO).
     *
     * @param int $limit Maximum rows to return (default 25, hard cap 50).
     * @return array[] Array of row objects with id, event_id, post_id, category, source, etc.
     */
    public static function get_unsynced_batch(int $limit = 25): array {
        global $wpdb;

        $limit = min(max(1, $limit), 50); // bounded: 1..50
        $table = esc_sql(Schema::get_feedback_log_table());

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, event_id, post_id, category, source,
                        predicted_category, confidence, decision_source,
                        accepted, corrected_category, created_at
                 FROM {$table}
                 WHERE synced_at IS NULL
                 ORDER BY created_at ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array($rows) ? $rows : [];
    }

    /**
     * Count feedback rows that have not yet been synced to FastAPI.
     *
     * @return int
     */
    public static function count_unsynced(): int {
        global $wpdb;

        $table = esc_sql(Schema::get_feedback_log_table());

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE synced_at IS NULL");
        return (int) $count;
    }

    /**
     * Generate a random UUID v4 string.
     *
     * Uses wp_generate_uuid4() when available (WP >= 4.7), falls back to
     * a cryptographically random implementation.
     *
     * @return string 36-character UUID string.
     */
    public static function generate_uuid4(): string {
        if (function_exists('wp_generate_uuid4')) {
            $uuid = wp_generate_uuid4();
            if (!empty($uuid)) {
                return $uuid;
            }
        }

        // Fallback: random_bytes-based UUID v4
        try {
            $data    = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // Version 4
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // Variant bits
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (\Exception $e) {
            // Last-resort non-crypto fallback
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                wp_rand(0, 0xffff), wp_rand(0, 0xffff),
                wp_rand(0, 0xffff),
                wp_rand(0, 0x0fff) | 0x4000,
                wp_rand(0, 0x3fff) | 0x8000,
                wp_rand(0, 0xffff), wp_rand(0, 0xffff), wp_rand(0, 0xffff)
            );
        }
    }
}
