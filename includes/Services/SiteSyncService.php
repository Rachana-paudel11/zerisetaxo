<?php
namespace ZeriseTaxo\Services;

defined('ABSPATH') || exit;

/**
 * Site-Data Synchronization Service (Phase B).
 *
 * Coordinates server-side snapshot synchronization between WordPress and FastAPI:
 * 1. Computes and freezes training-eligible post IDs to prevent count mismatches.
 * 2. Normalizes taxonomy profiles using canonical WordPress term IDs.
 * 3. Chunks posts into safe bounded batches (<= 100 posts per batch).
 * 4. Generates deterministic SHA-256 content hashes for incremental embedding caching.
 * 5. Maintains synchronization session state in WordPress transients without exposing
 *    sync_id, API credentials, or internal server paths to browser JavaScript.
 * 6. Normalizes all HTTP/network errors into user-friendly localized messages.
 */
class SiteSyncService {

    /**
     * Batch size limit matching FastAPI MAX_POSTS_PER_BATCH.
     */
    const BATCH_SIZE = 100;

    /**
     * Maximum categories matching FastAPI MAX_CATEGORIES.
     */
    const MAX_CATEGORIES = 200;

    /**
     * Maximum post text length in characters matching FastAPI MAX_TEXT_CHARS.
     */
    const MAX_TEXT_CHARS = 20000;

    /**
     * Transient prefix for holding active sync state.
     */
    const TRANSIENT_PREFIX = 'zerisetaxo_sync_state_';

    /**
     * Option names for persistent sync status tracking.
     */
    const OPTION_SYNC_STATUS       = 'zerisetaxo_sync_status';
    const OPTION_LAST_SYNCED_AT    = 'zerisetaxo_last_synced_at';
    const OPTION_SYNCED_POST_COUNT = 'zerisetaxo_synced_post_count';

    /**
     * Option names for persistent model rebuild tracking.
     */
    const OPTION_MODEL_STATUS    = 'zerisetaxo_site_model_status';
    const OPTION_MODEL_VERSION   = 'zerisetaxo_site_model_version';
    const OPTION_MODEL_ACCURACY  = 'zerisetaxo_site_model_accuracy';
    const OPTION_MODEL_MACRO_F1  = 'zerisetaxo_site_model_macro_f1';
    const OPTION_LAST_REBUILT_AT = 'zerisetaxo_last_rebuilt_at';

    /**
     * Inference client instance.
     *
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
     * Get the transient key for an administrator user.
     *
     * @param int $user_id
     * @return string
     */
    protected function get_transient_key(int $user_id): string {
        return self::TRANSIENT_PREFIX . $user_id;
    }

    /**
     * Clean and normalize raw HTML post content into plain training text.
     * Mirrors the preprocessing logic in Python site_ingestion.clean_text_content.
     *
     * @param string $raw_html
     * @return string
     */
    public static function clean_text_content(string $raw_html): string {
        if (empty($raw_html)) {
            return '';
        }

        // Strip WordPress shortcodes
        if (function_exists('strip_shortcodes')) {
            $raw_html = strip_shortcodes($raw_html);
        }

        // Strip HTML tags
        $text = function_exists('\\wp_strip_all_tags')
            ? wp_strip_all_tags($raw_html, true)
            : wp_strip_all_tags($raw_html);

        // Remove citation brackets e.g. [1], [2]
        $text = preg_replace('/\[\d+\]/', ' ', $text);

        // Decode HTML entities, then strip any leftover entity artifacts
        $text = htzerisetaxo_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/&[a-zA-Z0-9#]+;/', ' ', $text);

        // Normalize multiple whitespace characters
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Combine post title and content into normalized training text.
     *
     * @param string $title
     * @param string $content
     * @return string
     */
    public static function prepare_training_text(string $title, string $content): string {
        $clean_title   = self::clean_text_content($title);
        $clean_content = self::clean_text_content($content);

        $combined = trim($clean_title . "\n\n" . $clean_content);
        if (empty($combined)) {
            $combined = !empty($clean_title) ? $clean_title : $clean_content;
        }

        if (mb_strlen($combined, 'UTF-8') > self::MAX_TEXT_CHARS) {
            $combined = mb_substr($combined, 0, self::MAX_TEXT_CHARS, 'UTF-8');
        }

        return $combined;
    }

    /**
     * Generates a stable, deterministic SHA-256 hash of post content.
     * Matches Python site_ingestion.compute_post_content_hash byte-for-byte.
     *
     * @param string $title
     * @param string $content
     * @return string 64-character lowercase hex SHA-256
     */
    public static function compute_content_hash(string $title, string $content): string {
        $normalized = trim($title) . "\n" . trim($content);
        return hash('sha256', $normalized);
    }

    /**
     * Determine the exact set of training-eligible WordPress posts.
     *
     * Rules:
     * - post_type === 'post'
     * - post_status === 'publish'
     * - produces non-empty training text (title or content)
     *
     * @return int[] Array of unique, positive post IDs sorted ascending.
     */
    public function get_training_eligible_post_ids(): array {
        global $wpdb;

        if (!$wpdb) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct read of published posts for model training sync
        $rows = $wpdb->get_results(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID ASC"
        );

        if (!is_array($rows)) {
            return [];
        }

        $eligible_ids = [];
        foreach ($rows as $row) {
            $post_id = (int) $row->ID;
            $text    = self::prepare_training_text((string) $row->post_title, (string) $row->post_content);
            if (strlen(trim($text)) > 0) {
                $eligible_ids[] = $post_id;
            }
        }

        return array_values(array_unique($eligible_ids));
    }

    /**
     * Normalizes site categories into the canonical Phase B taxonomy payload.
     * Uses WordPress term_id as canonical category identity.
     *
     * @return array[] List of normalized category objects.
     */
    public function prepare_taxonomy_payload(): array {
        $raw_categories = get_categories(['hide_empty' => false]);
        if (!is_array($raw_categories) || empty($raw_categories)) {
            return [];
        }

        // Step 1: Collect valid positive term IDs
        $term_ids = [];
        foreach ($raw_categories as $cat) {
            $term_id = (int) ($cat->term_id ?? 0);
            if ($term_id > 0) {
                $term_ids[$term_id] = true;
            }
        }

        // Step 2: Build normalized payload
        $categories = [];
        foreach ($raw_categories as $cat) {
            $term_id = (int) ($cat->term_id ?? 0);
            if ($term_id <= 0) {
                continue;
            }

            $name        = sanitize_text_field((string) ($cat->name ?? ''));
            $slug        = sanitize_title((string) ($cat->slug ?? ''));
            $description = sanitize_text_field((string) ($cat->description ?? ''));
            $parent_id   = (int) ($cat->parent ?? 0);
            $post_count  = (int) ($cat->count ?? 0);

            // Self-parenting check: category cannot be its own parent
            if ($parent_id === $term_id) {
                $parent_id = 0;
            }

            // Missing parent reference check: parent must exist in same snapshot
            if ($parent_id > 0 && !isset($term_ids[$parent_id])) {
                $parent_id = 0;
            }

            $categories[] = [
                'term_id'        => $term_id,
                'name'           => mb_substr($name, 0, 191, 'UTF-8'),
                'slug'           => mb_substr($slug, 0, 200, 'UTF-8'),
                'description'    => mb_substr($description, 0, 1000, 'UTF-8'),
                'parent_term_id' => $parent_id,
                'post_count'     => max(0, $post_count),
            ];

            if (count($categories) >= self::MAX_CATEGORIES) {
                break;
            }
        }

        return $categories;
    }

    /**
     * Builds post items for a slice of post IDs.
     *
     * @param int[] $post_ids
     * @return array[]
     */
    public function prepare_posts_batch(array $post_ids): array {
        global $wpdb;

        if (empty($post_ids)) {
            return [];
        }

        $post_items = [];
        foreach ($post_ids as $id) {
            $post_id = (int) $id;
            if ($post_id <= 0) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post) {
                // If get_post returns null, query database directly
                if ($wpdb) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fallback direct read for post data
                    $row = $wpdb->get_row($wpdb->prepare("SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id));
                    if ($row) {
                        $post = $row;
                    }
                }
            }

            $title   = $post ? (string) $post->post_title : '';
            $content = $post ? (string) $post->post_content : '';

            $training_text = self::prepare_training_text($title, $content);
            if (strlen(trim($training_text)) === 0) {
                $training_text = sprintf('Post #%d', $post_id);
            }

            $content_hash = self::compute_content_hash($title, $content);

            // Fetch assigned WordPress category term IDs
            $cat_ids = function_exists('wp_get_post_categories')
                ? wp_get_post_categories($post_id, ['fields' => 'ids'])
                : [];

            $assigned_term_ids = [];
            if (is_array($cat_ids)) {
                foreach ($cat_ids as $cid) {
                    $cid_int = (int) $cid;
                    if ($cid_int > 0) {
                        $assigned_term_ids[] = $cid_int;
                    }
                }
            }

            $post_items[] = [
                'post_id'           => $post_id,
                'title'             => sanitize_text_field(mb_substr($title, 0, 382, 'UTF-8')),
                'training_text'     => $training_text,
                'assigned_term_ids' => array_values(array_unique($assigned_term_ids)),
                'content_hash'      => $content_hash,
            ];
        }

        return $post_items;
    }

    /**
     * Start a new snapshot synchronization session.
     *
     * 1. Freezes training-eligible post IDs.
     * 2. Calls FastAPI POST /ingest/sync/start with total_posts_expected.
     * 3. Stages current site taxonomy via POST /ingest/taxonomy.
     * 4. Stores session state in a server-side transient.
     *
     * @param int $user_id Current administrator user ID.
     * @return array|\WP_Error
     */
    public function start_sync(int $user_id) {
        // Step 1: Freeze training-eligible post IDs
        $eligible_ids = $this->get_training_eligible_post_ids();
        $total_posts  = count($eligible_ids);

        // Step 2: Start FastAPI sync session with exact expected post count
        $start_res = $this->client->sync_start($total_posts);
        if (is_wp_error($start_res)) {
            return $this->normalize_error($start_res);
        }

        $sync_id = (string) ($start_res['sync_id'] ?? '');
        if (empty($sync_id)) {
            return new \WP_Error('invalid_sync_id', self::normalize_error_code(502), ['status' => 502]);
        }

        // Step 3: Stage site taxonomy
        $categories = $this->prepare_taxonomy_payload();
        if (empty($categories)) {
            // Default category fallback if WordPress has no categories
            $categories = [
                [
                    'term_id'        => 1,
                    'name'           => 'General',
                    'slug'           => 'general',
                    'description'    => '',
                    'parent_term_id' => 0,
                    'post_count'     => $total_posts,
                ],
            ];
        }

        $tax_res = $this->client->sync_taxonomy($sync_id, $categories);
        if (is_wp_error($tax_res)) {
            return $this->normalize_error($tax_res);
        }

        // Step 4: Persist session in transient
        $total_batches = $total_posts > 0 ? (int) ceil($total_posts / self::BATCH_SIZE) : 0;
        $session = [
            'sync_id'       => $sync_id,
            'post_ids'      => $eligible_ids,
            'total_posts'   => $total_posts,
            'batch_size'    => self::BATCH_SIZE,
            'total_batches' => $total_batches,
            'created_at'    => time(),
        ];

        set_transient($this->get_transient_key($user_id), $session, HOUR_IN_SECONDS);

        // Return client-safe response (NEVER exposes sync_id)
        return [
            'status'        => 'started',
            'total_posts'   => $total_posts,
            'total_batches' => $total_batches,
            'batch_size'    => self::BATCH_SIZE,
        ];
    }

    /**
     * Process and upload a single batch of posts.
     *
     * @param int $user_id Current administrator user ID.
     * @param int $batch_index 0-indexed batch number.
     * @return array|\WP_Error
     */
    public function process_batch(int $user_id, int $batch_index) {
        $session = get_transient($this->get_transient_key($user_id));
        if (!is_array($session) || empty($session['sync_id'])) {
            return new \WP_Error(
                'no_active_sync',
                __('No active synchronization session found. Please restart synchronization.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        $sync_id       = (string) $session['sync_id'];
        $post_ids      = (array) ($session['post_ids'] ?? []);
        $total_posts   = (int) ($session['total_posts'] ?? 0);
        $total_batches = (int) ($session['total_batches'] ?? 0);

        if ($batch_index < 0) {
            return new \WP_Error('invalid_batch', __('Invalid batch index.', 'zerisetaxo'), ['status' => 400]);
        }

        // Slice up to 100 posts for this batch
        $batch_slice = array_slice($post_ids, $batch_index * self::BATCH_SIZE, self::BATCH_SIZE);
        if (empty($batch_slice) && $total_posts > 0) {
            return new \WP_Error('batch_out_of_range', __('Batch index exceeds post range.', 'zerisetaxo'), ['status' => 400]);
        }

        $posts_payload = $this->prepare_posts_batch($batch_slice);
        $upload_res    = $this->client->sync_posts($sync_id, $posts_payload, $batch_index);

        if (is_wp_error($upload_res)) {
            return $this->normalize_error($upload_res);
        }

        $processed_posts = min(($batch_index + 1) * self::BATCH_SIZE, $total_posts);
        $is_last_batch   = ($batch_index + 1 >= $total_batches);

        return [
            'batch_index'     => $batch_index,
            'processed_posts' => $processed_posts,
            'total_posts'     => $total_posts,
            'is_last_batch'   => $is_last_batch,
        ];
    }

    /**
     * Commit the staged snapshot session after all batches succeed.
     *
     * @param int $user_id Current administrator user ID.
     * @return array|\WP_Error
     */
    public function commit_sync(int $user_id) {
        $session = get_transient($this->get_transient_key($user_id));
        if (!is_array($session) || empty($session['sync_id'])) {
            return new \WP_Error(
                'no_active_sync',
                __('No active synchronization session found. Please restart synchronization.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        $sync_id     = (string) $session['sync_id'];
        $total_posts = (int) ($session['total_posts'] ?? 0);

        $commit_res = $this->client->sync_commit($sync_id);
        if (is_wp_error($commit_res)) {
            return $this->normalize_error($commit_res);
        }

        // Commit succeeded! Update persistent options
        update_option(self::OPTION_SYNC_STATUS, 'synced');
        update_option(self::OPTION_LAST_SYNCED_AT, time());
        update_option(self::OPTION_SYNCED_POST_COUNT, $total_posts);

        // Clear session transient
        delete_transient($this->get_transient_key($user_id));

        return [
            'status'       => 'synced',
            'message'      => __('Site data synchronized', 'zerisetaxo'),
            'model_status' => __('Personalized model not built yet', 'zerisetaxo'),
            'total_posts'  => $total_posts,
        ];
    }

    /**
     * Cancel an active synchronization session and clear temporary state.
     *
     * @param int $user_id
     * @return bool
     */
    public function cancel_sync(int $user_id): bool {
        return delete_transient($this->get_transient_key($user_id));
    }

    /**
     * Get the current synchronization state for the admin UI.
     *
     * @return array
     */
    public function get_sync_status(): array {
        $status      = get_option(self::OPTION_SYNC_STATUS, 'not_synced');
        $last_synced = (int) get_option(self::OPTION_LAST_SYNCED_AT, 0);
        $post_count  = (int) get_option(self::OPTION_SYNCED_POST_COUNT, 0);

        return [
            'status'            => $status,
            'is_synchronized'   => ('synced' === $status),
            'last_synced_at'    => $last_synced,
            'synced_post_count' => $post_count,
        ];
    }

    /**
     * Get the resolved sync & remote readiness display view-model.
     * Guarantees that historical sync records are never presented as proof
     * that an unavailable/restarted ephemeral inference server is currently ready.
     *
     * @param bool $is_service_connected
     * @param array|null $site_intel
     * @return array
     */
    public function get_sync_display_state(bool $is_service_connected, ?array $site_intel = null): array {
        $sync_info   = $this->get_sync_status();
        $last_synced = $sync_info['last_synced_at'];
        $post_count  = $sync_info['synced_post_count'];

        $has_synced = ($last_synced > 0 && 'synced' === $sync_info['status']);

        $model_info        = $this->get_model_status();
        $site_model_status = $model_info['status'] ?? 'not_built';
        $site_model_ver    = $model_info['version'] ?? 'None';

        $sync_label = $has_synced
            ? /* translators: %d: Number of synchronized posts. */
            sprintf(__('Last site sync completed: %d posts', 'zerisetaxo'), $post_count)
            : __('Not synchronized', 'zerisetaxo');

        // Remote readiness is a separate current state
        if (!$is_service_connected) {
            $remote_readiness_status = 'unavailable';
            $remote_readiness_label  = __('Remote model readiness unavailable (inference service disconnected)', 'zerisetaxo');
            $remote_readiness_detail = __('Historical sync records are preserved locally, but remote readiness cannot be verified while disconnected.', 'zerisetaxo');
            $is_remote_ready         = false;
        } else {
            $is_remote_ready         = !empty($site_intel['site_model_available']);
            $remote_readiness_status = $is_remote_ready ? 'ready' : ('needs_more_data' === $site_model_status ? 'needs_more_data' : 'not_built');
            $remote_readiness_label  = $is_remote_ready
                ? /* translators: %s: Model version identifier. */
                sprintf(__('Personalized model active (%s)', 'zerisetaxo'), $site_model_ver)
                : ('needs_more_data' === $site_model_status ? __('Site needs more category training data', 'zerisetaxo') : __('Personalized model not built yet', 'zerisetaxo'));
            $remote_readiness_detail = '';
        }

        return [
            'has_synced'              => $has_synced,
            'last_synced_at'          => $last_synced,
            'synced_post_count'       => $post_count,
            'sync_label'              => $sync_label,
            'is_service_connected'    => $is_service_connected,
            'is_remote_ready'         => $is_remote_ready,
            'remote_readiness_status' => $remote_readiness_status,
            'remote_readiness_label'  => $remote_readiness_label,
            'remote_readiness_detail' => $remote_readiness_detail,
        ];
    }

    /**
     * Trigger site-model rebuild on FastAPI from the active committed snapshot.
     *
     * @return array|\WP_Error
     */
    public function rebuild_model() {
        $sync_info = $this->get_sync_status();
        if (empty($sync_info['is_synchronized'])) {
            return new \WP_Error(
                'not_synchronized',
                __('Site data must be synchronized before rebuilding the model.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        $rebuild_res = $this->client->rebuild_site_model('site_model');
        if (is_wp_error($rebuild_res)) {
            return $this->normalize_error($rebuild_res);
        }

        $status  = sanitize_text_field($rebuild_res['status'] ?? 'ready');
        $version = sanitize_text_field($rebuild_res['version'] ?? '');
        $acc     = isset($rebuild_res['accuracy']) ? (float) $rebuild_res['accuracy'] : null;
        $f1      = isset($rebuild_res['macro_f1']) ? (float) $rebuild_res['macro_f1'] : null;
        $cats    = is_array($rebuild_res['trainable_categories'] ?? null) ? $rebuild_res['trainable_categories'] : [];
        $message = sanitize_text_field($rebuild_res['message'] ?? '');

        // Persist model metadata options
        update_option(self::OPTION_MODEL_STATUS, $status);
        if (!empty($version)) {
            update_option(self::OPTION_MODEL_VERSION, $version);
        }
        if ($acc !== null) {
            update_option(self::OPTION_MODEL_ACCURACY, $acc);
        }
        if ($f1 !== null) {
            update_option(self::OPTION_MODEL_MACRO_F1, $f1);
        }
        update_option(self::OPTION_LAST_REBUILT_AT, time());

        return [
            'status'               => $status,
            'version'              => $version,
            'trainable_categories' => $cats,
            'accuracy'             => $acc,
            'macro_f1'             => $f1,
            'message'              => $message,
            'rebuilt_at'           => time(),
        ];
    }

    /**
     * Get the current site model rebuild status for the admin UI.
     *
     * @return array
     */
    public function get_model_status(): array {
        $status          = get_option(self::OPTION_MODEL_STATUS, 'not_built');
        $version         = get_option(self::OPTION_MODEL_VERSION, 'None');
        $acc             = get_option(self::OPTION_MODEL_ACCURACY, null);
        $f1              = get_option(self::OPTION_MODEL_MACRO_F1, null);
        $last_rebuilt_at = (int) get_option(self::OPTION_LAST_REBUILT_AT, 0);

        return [
            'status'          => $status,
            'version'         => $version,
            'accuracy'        => $acc !== null ? (float) $acc : null,
            'macro_f1'        => $f1 !== null ? (float) $f1 : null,
            'last_rebuilt_at' => $last_rebuilt_at,
            'is_built'        => ('ready' === $status),
        ];
    }

    /**
     * Normalizes errors into clean, user-friendly localized messages without leaking
     * internal FastAPI details, stack traces, or credentials.
     *
     * @param \WP_Error $error
     * @return \WP_Error
     */
    protected function normalize_error(\WP_Error $error): \WP_Error {
        $code = 0;
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            $code = (int) $data['status'];
        }

        $err_msg = strtolower($error->get_error_message());
        if (strpos($err_msg, 'timed out') !== false || strpos($err_msg, 'timeout') !== false || strpos($err_msg, 'curl error 28') !== false) {
            $code = 504;
        }

        $normalized_msg = self::normalize_error_code($code);

        return new \WP_Error(
            'sync_error_' . $code,
            $normalized_msg,
            ['status' => ($code >= 400 && $code < 600) ? $code : 502]
        );
    }

    /**
     * Maps HTTP status codes to user-friendly error messages.
     *
     * @param int $code
     * @return string
     */
    public static function normalize_error_code(int $code): string {
        switch ($code) {
            case 401:
            case 403:
                return __('Authentication or connection issue with the inference service.', 'zerisetaxo');

            case 409:
                return __('Incomplete or conflicting synchronization. Please try again.', 'zerisetaxo');

            case 422:
                return __('Invalid site taxonomy or post data. Please verify your categories and posts.', 'zerisetaxo');

            case 429:
                return __('Synchronization is temporarily rate limited. Please wait a moment and try again.', 'zerisetaxo');

            case 504:
                return __('Inference service is unreachable or timed out.', 'zerisetaxo');

            default:
                if ($code >= 500 && $code <= 599) {
                    return __('Inference service is temporarily unavailable. Please try again shortly.', 'zerisetaxo');
                }
                return __('Synchronization encountered an unexpected error. Normal site operation is unaffected.', 'zerisetaxo');
        }
    }
}
