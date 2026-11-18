<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * Site-Specific Model Service (Phase 10).
 *
 * Coordinates PHP-native site-specific learning:
 * - Readiness evaluation (not_ready, limited, ready, healthy, needs_rebuild)
 * - Safe training and rebuilding via WordPressDataCollector + NaiveBayesTrainer
 * - Safety gates evaluation (token coverage, confidence, margin)
 * - Canonical term_id validation
 * - Incremental updates on post publish
 * - Rebuild scheduling on edits/deletions
 */
class SiteModelService {

    const STATE_NOT_READY     = 'not_ready';
    const STATE_LIMITED       = 'limited';
    const STATE_READY         = 'ready';
    const STATE_HEALTHY       = 'healthy';
    const STATE_NEEDS_REBUILD = 'needs_rebuild';
    const STATE_REBUILD_FAILED = 'rebuild_failed';

    const OPTION_ENABLED        = 'zerisetaxo_site_learning_enabled';
    const OPTION_NEEDS_REBUILD  = 'zerisetaxo_site_model_needs_rebuild';
    const OPTION_REBUILD_RESULT = 'zerisetaxo_site_model_rebuild_result';
    const CRON_HOOK             = 'zerisetaxo_cron_rebuild_site_model';

    /**
     * Transient key prefix used to deduplicate incremental post-publish training.
     * Prevents the same post from being trained twice on autosave / Gutenberg double-fire.
     */
    const TRANSIENT_TRAINED_PREFIX = 'zerisetaxo_trained_';

    /**
     * Default Phase 9 safety gate thresholds.
     */
    const DEFAULT_MIN_TOKEN_COVERAGE = 0.30;
    const DEFAULT_MIN_CONFIDENCE     = 0.45;
    const DEFAULT_MIN_MARGIN         = 0.10;

    /**
     * Cached active trainer instance.
     *
     * @var NaiveBayesTrainer|null
     */
    private ?NaiveBayesTrainer $trainer = null;

    /**
     * Configurable safety gate thresholds.
     */
    private float $min_token_coverage;
    private float $min_confidence;
    private float $min_margin;

    public function __construct(
        float $min_token_coverage = self::DEFAULT_MIN_TOKEN_COVERAGE,
        float $min_confidence     = self::DEFAULT_MIN_CONFIDENCE,
        float $min_margin         = self::DEFAULT_MIN_MARGIN
    ) {
        $this->min_token_coverage = $min_token_coverage;
        $this->min_confidence     = $min_confidence;
        $this->min_margin         = $min_margin;
    }

    /**
     * Check if site learning is enabled via feature flag.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    /**
     * Register WordPress hooks for incremental learning and rebuild scheduling.
     */
    public static function register_hooks(): void {
        // Incremental learning on new post publish
        add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);

        // Schedule rebuild on edits, deletions, or category changes
        add_action('post_updated', [__CLASS__, 'on_post_updated'], 10, 3);
        add_action('deleted_post', [__CLASS__, 'on_post_deleted'], 10, 1);
        add_action('delete_category', [__CLASS__, 'on_category_changed'], 10, 1);
        add_action('edited_category', [__CLASS__, 'on_category_changed'], 10, 1);

        // Bind cron rebuild worker
        add_action(self::CRON_HOOK, [__CLASS__, 'cron_rebuild_worker']);
    }

    /**
     * Evaluate current readiness state based on empirical evidence from Phase 9.
     *
     * States:
     * - needs_rebuild: Flagged due to post edit, post deletion, or category modification.
     * - not_ready:     < 2 categories OR < 10 total training posts.
     * - limited:       Valid model, but some categories have < 5 posts.
     * - healthy:       >= 5 categories with >= 10 posts each.
     * - ready:         >= 2 categories with >= 5 posts each.
     *
     * @return string One of self::STATE_* constants
     */
    public function get_readiness_state(): string {
        $last_result = get_option(self::OPTION_REBUILD_RESULT, null);
        if (is_array($last_result) && isset($last_result['state']) && $last_result['state'] === self::STATE_REBUILD_FAILED) {
            return self::STATE_REBUILD_FAILED;
        }

        if ((bool) get_option(self::OPTION_NEEDS_REBUILD, false)) {
            return self::STATE_NEEDS_REBUILD;
        }

        $meta = SiteModelStore::load_meta();
        if (empty($meta) || !is_array($meta)) {
            return self::STATE_NOT_READY;
        }

        $class_count   = (int) ($meta['class_count'] ?? 0);
        $training_docs = (int) ($meta['training_docs'] ?? 0);
        $min_docs_cat  = (int) ($meta['min_docs_per_cat'] ?? 0);

        $min_posts = (int) get_option('zerisetaxo_min_posts_training', 10);
        $min_cats  = (int) get_option('zerisetaxo_min_categories_training', 2);
        if ($class_count < $min_cats || $training_docs < $min_posts) {
            return self::STATE_NOT_READY;
        }

        if ($min_docs_cat < 5) {
            return self::STATE_LIMITED;
        }

        if ($class_count >= 5 && $training_docs >= 50 && $min_docs_cat >= 10) {
            return self::STATE_HEALTHY;
        }

        return self::STATE_READY;
    }

    /**
     * Check whether the site model is in an operational state for predictions.
     *
     * STATE_NEEDS_REBUILD is intentionally excluded: while the model is flagged
     * for rebuild, predictions may be stale. V1 safe behaviour is to use the
     * global PHP model until a successful cron or manual rebuild completes.
     *
     * @return bool
     */
    public function is_operational(): bool {
        $state = $this->get_readiness_state();
        return in_array($state, [self::STATE_READY, self::STATE_HEALTHY, self::STATE_LIMITED], true)
            && SiteModelStore::is_available();
    }

    /**
     * Load or restore the NaiveBayesTrainer lazily.
     *
     * @return NaiveBayesTrainer|null
     */
    public function get_trainer(): ?NaiveBayesTrainer {
        if ($this->trainer !== null) {
            return $this->trainer;
        }

        $restored = SiteModelStore::restore_trainer();
        if ($restored instanceof NaiveBayesTrainer) {
            $this->trainer = $restored;
            return $this->trainer;
        }

        return null;
    }

    /**
     * Execute prediction with Phase 9 Safety Gates:
     * 1. Token coverage >= 0.30
     * 2. Top-1 Confidence >= 0.45
     * 3. Top-1 vs Top-2 Margin >= 0.10
     * 4. Canonical term_id exists in WordPress
     *
     * @param string $text    Post title + content.
     * @param array  $context Additional context.
     * @return array Standardized result with status ('success' | 'gate_failed' | 'term_missing' | 'unavailable')
     */
    public function predict(string $text, array $context = []): array {
        if (!$this->is_operational()) {
            return [
                'status' => 'unavailable',
                'reason' => 'site_model_not_ready',
                'state'  => $this->get_readiness_state(),
            ];
        }

        $trainer = $this->get_trainer();
        if (!$trainer || $trainer->get_total_docs() === 0) {
            return [
                'status' => 'unavailable',
                'reason' => 'trainer_empty_or_corrupt',
            ];
        }

        $tokens = SiteTokenizer::tokenize($text);
        if (empty($tokens)) {
            return [
                'status' => 'gate_failed',
                'reason' => 'empty_tokens',
            ];
        }

        // Gate 1: Check known-token coverage against vocabulary
        $vocab_size = $trainer->get_vocabulary_size();
        $export = $trainer->export();
        $class_tokens = $export['class_token_counts'] ?? [];
        $known_count = 0;
        foreach ($tokens as $tok) {
            foreach ($class_tokens as $counts) {
                if (isset($counts[$tok])) {
                    $known_count++;
                    break;
                }
            }
        }
        $coverage = count($tokens) > 0 ? ($known_count / count($tokens)) : 0.0;
        if ($coverage < $this->min_token_coverage) {
            return [
                'status'   => 'gate_failed',
                'reason'   => 'low_token_coverage',
                'coverage' => round($coverage, 4),
                'threshold'=> $this->min_token_coverage,
            ];
        }

        // Execute Naive Bayes inference
        $raw = $trainer->predict($text);
        if (!$raw) {
            return [
                'status' => 'unavailable',
                'reason' => 'prediction_returned_null',
            ];
        }

        $term_id    = (int) ($raw['term_id'] ?? 0);
        $confidence = (float) ($raw['confidence'] ?? 0.0);
        $margin     = (float) ($raw['margin'] ?? 0.0);
        $term_name  = (string) ($raw['name'] ?? '');

        // Gate 2: Confidence threshold
        if ($confidence < $this->min_confidence) {
            return [
                'status'     => 'gate_failed',
                'reason'     => 'low_confidence',
                'confidence' => $confidence,
                'threshold'  => $this->min_confidence,
            ];
        }

        // Gate 3: Top-1 vs Top-2 Margin threshold
        if ($margin < $this->min_margin) {
            return [
                'status'    => 'gate_failed',
                'reason'    => 'low_margin',
                'margin'    => $margin,
                'threshold' => $this->min_margin,
            ];
        }

        // Gate 4: Canonical term_id exists in WordPress
        if ($term_id > 0 && function_exists('get_term')) {
            $term = get_term($term_id, 'category');
            if (!$term || is_wp_error($term)) {
                // Predicted term no longer exists in WordPress
                self::mark_needs_rebuild();
                return [
                    'status'  => 'term_missing',
                    'reason'  => 'term_id_deleted_from_wordpress',
                    'term_id' => $term_id,
                ];
            }
            // Use active WordPress term name if renamed
            $term_name = $term->name;
        }

        return [
            'status'               => 'success',
            'term_id'              => $term_id,
            'name'                 => $term_name,
            'confidence'           => $confidence,
            'margin'               => $margin,
            'coverage'             => round($coverage, 4),
            'runner_up_id'         => $raw['runner_up_id'] ?? null,
            'runner_up_name'       => $raw['runner_up_name'] ?? '',
            'runner_up_confidence' => $raw['runner_up_confidence'] ?? null,
            'scores'               => $raw['scores'] ?? [],
        ];
    }

    /**
     * Train or rebuild the site-specific model from historical WordPress posts.
     *
     * @return array
     */
    public function train_from_site(): array {
        $collection = WordPressDataCollector::collect([
            'posts_per_page'       => -1,
            'include_draft'        => false,
            'multi_cat_first_only' => true,
        ]);

        $posts        = $collection['posts'] ?? [];
        $category_map = $collection['category_map'] ?? [];
        $stats        = $collection['stats'] ?? [];

        $class_count  = count($category_map);
        $total_docs   = count($posts);
        $min_docs_cat = empty($category_map) ? 0 : min(array_column($category_map, 'count'));

        // Check hard minimum data rules
        $min_posts = (int) get_option('zerisetaxo_min_posts_training', 10);
        $min_cats  = (int) get_option('zerisetaxo_min_categories_training', 2);
        if ($class_count < $min_cats || $total_docs < $min_posts) {
            $meta = [
                'algorithm'        => 'naive_bayes',
                'trained_at'       => time(),
                'training_docs'    => $total_docs,
                'class_count'      => $class_count,
                'class_names'      => array_column($category_map, 'name'),
                'min_docs_per_cat' => $min_docs_cat,
                'status'           => self::STATE_NOT_READY,
            ];
            update_option(SiteModelStore::OPTION_META, $meta, false);
            $res = [
                'success' => false,
                'state'   => self::STATE_NOT_READY,
                'message' => sprintf(
                    /* translators: 1: Number of categories found, 2: Number of posts found. */
            sprintf(__('Insufficient data: Found %%1$d categories and %%2$d posts. Minimum required is %1$d categories and %2$d posts.', 'zerisetaxo'), $min_cats, $min_posts),
                    $class_count,
                    $total_docs
                ),
                'stats'   => $stats,
            ];
            update_option(self::OPTION_REBUILD_RESULT, [
                'success'    => false,
                'state'      => self::STATE_NOT_READY,
                'message'    => $res['message'],
                'rebuilt_at' => time(),
            ], false);
            return $res;
        }

        // Train Naive Bayes
        $t0 = microtime(true);
        $trainer = new NaiveBayesTrainer(alpha: 1.0);
        $trainer->train($posts);
        $train_duration_ms = (microtime(true) - $t0) * 1000;

        $export = $trainer->export();

        // Save model
        SiteModelStore::save($export, $total_docs, 'naive_bayes');

        // Reset needs_rebuild flag
        update_option(self::OPTION_NEEDS_REBUILD, 0, false);

        // Determine readiness state
        $state = self::STATE_READY;
        if ($min_docs_cat < 5) {
            $state = self::STATE_LIMITED;
        } elseif ($class_count >= 5 && $total_docs >= 50 && $min_docs_cat >= 10) {
            $state = self::STATE_HEALTHY;
        }

        // Augment metadata with readiness and stats
        $meta = SiteModelStore::load_meta() ?? [];
        $meta['status']           = $state;
        $meta['min_docs_per_cat'] = $min_docs_cat;
        $meta['train_time_ms']    = round($train_duration_ms, 3);
        update_option(SiteModelStore::OPTION_META, $meta, false);

        $this->trainer = $trainer;

        $success_message = sprintf(
            /* translators: 1: Algorithm version, 2: Training post count, 3: Category count, 4: Training time in milliseconds. */
        __('Site model built successfully (%1$s): %2$d posts across %3$d categories in %4$.2f ms.', 'zerisetaxo'),
            ucfirst($state),
            $total_docs,
            $class_count,
            $train_duration_ms
        );

        update_option(self::OPTION_REBUILD_RESULT, [
            'success'    => true,
            'state'      => $state,
            'message'    => $success_message,
            'rebuilt_at' => time(),
        ], false);

        return [
            'success'       => true,
            'state'         => $state,
            'training_docs' => $total_docs,
            'class_count'   => $class_count,
            'train_time_ms' => round($train_duration_ms, 3),
            'message'       => $success_message,
        ];
    }

    /**
     * Incrementally add a newly published post to the site model.
     *
     * @param int $post_id
     * @return bool
     */
    public function handle_post_published(int $post_id): bool {
        if (!self::is_enabled()) {
            return false;
        }

        $trainer = $this->get_trainer();
        if (!$trainer) {
            // No model to add to yet; flag for future build
            self::mark_needs_rebuild();
            return false;
        }

        $post = function_exists('get_post') ? get_post($post_id) : null;
        if (!$post || ($post->post_type ?? '') !== 'post' || ($post->post_status ?? '') !== 'publish') {
            return false;
        }

        // Get post category
        $terms = function_exists('get_the_terms') ? get_the_terms($post_id, 'category') : [];
        if (empty($terms) || is_wp_error($terms)) {
            return false;
        }

        // Filter out Uncategorized
        $uncategorized = function_exists('get_category_by_slug') ? get_category_by_slug('uncategorized') : null;
        $uncat_id = $uncategorized ? (int) $uncategorized->term_id : 1;
        $valid_terms = array_values(array_filter($terms, fn($t) => (int) $t->term_id !== $uncat_id));
        if (empty($valid_terms)) {
            return false;
        }

        // Check primary category if defined
        $target_term = $valid_terms[0];
        $yoast_id = (int) get_post_meta($post_id, '_yoast_wpseo_primary_category', true);
        if ($yoast_id > 0) {
            foreach ($valid_terms as $vt) {
                if ((int) $vt->term_id === $yoast_id) { $target_term = $vt; break; }
            }
        }

        $text = trim($post->post_title . ' ' . wp_strip_all_tags($post->post_content));
        if (str_word_count($text) < WordPressDataCollector::MIN_WORD_COUNT) {
            return false;
        }

        // Incrementally train on single post
        $trainer->train([[
            'term_id' => (int) $target_term->term_id,
            'name'    => (string) $target_term->name,
            'title'   => (string) $post->post_title,
            'content' => (string) wp_strip_all_tags($post->post_content),
        ]]);

        // Re-export and save
        $export = $trainer->export();
        $total = $trainer->get_total_docs();
        SiteModelStore::save($export, $total, 'naive_bayes');

        return true;
    }

    /**
     * Mark that the site model requires a scheduled rebuild.
     */
    public static function mark_needs_rebuild(): void {
        update_option(self::OPTION_NEEDS_REBUILD, 1, false);

        // Schedule WP-Cron rebuild if not already scheduled
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time() + 60, self::CRON_HOOK);
            }
        }
    }

    /**
     * Cron worker for background rebuild.
     * Stores the last rebuild result for admin visibility.
     * Clears needs_rebuild only on success; leaves it set on failure so admin sees Needs Rebuild.
     */
    public static function cron_rebuild_worker(): void {
        $service = new self();
        try {
            $result = $service->train_from_site();
            update_option(self::OPTION_REBUILD_RESULT, [
                'success'    => $result['success'],
                'state'      => $result['state'] ?? 'unknown',
                'message'    => $result['message'] ?? '',
                'rebuilt_at' => time(),
            ], false);
        } catch (\Throwable $e) {
            // Store failure without exposing stack trace to admins
            update_option(self::OPTION_REBUILD_RESULT, [
                'success'    => false,
                'state'      => self::STATE_REBUILD_FAILED,
                'message'    => 'Rebuild failed. Please try rebuilding manually from the Settings page.',
                'rebuilt_at' => time(),
            ], false);
            // Keep needs_rebuild = 1 so next cron attempt or manual rebuild retries
        }
    }

    /**
     * WordPress hook callback: Transition post status.
     *
     * Deduplication: Each unique post_id is trained incrementally at most once per
     * 60-second window, preventing autosave, revision creation, Gutenberg REST saves,
     * and publish→publish no-op transitions from inflating token counts.
     *
     * If reliable deduplication fails for any reason, mark_needs_rebuild() is preferred
     * over risking corrupted token counts.
     */
    public static function on_transition_post_status(string $new_status, string $old_status, $post): void {
        if ($new_status !== 'publish' || $old_status === 'publish' || empty($post->ID)) {
            return;  // Only handle genuine new publications
        }

        $post_id = (int) $post->ID;

        // Guard: skip autosave posts
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return;
        }
        // Guard: skip revision posts
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }

        // Deduplication: use a short-lived transient to prevent double-fire
        $transient_key = self::TRANSIENT_TRAINED_PREFIX . $post_id;
        if (function_exists('get_transient') && get_transient($transient_key)) {
            // Already trained this post in the last 60 seconds; prefer needs_rebuild safety
            self::mark_needs_rebuild();
            return;
        }
        if (function_exists('set_transient')) {
            set_transient($transient_key, 1, 60);  // 60-second deduplication window
        }

        $service = new self();
        $service->handle_post_published($post_id);
    }

    /**
     * WordPress hook callback: Post updated.
     */
    public static function on_post_updated(int $post_id, $post_after, $post_before): void {
        if (!empty($post_after) && ($post_after->post_status ?? '') === 'publish') {
            // Flag needs rebuild for background cron re-sync
            self::mark_needs_rebuild();
        }
    }

    /**
     * WordPress hook callback: Post deleted.
     */
    public static function on_post_deleted(int $post_id): void {
        self::mark_needs_rebuild();
    }

    /**
     * WordPress hook callback: Category deleted or edited.
     */
    public static function on_category_changed(int $term_id): void {
        self::mark_needs_rebuild();
    }
}
