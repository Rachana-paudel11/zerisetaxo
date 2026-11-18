<?php
namespace ZeriseTaxo\REST;

use ZeriseTaxo\Database\Schema;
use ZeriseTaxo\Database\FeedbackRepository;
use ZeriseTaxo\Services\InferenceClient;

defined('ABSPATH') || exit;

/**
 * REST API Controller for AI Category Suggestions.
 *
 * Provides endpoints for creating, retrieving, approving, rejecting,
 * and merging AI-generated category suggestions.
 */
class SuggestionsController {

    /**
     * REST route namespace.
     */
    const NAMESPACE_PRIMARY = 'zerisetaxo/v1';

    /**
     * Maximum pending suggestions allowed per post.
     */
    const MAX_PENDING_PER_POST = 20;

    /**
     * Inference client instance (for authenticated feedback dispatch).
     *
     * @var InferenceClient|null
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param InferenceClient|null $client Optional. Required for feedback dispatch.
     */
    public function __construct(?InferenceClient $client = null) {
        $this->client = $client;
    }

    /**
     * Register all REST API routes.
     */
    public function register_routes() {
        // POST /wp-json/zerisetaxo/v1/suggestions (Create pending suggestion)
        register_rest_route(
            self::NAMESPACE_PRIMARY,
            '/suggestions',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handle_create_suggestion'],
                    'permission_callback' => [$this, 'check_create_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'validate_callback' => function ($param) {
                                return is_numeric($param) && intval($param) > 0;
                            },
                            'sanitize_callback' => 'absint',
                        ],
                        'suggested_category' => [
                            'required'          => true,
                            'type'              => 'string',
                            'validate_callback' => function ($param) {
                                $trimmed = trim($param);
                                return !empty($trimmed) && mb_strlen($trimmed) <= 191;
                            },
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'reason' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_textarea_field',
                        ],
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'handle_get_suggestions'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'status' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => 'pending',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'page' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'default'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                        'per_page' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'default'           => 20,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // POST /wp-json/zerisetaxo/v1/suggestions/{id}/approve
        register_rest_route(
            self::NAMESPACE_PRIMARY,
            '/suggestions/(?P<id>\d+)/approve',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_approve'],
                'permission_callback' => [$this, 'check_suggestion_post_permissions'],
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // POST /wp-json/zerisetaxo/v1/suggestions/{id}/reject
        register_rest_route(
            self::NAMESPACE_PRIMARY,
            '/suggestions/(?P<id>\d+)/reject',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_reject'],
                'permission_callback' => [$this, 'check_suggestion_post_permissions'],
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // POST /wp-json/zerisetaxo/v1/suggestions/{id}/merge
        register_rest_route(
            self::NAMESPACE_PRIMARY,
            '/suggestions/(?P<id>\d+)/merge',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_merge'],
                'permission_callback' => [$this, 'check_suggestion_post_permissions'],
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'existing_term_id' => [
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

        // GET /wp-json/zerisetaxo/v1/site-learning-state & /wp-json/zerisetaxo/v1/site-learning-state
        $register_state_route = function ($ns) {
            register_rest_route(
                $ns,
                '/site-learning-state',
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'handle_get_site_learning_state'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ]
            );
        };
        $register_state_route(self::NAMESPACE_PRIMARY);
        $register_state_route('zerisetaxo/v1');

        // POST /wp-json/zerisetaxo/v1/bootstrap/suggest & /wp-json/zerisetaxo/v1/bootstrap/suggest
        $register_bootstrap_suggest = function ($ns) {
            register_rest_route(
                $ns,
                '/bootstrap/suggest',
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handle_bootstrap_suggest'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'site_description' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_textarea_field',
                        ],
                        'topics' => [
                            'required'          => false,
                            'default'           => [],
                        ],
                        'niche' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'keywords' => [
                            'required'          => false,
                            'default'           => [],
                        ],
                    ],
                ]
            );
        };
        $register_bootstrap_suggest(self::NAMESPACE_PRIMARY);
        $register_bootstrap_suggest('zerisetaxo/v1');

        // POST /wp-json/zerisetaxo/v1/bootstrap/approve-suggestion & /wp-json/zerisetaxo/v1/bootstrap/approve-suggestion
        $register_bootstrap_approve = function ($ns) {
            register_rest_route(
                $ns,
                '/bootstrap/approve-suggestion',
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handle_approve_bootstrap_suggestion'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'name' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'action' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => 'approve',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'new_name' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'parent' => [
                            'required'          => false,
                            'default'           => null,
                        ],
                    ],
                ]
            );
        };
        $register_bootstrap_approve(self::NAMESPACE_PRIMARY);
        $register_bootstrap_approve('zerisetaxo/v1');
    }

    /**
     * Check permissions for machine-to-machine suggestion recording.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_create_permissions(\WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_not_logged_in',
                __('Authentication required to submit category suggestions.', 'zerisetaxo'),
                ['status' => 401]
            );
        }

        $post_id = $request->get_param('post_id');
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post) {
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
                __('You lack permission to submit category suggestions.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Check permissions for administrator suggestion management endpoints.
     *
     * Requires manage_categories capability and valid nonce for state-changing browser requests.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_admin_permissions(\WP_REST_Request $request) {
        if (!current_user_can('manage_categories')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You lack permission to manage category suggestions.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        // Strict nonce verification for admin-facing state-changing operations
        $method = $request->get_method();
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $nonce = $request->get_header('x_wp_nonce');
            if (empty($nonce)) {
                $nonce = $request->get_param('_wpnonce');
            }
            if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new \WP_Error(
                    'rest_forbidden_nonce',
                    __('Invalid or missing security nonce.', 'zerisetaxo'),
                    ['status' => 403]
                );
            }
        }

        return true;
    }

    /**
     * Permission callback verifying administrative taxonomy permissions AND
     * object-level authorization to edit the post associated with the suggestion.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_suggestion_post_permissions(\WP_REST_Request $request) {
        $admin_check = $this->check_admin_permissions($request);
        if (is_wp_error($admin_check)) {
            return $admin_check;
        }

        $id = absint($request->get_param('id'));
        if ($id <= 0) {
            return current_user_can('manage_categories');
        }

        global $wpdb;
        $table = esc_sql(Schema::get_table_name());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        $post_id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$table} WHERE id = %d", $id));
        if ($post_id <= 0) {
            return current_user_can('manage_categories');
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to edit the post associated with this suggestion.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle creating a new pending suggestion.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_create_suggestion(\WP_REST_Request $request) {
        if (!get_option('zerisetaxo_allow_new_categories', true)) {
            return new \WP_Error(
                'new_categories_disabled',
                __('Proposing new categories is currently disabled in plugin settings.', 'zerisetaxo'),
                ['status' => 403]
            );
        }
        global $wpdb;

        $post_id            = absint($request->get_param('post_id'));
        $suggested_category = sanitize_text_field(trim($request->get_param('suggested_category')));
        $reason             = sanitize_textarea_field(trim($request->get_param('reason')));

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return new \WP_Error(
                'invalid_post',
                __('Target post does not exist.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        if (empty($suggested_category) || mb_strlen($suggested_category) > 191) {
            return new \WP_Error(
                'invalid_category',
                __('Suggested category must be a non-empty string under 191 characters.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        $table = esc_sql(Schema::get_table_name());

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

        $now   = current_time('mysql');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom suggestions table
        $result = $wpdb->insert(
            $table,
            [
                'post_id'            => $post_id,
                'suggested_category' => $suggested_category,
                'reason'             => $reason,
                'status'             => 'pending',
                'created_at'         => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        if (false === $result) {
            return new \WP_Error(
                'db_insert_failed',
                __('Failed to save category suggestion to database.', 'zerisetaxo'),
                ['status' => 500]
            );
        }

        $suggestion_id = $wpdb->insert_id;

        return new \WP_REST_Response([
            'id'                 => $suggestion_id,
            'post_id'            => $post_id,
            'suggested_category' => $suggested_category,
            'reason'             => $reason,
            'status'             => 'pending',
            'created_at'         => $now,
            'message'            => __('Category suggestion saved as pending human review.', 'zerisetaxo'),
        ], 201);
    }

    /**
     * Handle retrieving suggestions (e.g. pending).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_get_suggestions(\WP_REST_Request $request) {
        global $wpdb;

        $status   = sanitize_text_field($request->get_param('status'));
        $page     = max(1, absint($request->get_param('page')));
        $per_page = min(100, max(1, absint($request->get_param('per_page'))));
        $offset   = ($page - 1) * $per_page;

        $table = esc_sql(Schema::get_table_name());

        $where_clauses = [];
        $params        = [];

        if (!empty($status) && 'all' !== $status) {
            $where_clauses[] = 's.status = %s';
            $params[]        = $status;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Count total
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql, dynamic params prepared
            $count_query = "SELECT COUNT(*) FROM {$table} s {$where_sql}";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total       = (int) $wpdb->get_var($wpdb->prepare($count_query, ...$params));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
            $total       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} s");
        }

        // Fetch paginated results with post title
        $select_params = array_merge($params, [$per_page, $offset]);

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic query with validated parameters
        $sql = $wpdb->prepare(
            "SELECT s.*, p.post_title, p.post_status 
             FROM {$table} s 
             LEFT JOIN {$wpdb->posts} p ON s.post_id = p.ID 
             {$where_sql} 
             ORDER BY s.created_at DESC 
             LIMIT %d OFFSET %d",
            ...$select_params
        );
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results($sql, ARRAY_A);

        $response = new \WP_REST_Response([
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil($total / $per_page),
        ], 200);

        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));

        return $response;
    }

    /**
     * Handle approving a suggestion.
     *
     * Creates the new WordPress category term via wp_insert_term(),
     * assigns it to the post, and marks the suggestion row as 'approved'.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_approve(\WP_REST_Request $request) {
        global $wpdb;

        $id    = absint($request->get_param('id'));
        $table = esc_sql(Schema::get_table_name());

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        $suggestion = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        if (!$suggestion) {
            return new \WP_Error(
                'suggestion_not_found',
                __('Category suggestion not found.', 'zerisetaxo'),
                ['status' => 404]
            );
        }

        if (!current_user_can('edit_post', $suggestion->post_id)) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to edit this post.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        if ('approved' === $suggestion->status) {
            return new \WP_Error(
                'already_approved',
                __('This suggestion has already been approved.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        // 1. Create or get WordPress category
        $term = term_exists($suggestion->suggested_category, 'category');
        if (!$term) {
            if (!current_user_can('manage_categories')) {
                return new \WP_Error(
                    'rest_forbidden',
                    __('You do not have permission to create categories.', 'zerisetaxo'),
                    ['status' => 403]
                );
            }

            $term_result = wp_insert_term(
                $suggestion->suggested_category,
                'category',
                [
                    'description' => !empty($suggestion->reason)
                        ? sprintf(/* translators: %s: Original suggestion title. */
            __('Auto-created via AI Suggestion: %s', 'zerisetaxo'), $suggestion->reason)
                        : '',
                ]
            );

            if (is_wp_error($term_result)) {
                return new \WP_Error(
                    'term_creation_failed',
                    $term_result->get_error_message(),
                    ['status' => 500]
                );
            }
            $term_id = (int) $term_result['term_id'];
        } else {
            $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
        }

        // 2. Assign the category to the post (append = true preserves existing categories)
        wp_set_post_categories($suggestion->post_id, [$term_id], true);

        // 3. Update database suggestion status
        $now     = current_time('mysql');
        $user_id = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom suggestions table
        $wpdb->update(
            $table,
            [
                'status'      => 'approved',
                'reviewed_at' => $now,
                'reviewed_by' => $user_id,
            ],
            ['id' => $id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        // 4. Dispatch approved sample to feedback collection loop if opted in
        $this->dispatch_telemetry_feedback((int) $suggestion->post_id, (string) $suggestion->suggested_category);

        return new \WP_REST_Response([
            'id'          => $id,
            'status'      => 'approved',
            'term_id'     => $term_id,
            'category'    => $suggestion->suggested_category,
            'post_id'     => (int) $suggestion->post_id,
            'reviewed_at' => $now,
            'reviewed_by' => $user_id,
            'message'     => sprintf(
                /* translators: 1: Category name, 2: Post ID. */
            __('Category "%1$s" approved, created, and assigned to post #%2$d.', 'zerisetaxo'),
                $suggestion->suggested_category,
                $suggestion->post_id
            ),
        ], 200);
    }

    /**
     * Handle rejecting a suggestion.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_reject(\WP_REST_Request $request) {
        global $wpdb;

        $id    = absint($request->get_param('id'));
        $table = esc_sql(Schema::get_table_name());

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        $suggestion = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        if (!$suggestion) {
            return new \WP_Error(
                'suggestion_not_found',
                __('Category suggestion not found.', 'zerisetaxo'),
                ['status' => 404]
            );
        }

        if (!current_user_can('edit_post', $suggestion->post_id)) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to edit this post.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        $now     = current_time('mysql');
        $user_id = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom suggestions table
        $wpdb->update(
            $table,
            [
                'status'      => 'rejected',
                'reviewed_at' => $now,
                'reviewed_by' => $user_id,
            ],
            ['id' => $id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        return new \WP_REST_Response([
            'id'          => $id,
            'status'      => 'rejected',
            'reviewed_at' => $now,
            'reviewed_by' => $user_id,
            'message'     => __('Category suggestion rejected.', 'zerisetaxo'),
        ], 200);
    }

    /**
     * Handle merging a suggestion into an existing WordPress category.
     *
     * Assigns the existing category to the post and marks suggestion as 'merged'.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_merge(\WP_REST_Request $request) {
        global $wpdb;

        $id               = absint($request->get_param('id'));
        $existing_term_id = absint($request->get_param('existing_term_id'));
        $table            = esc_sql(Schema::get_table_name());

        // Validate that target category term exists
        $term = get_term_by('id', $existing_term_id, 'category');
        if (!$term || is_wp_error($term)) {
            return new \WP_Error(
                'invalid_target_category',
                __('The selected target category does not exist.', 'zerisetaxo'),
                ['status' => 400]
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table escaped via esc_sql
        $suggestion = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        if (!$suggestion) {
            return new \WP_Error(
                'suggestion_not_found',
                __('Category suggestion not found.', 'zerisetaxo'),
                ['status' => 404]
            );
        }

        if (!current_user_can('edit_post', $suggestion->post_id)) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to edit this post.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        // 1. Assign existing category to post (append)
        wp_set_post_categories($suggestion->post_id, [$existing_term_id], true);

        // 2. Update suggestion record
        $now     = current_time('mysql');
        $user_id = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom suggestions table
        $wpdb->update(
            $table,
            [
                'status'              => 'merged',
                'merged_into_term_id' => $existing_term_id,
                'reviewed_at'         => $now,
                'reviewed_by'         => $user_id,
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%d'],
            ['%d']
        );

        return new \WP_REST_Response([
            'id'                  => $id,
            'status'              => 'merged',
            'merged_into_term_id' => $existing_term_id,
            'category_name'       => $term->name,
            'post_id'             => (int) $suggestion->post_id,
            'reviewed_at'         => $now,
            'reviewed_by'         => $user_id,
            'message'             => sprintf(
                /* translators: 1: Post ID, 2: Category name. */
            __('Post #%1$d merged into existing category "%2$s".', 'zerisetaxo'),
                $suggestion->post_id,
                $term->name
            ),
        ], 200);
    }

    /**
     * Persist approved training sample to the feedback log, then dispatch to FastAPI if opted in.
     *
     * Order of operations (MUST be maintained):
     *  1. Persist to zerisetaxo_feedback_log with synced_at = NULL.
     *  2. If telemetry opt-in is active AND client is configured: dispatch to FastAPI.
     *  3. On 2xx: mark synced. On failure: record error, leave synced_at NULL.
     *
     * @param int    $post_id
     * @param string $category
     */
    protected function dispatch_telemetry_feedback(int $post_id, string $category): void {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // ── Step 1: Always persist (regardless of telemetry opt-in) ─────────
        $result = FeedbackRepository::insert([
            'post_id'            => $post_id,
            'category'           => $category,
            'source'             => 'admin_approval',
            'predicted_category' => '',
            'confidence'         => null,
            'decision_source'    => null,
            'accepted'           => true,
            'corrected_category' => null,
        ]);

        if ($result === false) {
            return;
        }

        $row_id   = $result['id'];
        $event_id = $result['event_id'];

        // ── Step 2: Dispatch only if client is configured ──────────────────
        if (!$this->client || !$this->client->is_configured()) {
            FeedbackRepository::mark_sync_failed($row_id, 'ML service not configured.');
            return;
        }

        $dispatch_result = $this->client->send_feedback(
            $event_id,
            $post_id,
            $category,
            'admin_approval',
            [
                'predicted_category' => '',
                'confidence'         => null,
                'decision_source'    => null,
                'accepted'           => true,
                'corrected_category' => null,
            ]
        );

        if (is_wp_error($dispatch_result)) {
            FeedbackRepository::mark_sync_failed($row_id, $dispatch_result->get_error_message());
        } else {
            FeedbackRepository::mark_synced($row_id);
        }
    }

    /**
     * GET /site-learning-state
     * Returns structured site learning state, metrics, and thresholds.
+    *
+    * @param \WP_REST_Request $request
+    * @return \WP_REST_Response
+    */
    public function handle_get_site_learning_state(\WP_REST_Request $request): \WP_REST_Response {
        $detector = new \ZeriseTaxo\ML\Site\SiteStateDetector();
        return new \WP_REST_Response($detector->detect(), 200);
    }

    /**
     * POST /bootstrap/suggest
     * Generates structured taxonomy suggestions from site description, topics,
     * page evidence, and existing taxonomy.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_bootstrap_suggest(\WP_REST_Request $request): \WP_REST_Response {
        $site_description = sanitize_textarea_field((string) $request->get_param('site_description'));
        $raw_topics = $request->get_param('topics');
        if (is_string($raw_topics)) {
            $topics = array_filter(array_map('trim', explode(',', $raw_topics)));
        } else {
            $topics = array_map('sanitize_text_field', (array) $raw_topics);
        }

        $niche = sanitize_text_field((string) $request->get_param('niche'));

        $raw_keywords = $request->get_param('keywords');
        if (is_string($raw_keywords)) {
            $keywords = array_filter(array_map('trim', explode(',', $raw_keywords)));
        } else {
            $keywords = array_map('sanitize_text_field', (array) $raw_keywords);
        }

        // Collect page evidence
        $pages_context = \ZeriseTaxo\ML\Site\WordPressPageCollector::collect(20);

        // Collect existing category taxonomy structure
        $existing_terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);
        $existing_taxonomy = [];
        if (!is_wp_error($existing_terms) && is_array($existing_terms)) {
            foreach ($existing_terms as $t) {
                if ((int) $t->term_id !== 1 && strtolower($t->name) !== 'uncategorized') {
                    $existing_taxonomy[] = [
                        'term_id'   => (int) $t->term_id,
                        'name'      => (string) $t->name,
                        'parent_id' => (int) $t->parent,
                    ];
                }
            }
        }

        $payload = [
            'site_description'  => $site_description,
            'topics'            => array_values($topics),
            'niche'             => $niche,
            'keywords'          => array_values($keywords),
            'pages_context'     => $pages_context,
            'existing_taxonomy' => $existing_taxonomy,
        ];

        // Try FastAPI dispatch if configured
        if ($this->client && $this->client->is_configured()) {
            $response = $this->client->bootstrap_taxonomy_suggest($payload);
            if (!is_wp_error($response) && is_array($response) && isset($response['suggestions'])) {
                return new \WP_REST_Response($response, 200);
            }
        }

        // Local PHP Fallback Generator for offline resilience
        $suggestions = $this->generate_local_bootstrap_suggestions($payload);

        return new \WP_REST_Response([
            'suggestions' => $suggestions,
            'count'       => count($suggestions),
            'source'      => 'local_fallback',
        ], 200);
    }

    /**
     * Local PHP Fallback Generator for taxonomy bootstrap suggestions.
     */
    public function generate_local_bootstrap_suggestions(array $payload): array {

        $topics = (array) ($payload['topics'] ?? []);
        $desc   = (string) ($payload['site_description'] ?? '');
        $pages  = (array) ($payload['pages_context'] ?? []);
        $existing = (array) ($payload['existing_taxonomy'] ?? []);

        $existing_map = [];
        foreach ($existing as $ex) {
            $name_key = strtolower(trim((string) $ex['name']));
            $existing_map[$name_key] = (int) $ex['term_id'];
        }

        $candidates = [];

        // 1. Process explicit topics
        foreach ($topics as $tp) {
            $clean = sanitize_text_field(trim((string) $tp));
            if (empty($clean)) continue;

            $lower = strtolower($clean);
            $is_existing = isset($existing_map[$lower]);

            $candidates[] = [
                'name'                 => $clean,
                'type'                 => 'parent',
                'confidence'           => 0.90,
                'reason'               => 'Primary topic provided in site profile',
                'existing_category_id' => $is_existing ? $existing_map[$lower] : null,
                'match_type'           => $is_existing ? 'existing_match' : 'taxonomy_expansion',
            ];
        }

        // 2. Process pages context
        foreach ($pages as $p) {
            $title = sanitize_text_field(trim((string) ($p['title'] ?? '')));
            if (empty($title) || strlen($title) < 3) continue;

            $lower = strtolower($title);
            $is_existing = isset($existing_map[$lower]);

            $candidates[] = [
                'name'                 => $title,
                'type'                 => 'child',
                'parent'               => !empty($topics[0]) ? $topics[0] : null,
                'confidence'           => 0.82,
                'reason'               => 'Extracted from published page context',
                'existing_category_id' => $is_existing ? $existing_map[$lower] : null,
                'match_type'           => $is_existing ? 'existing_match' : 'taxonomy_expansion',
            ];
        }

        // Fallback default topics if none provided
        if (empty($candidates)) {
            $candidates[] = [
                'name'                 => 'General',
                'type'                 => 'parent',
                'confidence'           => 0.75,
                'reason'               => 'Default baseline category',
                'existing_category_id' => isset($existing_map['general']) ? $existing_map['general'] : null,
                'match_type'           => isset($existing_map['general']) ? 'existing_match' : 'taxonomy_expansion',
            ];
        }

        return $candidates;
    }

    /**
     * POST /bootstrap/approve-suggestion
     * Handles admin Approve, Reject, or Edit actions for cold-start category suggestions.
     *
     * NEVER creates categories automatically. Executes ONLY on explicit admin submission.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_approve_bootstrap_suggestion(\WP_REST_Request $request): \WP_REST_Response {
        $raw_name = sanitize_text_field((string) $request->get_param('name'));
        $action   = sanitize_text_field((string) $request->get_param('action')); // approve, reject, edit
        $new_name = sanitize_text_field((string) $request->get_param('new_name'));
        $parent   = $request->get_param('parent');

        if (empty($raw_name)) {
            return new \WP_REST_Response([
                'status'  => 'error',
                'message' => __('Category name is required.', 'zerisetaxo'),
            ], 400);
        }

        // Handle Rejection Action
        if ($action === 'reject') {
            FeedbackRepository::insert([
                'post_id'            => 0,
                'category'           => $raw_name,
                'source'             => 'bootstrap_rejection',
                'predicted_category' => $raw_name,
                'confidence'         => null,
                'decision_source'    => 'bootstrap',
                'accepted'           => false,
                'corrected_category' => null,
            ]);

            return new \WP_REST_Response([
                'status'   => 'rejected',
                'category' => $raw_name,
                'message'  => sprintf(/* translators: %s: Suggestion title. */
            __('Suggestion "%s" rejected.', 'zerisetaxo'), $raw_name),
            ], 200);
        }

        // Handle Approve or Edit Action
        $target_name = ($action === 'edit' && !empty($new_name)) ? $new_name : $raw_name;

        // Check if category already exists in WordPress
        $term_obj = get_term_by('name', $target_name, 'category');
        $created  = false;
        $term_id  = 0;

        if ($term_obj && !is_wp_error($term_obj)) {
            $term_id = (int) $term_obj->term_id;
        } else {
            // Resolve parent ID if parent category name or term_id provided
            $parent_id = 0;
            if (!empty($parent)) {
                if (is_numeric($parent) && (int) $parent > 0) {
                    $parent_id = (int) $parent;
                } else {
                    $parent_term = get_term_by('name', (string) $parent, 'category');
                    if ($parent_term && !is_wp_error($parent_term)) {
                        $parent_id = (int) $parent_term->term_id;
                    }
                }
            }

            $inserted = wp_insert_term($target_name, 'category', ['parent' => $parent_id]);
            if (is_wp_error($inserted)) {
                return new \WP_REST_Response([
                    'status'  => 'error',
                    'message' => $inserted->get_error_message(),
                ], 400);
            }

            $term_id = (int) $inserted['term_id'];
            $created = true;
        }

        // Persist validated decision to FeedbackRepository for tenant-isolated future rebuild jobs
        FeedbackRepository::insert([
            'post_id'            => 0,
            'category'           => $target_name,
            'source'             => 'bootstrap_approval',
            'predicted_category' => $raw_name,
            'confidence'         => null,
            'decision_source'    => 'bootstrap',
            'accepted'           => true,
            'corrected_category' => ($target_name !== $raw_name) ? $target_name : null,
        ]);

        return new \WP_REST_Response([
            'status'        => 'approved',
            'term_id'       => $term_id,
            'category_name' => $target_name,
            'created'       => $created,
            'message'       => sprintf(/* translators: %s: Category name. */
            __('Category "%s" approved and active.', 'zerisetaxo'), $target_name),
        ], 200);
    }
}
