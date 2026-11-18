<?php
namespace ZeriseTaxo\Services;

use ZeriseTaxo\Database\FeedbackRepository;

defined('ABSPATH') || exit;

/**
 * Handles retry and bulk replay of unsynced ML feedback events (Phase C).
 *
 * WordPress is the durable source of truth. FastAPI feedback.csv is ephemeral.
 * This service dispatches unsynced feedback rows to FastAPI using the original
 * event_id (preserving idempotency — FastAPI deduplicates on event_id).
 *
 * Replay is safe to run multiple times.
 * No new WordPress rows are ever created during retry or replay.
 * Post text is assembled at dispatch time from get_post(); deleted-post events
 * are dispatched with empty text (FastAPI accepts — category is mandatory, text optional).
 *
 * wp_cron schedule: every 6 hours ('zerisetaxo_feedback_retry_interval').
 * Batch size: 25 rows per cron run (bounded, avoids rate-limit saturation).
 *
 * Missing-post behavior:
 *   If get_post() returns null the payload is sent with text=''.
 *   FastAPI will still record the category correction in feedback.csv.
 *   This is the correct behavior because the category label is what trains the model —
 *   the absence of body text results in that row being weight-neutral during
 *   TF-IDF feature extraction (the row contributes the label only).
 *   The row is NOT skipped or marked with a special reason; it is dispatched normally.
 */
class FeedbackReplayService {

    /**
     * Cron hook name.
     */
    const CRON_HOOK = 'zerisetaxo_feedback_retry';

    /**
     * Cron interval slug.
     */
    const CRON_INTERVAL = 'zerisetaxo_feedback_retry_interval';

    /**
     * Batch size for cron retry.
     */
    const RETRY_BATCH_SIZE = 25;

    /**
     * Maximum batch size for manual admin replay (larger, but still bounded).
     */
    const REPLAY_BATCH_SIZE = 50;

    /**
     * @var InferenceClient
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param InferenceClient $client
     */
    public function __construct(InferenceClient $client) {
        $this->client = $client;
    }

    /**
     * Register the custom cron interval and schedule the recurring job.
     * Called once at plugin load time.
     */
    public static function register_cron_schedule() {
        // Register custom interval: every 6 hours
        add_filter('cron_schedules', function (array $schedules) {
            if (!isset($schedules[self::CRON_INTERVAL])) {
                $schedules[self::CRON_INTERVAL] = [
                    'interval' => 6 * HOUR_IN_SECONDS,
                    'display'  => __('Every 6 Hours (ML Feedback Retry)', 'zerisetaxo'),
                ];
            }
            return $schedules;
        });
    }

    /**
     * Schedule the cron job if not already scheduled and service URL is configured.
     * Called from the Plugin bootstrap after hooks are set up.
     */
    public static function maybe_schedule_cron() {
        $service_url = InferenceClient::get_canonical_service_url();
        if (empty($service_url)) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Unschedule the cron job. Called on plugin deactivation.
     */
    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Process a bounded batch of unsynced feedback events.
     *
     * This is the cron callback. It is also safe to call manually (e.g. admin replay).
     * Returns a summary array for logging/display.
     *
     * @param int $batch_size Number of rows to process (default: RETRY_BATCH_SIZE).
     * @return array{processed: int, synced: int, failed: int, skipped_429: bool}
     */
    public function process_retry_batch(int $batch_size = self::RETRY_BATCH_SIZE): array {
        if (!$this->client->is_configured()) {
            return ['processed' => 0, 'synced' => 0, 'failed' => 0, 'skipped_429' => false];
        }

        $rows = FeedbackRepository::get_unsynced_batch($batch_size);

        $processed   = 0;
        $synced      = 0;
        $failed      = 0;
        $skipped_429 = false;

        foreach ($rows as $row) {
            $row_id   = (int) $row['id'];
            $event_id = (string) $row['event_id'];
            $post_id  = (int) $row['post_id'];
            $category = (string) $row['category'];
            $source   = (string) $row['source'];

            $result = $this->client->send_feedback(
                $event_id,
                $post_id,
                $category,
                $source,
                [
                    'predicted_category' => $row['predicted_category'] ?? '',
                    'confidence'         => isset($row['confidence']) && $row['confidence'] !== '' ? (float) $row['confidence'] : null,
                    'decision_source'    => $row['decision_source'] ?? 'hybrid',
                    'accepted'           => (bool) ($row['accepted'] ?? true),
                    'corrected_category' => $row['corrected_category'] ?? null,
                ]
            );

            $processed++;

            if (is_wp_error($result)) {
                $err_code = $result->get_error_code();

                // 429 Rate-limited: stop the batch immediately, respect the server.
                if (strpos($err_code, '429') !== false || strpos($err_code, 'http_429') !== false) {
                    FeedbackRepository::mark_sync_failed($row_id, '429 Rate limited — will retry later.');
                    $skipped_429 = true;
                    $failed++;
                    break;
                }

                // 401/403: authentication problem — mark failed, stop batch (token likely wrong for all).
                if (strpos($err_code, '401') !== false || strpos($err_code, '403') !== false ||
                    strpos($err_code, 'http_401') !== false || strpos($err_code, 'http_403') !== false) {
                    FeedbackRepository::mark_sync_failed($row_id, "Auth error ({$err_code}) — check API token.");
                    $failed++;
                    break;
                }

                // All other errors (5xx, timeout, offline): record and continue to next row.
                FeedbackRepository::mark_sync_failed($row_id, $result->get_error_message());
                $failed++;
                continue;
            }

            // 2xx success (including duplicate_ignored — idempotent, still a success).
            FeedbackRepository::mark_synced($row_id);
            $synced++;
        }

        return [
            'processed'   => $processed,
            'synced'      => $synced,
            'failed'      => $failed,
            'skipped_429' => $skipped_429,
        ];
    }

    /**
     * Run a full bounded replay of all unsynced feedback rows.
     *
     * Safe to call multiple times (idempotent — FastAPI deduplicates on event_id).
     * Uses REPLAY_BATCH_SIZE (50) per call. For very large backlogs, call this
     * method from a loop or repeat the admin action.
     *
     * @return array{processed: int, synced: int, failed: int, skipped_429: bool}
     */
    public function replay_all(): array {
        return $this->process_retry_batch(self::REPLAY_BATCH_SIZE);
    }
}
