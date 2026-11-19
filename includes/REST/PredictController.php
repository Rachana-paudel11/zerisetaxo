<?php
namespace ZeriseTaxo\REST;

use ZeriseTaxo\Services\InferenceClient;
use ZeriseTaxo\Database\FeedbackRepository;

defined('ABSPATH') || exit;

/**
 * REST API Controller for category predictions.
 */
class PredictController {

    /**
     * Primary REST route namespace.
     */
    const NAMESPACE_PRIMARY = 'zerisetaxo/v1';

    /**
     * Route path.
     */
    const ROUTE = '/predict';

    /**
     * Maximum pending suggestions allowed per post.
     */
    const MAX_PENDING_PER_POST = 20;

    /**
     * Inference client instance.
     *
     * @var InferenceClient|null
     */
    protected $client;

    /**
     * Prediction engine instance.
     *
     * @var \ZeriseTaxo\ML\PredictionEngineInterface|null
     */
    protected $engine;

    /**
     * Constructor.
     *
     * @param InferenceClient|null                                  $client
     * @param \ZeriseTaxo\ML\PredictionEngineInterface|null $engine
     */
    public function __construct(?InferenceClient $client = null, ?\ZeriseTaxo\ML\PredictionEngineInterface $engine = null) {
        $this->client = $client;
        if (func_num_args() >= 2) {
            $this->engine = $engine;
        } elseif ($client !== null && class_exists(\ZeriseTaxo\ML\FastApiPredictionEngine::class)) {
            $this->engine = new \ZeriseTaxo\ML\FastApiPredictionEngine($client);
        } elseif ($client === null && class_exists(\ZeriseTaxo\ML\PhpPredictionEngine::class)) {
            $model_path = defined('ZERISETAXO_PATH')
                ? ZERISETAXO_PATH . 'models/php/runtime/'
                : dirname(__DIR__, 2) . '/models/php/runtime/';
            if (is_dir($model_path) && file_exists($model_path . 'metadata_float64.json')) {
                $this->engine = \ZeriseTaxo\ML\PhpPredictionEngine::from_file($model_path);
            } else {
                $json_path = defined('ZERISETAXO_PATH')
                    ? ZERISETAXO_PATH . 'models/php/global-model.json'
                    : dirname(__DIR__, 2) . '/models/php/global-model.json';
                if (file_exists($json_path)) {
                    $this->engine = \ZeriseTaxo\ML\PhpPredictionEngine::from_file($json_path);
                }
            }
        }
        // engine remains null when ML classes are not loaded (legacy test contexts)
    }

    /**
     * Returns active prediction engine.
     *
     * @return \ZeriseTaxo\ML\PredictionEngineInterface|null
     */
    public function get_engine(): ?\ZeriseTaxo\ML\PredictionEngineInterface {
        return $this->engine;
    }

    /**
     * Sets or overrides prediction engine.
     *
     * @param \ZeriseTaxo\ML\PredictionEngineInterface|null $engine
     */
    public function set_engine(?\ZeriseTaxo\ML\PredictionEngineInterface $engine): void {
        $this->engine = $engine;
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        $args = [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_predict'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'post_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && intval($param) > 0;
                    },
                    'sanitize_callback' => 'absint',
                ],
                'enable_zero_shot' => [
                    'required'          => false,
                    'type'              => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
                'enable_llm' => [
                    'required'          => false,
                    'type'              => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ];

        // Register primary endpoint /wp-json/zerisetaxo/v1/predict
        register_rest_route(self::NAMESPACE_PRIMARY, self::ROUTE, $args);

        // Register category application endpoint /wp-json/zerisetaxo/v1/apply (Strict term_id only)
        register_rest_route(
            self::NAMESPACE_PRIMARY,
            '/apply',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_apply_category'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'term_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'validate_callback' => function ($param) {
                            return is_numeric($param) && intval($param) > 0;
                        },
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // Register new category creation & application endpoint /wp-json/zerisetaxo/v1/create-and-apply
        register_rest_route(
            self::NAMESPACE_PRIMARY,
            '/create-and-apply',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_create_and_apply_category'],
                'permission_callback' => [$this, 'check_create_permissions'],
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'category_name' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // Register queue suggestion endpoint /wp-json/zerisetaxo/v1/queue-suggestion
        register_rest_route(
            self::NAMESPACE_PRIMARY,
            '/queue-suggestion',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_queue_suggestion'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'category_name' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * Check user permissions for category creation.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_create_permissions(\WP_REST_Request $request) {
        $edit_check = $this->check_permissions($request);
        if (is_wp_error($edit_check)) {
            return $edit_check;
        }

        if (!current_user_can('manage_categories')) {
            return new \WP_Error(
                'rest_cannot_create_category',
                __('You do not have permission to create categories.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Check user permissions for the request.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_permissions(\WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_not_logged_in',
                __('You must be logged in to request a category prediction.', 'zerisetaxo'),
                ['status' => 401]
            );
        }

        $post_id = $request->get_param('post_id');
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'post') {
                return new \WP_Error(
                    'invalid_post',
                    __('Invalid post ID provided or post does not exist.', 'zerisetaxo'),
                    ['status' => 400]
                );
            }

            if (!current_user_can('edit_post', $post_id)) {
                return new \WP_Error(
                    'rest_forbidden_post',
                    __('You do not have permission to edit this post.', 'zerisetaxo'),
                    ['status' => 403]
                );
            }
        }

        if (!current_user_can('edit_posts')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You lack permission to edit posts.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle the prediction request.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_predict(\WP_REST_Request $request) {
        $post_id        = absint($request->get_param('post_id'));
        $post           = $post_id ? get_post($post_id) : null;
        $client_title   = $request->get_param('client_title');
        $client_content = $request->get_param('client_content');

        // If post_id is provided and post exists, verify it is a valid post type
        if ($post && !in_array($post->post_type, ['post', 'revision'], true)) {
            return new \WP_Error(
                'invalid_post',
                __('Invalid post type provided. Predictions are supported for posts.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        // Fetch post title + content from post object or editor payload (supports new unsaved posts/drafts)
        $title = $post ? trim($post->post_title) : '';
        $raw_post_content = ($post && function_exists('strip_shortcodes')) ? strip_shortcodes($post->post_content) : ($post ? $post->post_content : '');
        $content = wp_strip_all_tags($raw_post_content);

        if (!empty($client_title)) {
            $title = trim(sanitize_text_field($client_title));
        }
        if (!empty($client_content)) {
            $raw_client_content = function_exists('strip_shortcodes') ? strip_shortcodes($client_content) : $client_content;
            $content = wp_strip_all_tags($raw_client_content);
        }

        $text = trim($title . "\n\n" . $content);

        if (empty($text)) {
            return new \WP_Error(
                'empty_post_content',
                __('Post title and body are both empty. Add text before predicting.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        // Fetch all active categories from WordPress with taxonomy metadata
        $categories           = get_categories(['hide_empty' => false]);
        $available_categories = !empty($categories) ? wp_list_pluck($categories, 'name') : [];
        $category_details     = [];
        foreach ($categories as $cat) {
            $parent_name = $cat->parent ? get_cat_name($cat->parent) : '';
            $category_details[] = [
                'term_id'     => (int) $cat->term_id,
                'name'        => $cat->name,
                'slug'        => $cat->slug,
                'description' => $cat->description,
                'parent_name' => $parent_name,
            ];
        }

        // Check if suggestions are enabled globally
        $suggestions_enabled = (bool) get_option('zerisetaxo_enable_suggestions', true);
        if (!$suggestions_enabled) {
            return new \WP_Error(
                'suggestions_disabled',
                __('Category suggestions are currently disabled in plugin settings.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        // Resolve prediction engine options (request payload overrides global option)
        $json_params        = $request->get_json_params();
        $param_local_model  = isset($json_params['enable_local_model']) ? $json_params['enable_local_model'] : $request->get_param('enable_local_model');
        $param_zero_shot    = isset($json_params['enable_zero_shot']) ? $json_params['enable_zero_shot'] : $request->get_param('enable_zero_shot');
        $param_llm          = isset($json_params['enable_llm']) ? $json_params['enable_llm'] : $request->get_param('enable_llm');

        $enable_local_model = ($param_local_model !== null)
            ? rest_sanitize_boolean($param_local_model)
            : (bool) get_option('zerisetaxo_enable_local_model', true);

        $enable_zero_shot = ($param_zero_shot !== null)
            ? rest_sanitize_boolean($param_zero_shot)
            : (bool) get_option('zerisetaxo_enable_zero_shot', true);

        $enable_llm = ($param_llm !== null)
            ? rest_sanitize_boolean($param_llm)
            : (bool) get_option('zerisetaxo_enable_llm', true);

        // Execute category prediction via injected PredictionEngineInterface
        $context = [
            'available_categories' => $available_categories,
            'category_details'     => $category_details,
            'post_id'              => $post_id,
            'title'                => $title,
            'confidence_threshold' => ((float) get_option('zerisetaxo_confidence_threshold', 75)) / 100.0,
            'ambiguity_margin'     => ((float) get_option('zerisetaxo_ambiguity_margin', 12)) / 100.0,
            'enable_zero_shot'     => $enable_zero_shot,
            'enable_llm'           => $enable_llm,
            'enable_local_model'   => $enable_local_model,
        ];

        if ($this->engine) {
            $result = $this->engine->predict_category($text, $context);
        } elseif ($this->client) {
            $result = $this->client->cascade_predict(
                $text,
                $available_categories,
                0.75,
                $title,
                $enable_zero_shot,
                $enable_llm,
                $enable_local_model,
                null,
                $category_details
            );
        } else {
            // PHP engine unavailable (model not found or not yet loaded).
            // Never block editor — return a graceful degradation response.
            return new \WP_Error(
                'zerisetaxo_engine_unavailable',
                __('Category suggestions are temporarily unavailable. You can still choose a category normally.', 'zerisetaxo'),
                ['status' => 503]
            );
        }

        if (is_wp_error($result)) {
            return $result;
        }

        if (!empty($result['error']) && isset($result['wp_error']) && is_wp_error($result['wp_error'])) {
            return $result['wp_error'];
        }

        $tier_used            = (int) $result['tier_used'];
        $tier_name            = sanitize_text_field($result['tier_name']);
        $result_type          = sanitize_text_field($result['result_type']);
        $category             = sanitize_text_field($result['category']);
        $suggested_category   = sanitize_text_field($result['suggested_category']);
        $confidence           = (float) $result['confidence'];
        $reasoning            = sanitize_text_field($result['reasoning']);
        $state                = sanitize_text_field($result['state'] ?? 'confident');
        $runner_up_category   = sanitize_text_field($result['runner_up_category'] ?? '');
        $runner_up_confidence = isset($result['runner_up_confidence']) ? (float) $result['runner_up_confidence'] : null;
        $margin               = isset($result['margin']) ? (float) $result['margin'] : null;
        $is_ood               = (bool) ($result['is_ood'] ?? false);
        $decision_source      = sanitize_text_field($result['decision_source'] ?? 'hybrid');

        // If Tier 3 suggested a brand new category and new categories are allowed, queue it in the suggestions table
        $allow_new_categories = (bool) get_option('zerisetaxo_allow_new_categories', true);
        if ($allow_new_categories && 'new_category_suggestion' === $result_type && !empty($suggested_category)) {
            global $wpdb;
            $table = esc_sql(\ZeriseTaxo\Database\Schema::get_table_name());

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE post_id = %d AND suggested_category = %s AND status = 'pending'",
                $post_id,
                $suggested_category
            ));

            if (!$existing_id) {
                $wpdb->insert($table, [
                    'post_id'            => $post_id,
                    'suggested_category' => $suggested_category,
                    'reason'             => $reasoning,
                    'status'             => 'pending',
                    'created_at'         => current_time('mysql'),
                ]);
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        // Find existing matching WordPress category term ID if one exists (Task 5: Prefer canonical term_id)
        $term_id = 0;
        if (!empty($result['term_id']) && (int) $result['term_id'] > 0) {
            $term_id = (int) $result['term_id'];
        } elseif ('existing' === $result_type && !empty($category)) {
            $term = get_term_by('name', $category, 'category');
            if ($term && !is_wp_error($term)) {
                $term_id = (int) $term->term_id;
            }
        }

        // Find runner-up term ID if one exists
        $runner_up_term_id = 0;
        if (!empty($runner_up_category)) {
            $ru_term = get_term_by('name', $runner_up_category, 'category');
            if ($ru_term && !is_wp_error($ru_term)) {
                $runner_up_term_id = (int) $ru_term->term_id;
            }
        }

                // Save prediction details as post meta (only for saved posts with ID > 0)
        $tier_breakdown = $result['tier_breakdown'] ?? [];
        if ($post_id > 0) {
            update_post_meta($post_id, '_zerisetaxo_predicted_category', $category);
            update_post_meta($post_id, '_zerisetaxo_predicted_category_id', $term_id);
            update_post_meta($post_id, '_zerisetaxo_prediction_confidence', $confidence);
            update_post_meta($post_id, '_zerisetaxo_prediction_reasoning', $reasoning);
            update_post_meta($post_id, '_zerisetaxo_prediction_tier', $tier_used);
            update_post_meta($post_id, '_zerisetaxo_prediction_tier_name', $tier_name);
            update_post_meta($post_id, '_zerisetaxo_prediction_tier_breakdown', $tier_breakdown);
            update_post_meta($post_id, '_zerisetaxo_enable_zero_shot', $enable_zero_shot ? 1 : 0);
            update_post_meta($post_id, '_zerisetaxo_enable_llm', $enable_llm ? 1 : 0);
            update_post_meta($post_id, '_zerisetaxo_prediction_state', $state);
            update_post_meta($post_id, '_zerisetaxo_decision_source', $decision_source);
        }

                // Apply Category Ordering Strategy (Category Order & Taxonomy Terms Order compatibility)
        $order_mode = get_option('zerisetaxo_category_order_mode', 'custom_order');
        if ('post_count' === $order_mode && !empty($runner_up_category) && $runner_up_term_id > 0) {
            $rec_term = get_term_by('name', $category, 'category');
            $ru_term  = get_term((int) $runner_up_term_id, 'category');
            if ($rec_term && !is_wp_error($rec_term) && $ru_term && !is_wp_error($ru_term)) {
                // If candidate scores are close (within 0.15 margin) and runner-up has higher post volume, promote it
                $conf_diff = abs($confidence - ($runner_up_confidence ?? 0));
                if ($ru_term->count > $rec_term->count && $conf_diff <= 0.15) {
                    $swp_cat  = $category;
                    $swp_conf = $confidence;
                    $category = $runner_up_category;
                    $confidence = $runner_up_confidence;
                    $runner_up_category = $swp_cat;
                    $runner_up_confidence = $swp_conf;
                    $runner_up_term_id = (int) $rec_term->term_id;
                }
            }
        } elseif ('custom_order' === $order_mode && !empty($runner_up_category) && $runner_up_term_id > 0) {
            $custom_order_str = get_option('zerisetaxo_custom_category_order', '');
            if (!empty($custom_order_str)) {
                $saved_order_ids = array_map('intval', explode(',', $custom_order_str));
                $order_map = array_flip($saved_order_ids);
                $rec_term = get_term_by('name', $category, 'category');
                if ($rec_term && !is_wp_error($rec_term)) {
                    $pos_rec = $order_map[$rec_term->term_id] ?? 999999;
                    $pos_ru  = $order_map[$runner_up_term_id] ?? 999999;
                    $conf_diff = abs($confidence - ($runner_up_confidence ?? 0));
                    if ($pos_ru < $pos_rec && $conf_diff <= 0.15) {
                        $swp_cat  = $category;
                        $swp_conf = $confidence;
                        $category = $runner_up_category;
                        $confidence = $runner_up_confidence;
                        $runner_up_category = $swp_cat;
                        $runner_up_confidence = $swp_conf;
                        $runner_up_term_id = (int) $rec_term->term_id;
                    }
                }
            }
        }

        $is_valid_runner_up = !empty($runner_up_category) && $runner_up_term_id > 0 && ($runner_up_confidence !== null && $runner_up_confidence >= 0.10);

        if ('ambiguous' === $state && !$is_valid_runner_up) {
            // Runner-up fails presentation quality gate (< 25% confidence): suppress runner-up and adjust presentation state
            $state = ($confidence >= 0.80) ? 'confident' : 'good_match';
            $runner_up_category   = '';
            $runner_up_term_id    = 0;
            $runner_up_confidence = null;
        }

        if ('ambiguous' === $state && $is_valid_runner_up && $runner_up_term_id > 0) {
            update_post_meta($post_id, '_zerisetaxo_runner_up_category', $runner_up_category);
            update_post_meta($post_id, '_zerisetaxo_runner_up_category_id', $runner_up_term_id);
            if ($runner_up_confidence !== null) {
                update_post_meta($post_id, '_zerisetaxo_runner_up_confidence', $runner_up_confidence);
            }
        } else {
            delete_post_meta($post_id, '_zerisetaxo_runner_up_category');
            delete_post_meta($post_id, '_zerisetaxo_runner_up_category_id');
            delete_post_meta($post_id, '_zerisetaxo_runner_up_confidence');
        }

        // Distinguish exact prediction states for user-facing workflow
        $is_new_cat_candidate = ('new_category_suggestion' === $result_type || 'no_existing_category' === $state) && !empty($suggested_category) && $confidence >= 0.25;
        $is_insufficient     = ('insufficient_data' === $state || empty($text));
        $is_pure_ood          = ($is_ood || $confidence < 0.25 || 'out_of_distribution' === $state) && !$is_new_cat_candidate;

        if ($is_insufficient) {
            $state            = 'insufficient_data';
            $result_type      = 'none';
            $term_id          = 0;
            $category         = '';
            $confidence_label = __('Insufficient content', 'zerisetaxo');
            $reasoning        = __('Post text contains insufficient content for classification.', 'zerisetaxo');
        } elseif ($is_new_cat_candidate && 'ambiguous' !== $state) {
            $state            = 'no_existing_category';
            $result_type      = 'new_category_suggestion';
            $term_id          = 0;
            $category         = !empty($category) ? $category : $suggested_category;
            $confidence_label = __('New Category Suggested', 'zerisetaxo');
            if (empty($reasoning)) {
                $reasoning = sprintf(/* translators: %s: Identified topic name. */
            __('Identified topic "%s", but no matching category exists in your WordPress taxonomy.', 'zerisetaxo'), $suggested_category);
            }
        } elseif (($is_pure_ood || $term_id <= 0) && 'ambiguous' !== $state) {
            $state            = 'out_of_distribution';
            $result_type      = 'none';
            $is_ood           = true;
            $term_id          = 0;
            $category         = '';
            $suggested_category = '';
            $confidence_label = __('No strong match', 'zerisetaxo');
            if (empty($reasoning)) {
                $reasoning = __('No strong match found. Try checking again or choose an existing category manually.', 'zerisetaxo');
            }
        } elseif ('ambiguous' === $state) {
            $confidence_label = __('Review suggested', 'zerisetaxo');
        } elseif ($confidence >= 0.80) {
            $confidence_label = __('Strong match', 'zerisetaxo');
        } else {
            $confidence_label = __('Good match', 'zerisetaxo');
        }

        // Re-save sanitized state, term_id, reasoning, and suggested_category to post meta
        update_post_meta($post_id, '_zerisetaxo_predicted_category', $category);
        update_post_meta($post_id, '_zerisetaxo_predicted_category_id', $term_id);
        update_post_meta($post_id, '_zerisetaxo_suggested_category', $suggested_category);
        update_post_meta($post_id, '_zerisetaxo_prediction_reasoning', $reasoning);
        update_post_meta($post_id, '_zerisetaxo_prediction_state', $state);
        update_post_meta($post_id, '_zerisetaxo_result_type', $result_type);

        // Resolve parent category info for main category & runner-up category if available
        $parent_id = 0;
        $parent_name = '';
        if ($term_id > 0) {
            $cat_term = get_term($term_id, 'category');
            if ($cat_term && !is_wp_error($cat_term) && $cat_term->parent > 0) {
                $parent_id = (int) $cat_term->parent;
                $parent_name = get_cat_name($parent_id);
            }
        }

        $runner_up_parent_id = 0;
        $runner_up_parent_name = '';
        if ($runner_up_term_id > 0) {
            $ru_cat_term = get_term($runner_up_term_id, 'category');
            if ($ru_cat_term && !is_wp_error($ru_cat_term) && $ru_cat_term->parent > 0) {
                $runner_up_parent_id = (int) $ru_cat_term->parent;
                $runner_up_parent_name = get_cat_name($runner_up_parent_id);
            }
        }

        // Build all candidate categories list for frontend chips display
        $candidate_categories_data = [];
        $raw_candidates = $result['candidate_categories'] ?? [];
        if (empty($raw_candidates) && !empty($category) && $term_id > 0) {
            $raw_candidates[] = ['category' => $category, 'confidence' => $confidence];
            if (!empty($runner_up_category) && $runner_up_term_id > 0) {
                $raw_candidates[] = ['category' => $runner_up_category, 'confidence' => $runner_up_confidence ?? 0.0];
            }
        }

        $seen_cand_ids = [];
        foreach ($raw_candidates as $cand) {
            $cand_name = sanitize_text_field($cand['category'] ?? '');
            if (empty($cand_name)) {
                continue;
            }
            $cand_term = get_term_by('name', $cand_name, 'category');
            if ($cand_term && !is_wp_error($cand_term)) {
                $cand_id = (int) $cand_term->term_id;
                if (!empty($seen_cand_ids[$cand_id])) {
                    continue;
                }
                $seen_cand_ids[$cand_id] = true;

                $cand_parent_id = ($cand_term->parent > 0) ? (int) $cand_term->parent : 0;
                $cand_parent_name = ($cand_parent_id > 0) ? get_cat_name($cand_parent_id) : '';

                $candidate_categories_data[] = [
                    'name'        => $cand_name,
                    'term_id'     => $cand_id,
                    'parent_id'   => $cand_parent_id,
                    'parent_name' => $cand_parent_name,
                    'confidence'  => isset($cand['confidence']) ? (float) $cand['confidence'] : 0.0,
                    'is_new'      => false,
                ];
            }
        }

        return new \WP_REST_Response([
            'post_id'                => $post_id,
            'tier_used'              => $tier_used,
            'tier_name'              => $tier_name,
            'result_type'            => $result_type,
            'category'               => $category,
            'suggested_category'     => $suggested_category,
            'confidence'             => $confidence,
            'confidence_label'       => $confidence_label,
            'reasoning'              => $reasoning,
            'category_id'            => $term_id,
            'parent_id'              => $parent_id,
            'parent_name'            => $parent_name,
            'runner_up_category'     => $runner_up_category,
            'runner_up_category_id'  => $runner_up_term_id,
            'runner_up_parent_id'    => $runner_up_parent_id,
            'runner_up_parent_name'  => $runner_up_parent_name,
            'runner_up_confidence'   => $runner_up_confidence,
            'candidate_categories'   => $candidate_categories_data,
            'scores'                 => $result['scores'] ?? [],
            'tier_breakdown'         => $tier_breakdown,
            'state'                  => $state,
            'margin'                 => $margin,
            'is_ood'                 => $is_ood,
            'decision_source'        => $decision_source,
            'can_manage_categories'  => current_user_can('manage_categories'),
            'timestamp'              => current_time('mysql'),
        ], 200);
    }

    /**
     * Handle applying an existing category directly to the post by canonical term_id.
     * Never creates a term if it does not exist.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_apply_category(\WP_REST_Request $request) {
        $post_id = absint($request->get_param('post_id'));
        $term_id = absint($request->get_param('term_id'));

        if ($term_id <= 0) {
            return new \WP_Error(
                'invalid_term_id',
                __('A valid numeric category term ID is required.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        // Verify term exists in WordPress under the 'category' taxonomy
        $term = get_term($term_id, 'category');
        if (!$term || is_wp_error($term) || $term->taxonomy !== 'category') {
            return new \WP_Error(
                'term_not_found',
                __('The specified category does not exist.', 'zerisetaxo'),
                ['status' => 404]
            );
        }

        // Assign category to post (preserving existing custom categories, but stripping default "Uncategorized")
        $default_cat_id = (int) get_option('default_category', 1);
        $current_cats   = wp_get_post_categories($post_id);
        $updated_cats   = array_diff($current_cats, [$default_cat_id]);
        $updated_cats[] = $term_id;

        $parent_id   = $term->parent ? (int) $term->parent : 0;
        $parent_name = $parent_id > 0 ? get_cat_name($parent_id) : '';
        if ($parent_id > 0) {
            $updated_cats[] = $parent_id;
        }

        wp_set_post_categories($post_id, array_values(array_unique($updated_cats)), false);

        // If there is an entry in the suggestions table for this post, mark it approved
        global $wpdb;
        $table = esc_sql(\ZeriseTaxo\Database\Schema::get_table_name());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom suggestions table
        $wpdb->update(
            $table,
            [
                'status'      => 'approved',
                'reviewed_at' => current_time('mysql'),
                'reviewed_by' => get_current_user_id(),
            ],
            [
                'post_id'            => $post_id,
                'suggested_category' => $term->name,
                'status'             => 'pending',
            ]
        );

        // Record site-specific feedback asynchronously
        $this->record_feedback($post_id, $term->name, 'admin_apply');

        return new \WP_REST_Response([
            'success'     => true,
            'post_id'     => $post_id,
            'category'    => $term->name,
            'category_id' => $term_id,
            'parent_id'   => $parent_id,
            'parent_name' => $parent_name,
            'created_new' => false,
            'message'     => sprintf(/* translators: %s: Category name. */
            __('Category "%s" applied to post.', 'zerisetaxo'), $term->name),
        ], 200);
    }

    /**
     * Handle creating a novel category and applying it to the post.
     * Strictly requires category creation permissions (e.g. manage_categories).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_create_and_apply_category(\WP_REST_Request $request) {
        if (!get_option('zerisetaxo_allow_new_categories', true)) {
            return new \WP_Error(
                'new_categories_disabled',
                __('Proposing new categories is currently disabled in plugin settings.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        if (!current_user_can('manage_categories')) {
            return new \WP_Error(
                'rest_cannot_create_category',
                __('You do not have permission to create categories.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        $post_id       = absint($request->get_param('post_id'));
        $category_name = sanitize_text_field(trim((string) $request->get_param('category_name')));

        if (empty($category_name)) {
            return new \WP_Error(
                'invalid_category_name',
                __('Category name cannot be empty.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        // Check if category already exists in WordPress
        $existing = term_exists($category_name, 'category');
        if ($existing) {
            $existing_id = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
            return new \WP_Error(
                'category_already_exists',
                sprintf(/* translators: 1: Category name, 2: Category ID. */
            __('Category "%1$s" already exists (ID: %2$d). Please select it from existing categories.', 'zerisetaxo'), $category_name, $existing_id),
                ['status' => 409]
            );
        }

        // Explicit creation of taxonomy term
        $inserted = wp_insert_term($category_name, 'category', [
            'description' => __('Created via AI Category Recommendation', 'zerisetaxo'),
        ]);

        if (is_wp_error($inserted)) {
            return $inserted;
        }

        $term_id = (int) $inserted['term_id'];

        // Assign category to post (preserving existing custom categories, but stripping default "Uncategorized")
        $default_cat_id = (int) get_option('default_category', 1);
        $current_cats   = wp_get_post_categories($post_id);
        $updated_cats   = array_diff($current_cats, [$default_cat_id]);
        $updated_cats[] = $term_id;
        wp_set_post_categories($post_id, array_values(array_unique($updated_cats)), false);

        // Update suggestion table if pending
        global $wpdb;
        $table = esc_sql(\ZeriseTaxo\Database\Schema::get_table_name());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom suggestions table
        $wpdb->update(
            $table,
            [
                'status'      => 'approved',
                'reviewed_at' => current_time('mysql'),
                'reviewed_by' => get_current_user_id(),
            ],
            [
                'post_id'            => $post_id,
                'suggested_category' => $category_name,
                'status'             => 'pending',
            ]
        );

        // Record site-specific feedback asynchronously
        $this->record_feedback($post_id, $category_name, 'admin_create_and_apply');

        return new \WP_REST_Response([
            'success'     => true,
            'post_id'     => $post_id,
            'category'    => $category_name,
            'category_id' => $term_id,
            'created_new' => true,
            'message'     => sprintf(/* translators: %s: Category name. */
            __('New category "%s" created and applied to post.', 'zerisetaxo'), $category_name),
        ], 201);
    }

    /**
     * Queue a new category candidate suggestion for admin review in Category Suggestions.
     * Strictly verifies permission and checks for existing equivalent categories.
     * Never creates or applies terms automatically.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_queue_suggestion(\WP_REST_Request $request) {
        if (!get_option('zerisetaxo_allow_new_categories', true)) {
            return new \WP_Error(
                'new_categories_disabled',
                __('Proposing new categories is currently disabled in plugin settings.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        $post_id       = absint($request->get_param('post_id'));
        $category_name = sanitize_text_field(trim((string) $request->get_param('category_name')));

        if (empty($category_name) || mb_strlen($category_name) > 191) {
            return new \WP_Error(
                'invalid_category_name',
                __('Category name must be a non-empty string under 191 characters.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        // Check if category already exists in WordPress
        $existing = term_exists($category_name, 'category');
        if ($existing) {
            $existing_id = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
            return new \WP_Error(
                'category_already_exists',
                sprintf(/* translators: 1: Category name, 2: Category ID. */
            __('Category "%1$s" already exists on your site (ID: #%2$d). Please select it from existing categories.', 'zerisetaxo'), $category_name, $existing_id),
                ['status' => 409]
            );
        }

        global $wpdb;
        $table = esc_sql(\ZeriseTaxo\Database\Schema::get_table_name());

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        $pending_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = 'pending'",
            $post_id
        ));

        if ($pending_count >= self::MAX_PENDING_PER_POST) {
            return new \WP_Error(
                'too_many_pending_suggestions',
                sprintf(
                    /* translators: %d: Maximum allowed pending suggestions. */
                    __('This post already has %d pending category suggestions. Please review or resolve existing suggestions before adding more.', 'zerisetaxo'),
                    self::MAX_PENDING_PER_POST
                ),
                ['status' => 429]
            );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        // Check if already queued
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND suggested_category = %s AND status = 'pending'",
            $post_id,
            $category_name
        ));

        if (!$existing_id) {
            $wpdb->insert($table, [
                'post_id'            => $post_id,
                'suggested_category' => $category_name,
                'reason'             => __('Queued for review from post editor recommendation', 'zerisetaxo'),
                'status'             => 'pending',
                'created_at'         => current_time('mysql'),
            ]);
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return new \WP_REST_Response([
            'success'     => true,
            'post_id'     => $post_id,
            'category'    => $category_name,
            'message'     => sprintf(/* translators: %s: Category name. */
            __('Category suggestion "%s" queued for review.', 'zerisetaxo'), $category_name),
        ], 200);
    }

    /**
     * Persist feedback to the durable log, then dispatch to FastAPI (Phase C).
     *
     * Order of operations (MUST be maintained):
     *  1. Collect post meta (predicted category, confidence, decision_source).
     *  2. INSERT row into zerisetaxo_feedback_log with synced_at = NULL.
     *  3. If insert succeeded, POST to FastAPI /feedback (blocking, with Bearer token).
     *  4. On 2xx: mark row synced. On failure: record error, leave synced_at NULL.
     *
     * A FastAPI outage MUST NOT fail this method or propagate to the REST response.
     *
     * @param int    $post_id
     * @param string $category
     * @param string $source
     */
    protected function record_feedback(int $post_id, string $category, string $source): void {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $predicted_category = (string) get_post_meta($post_id, '_zerisetaxo_predicted_category', true);
        $raw_confidence     = get_post_meta($post_id, '_zerisetaxo_prediction_confidence', true);
        $confidence         = ($raw_confidence !== '' && $raw_confidence !== false) ? (float) $raw_confidence : null;
        $decision_source    = (string) (get_post_meta($post_id, '_zerisetaxo_decision_source', true) ?: 'hybrid');
        $accepted           = ($predicted_category !== '' && $predicted_category === $category);

        // ── Step 1: Persist to DB first ──────────────────────────────────────
        $result = FeedbackRepository::insert([
            'post_id'            => $post_id,
            'category'           => $category,
            'source'             => $source,
            'predicted_category' => $predicted_category,
            'confidence'         => $confidence,
            'decision_source'    => $decision_source,
            'accepted'           => $accepted,
            'corrected_category' => $accepted ? null : $category,
        ]);

        if ($result === false) {
            // DB insert failed; skip dispatch (will be picked up by retry cron).
            return;
        }

        $row_id   = $result['id'];
        $event_id = $result['event_id'];

        // ── Step 2: Dispatch to FastAPI (failure must NOT propagate up) ───────
        if (!$this->client || !$this->client->is_configured()) {
            FeedbackRepository::mark_sync_failed($row_id, 'ML service not configured.');
            return;
        }

        $dispatch_result = $this->client->send_feedback(
            $event_id,
            $post_id,
            $category,
            $source,
            [
                'predicted_category' => $predicted_category,
                'confidence'         => $confidence,
                'decision_source'    => $decision_source,
                'accepted'           => $accepted,
                'corrected_category' => $accepted ? null : $category,
            ]
        );

        if (is_wp_error($dispatch_result)) {
            FeedbackRepository::mark_sync_failed($row_id, $dispatch_result->get_error_message());
        } else {
            FeedbackRepository::mark_synced($row_id);
        }
    }
}
