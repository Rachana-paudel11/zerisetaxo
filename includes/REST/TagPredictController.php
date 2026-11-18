<?php
namespace ZeriseTaxo\REST;

use ZeriseTaxo\ML\Tags\TagCandidateExtractor;

defined('ABSPATH') || exit;

/**
 * REST API Controller for V1 Tag Candidate Predictions.
 *
 * Provides read-only candidate tag suggestions for editor sessions.
 * Guarantees zero database modifications during prediction.
 */
class TagPredictController {

    /**
     * REST namespace.
     */
    const NAMESPACE_PRIMARY = 'zerisetaxo/v1';

    /**
     * Route path.
     */
    const ROUTE = '/predict-tags';

    /**
     * Maximum allowed request body content length in bytes (50 KB).
     */
    const MAX_CONTENT_LENGTH = 50000;

    /**
     * Register REST API routes.
     */
    public function register_routes(): void {
        $route_args = [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_predict_tags'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'content' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'wp_strip_all_tags',
                ],
                'title' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'excerpt' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 5,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ];

        register_rest_route(self::NAMESPACE_PRIMARY, self::ROUTE, $route_args);
    }

    /**
     * Check permission for REST endpoint.
     * Requires user authentication and post editing capability.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_permissions(\WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_not_logged_in',
                __('You must be logged in to request tag predictions.', 'zerisetaxo'),
                ['status' => 401]
            );
        }

        if (!current_user_can('edit_posts')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to edit posts.', 'zerisetaxo'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle POST /predict-tags request.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_predict_tags(\WP_REST_Request $request): \WP_REST_Response {
        if (!get_option('zerisetaxo_enable_suggestions', true) || !get_option('zerisetaxo_enable_tag_suggestions', true)) {
            return new \WP_REST_Response([
                'status'      => 'disabled',
                'suggestions' => [],
                'explanation' => __('Tag suggestions are currently disabled in plugin settings.', 'zerisetaxo'),
                'debug'       => [
                    'extracted_candidates' => 0,
                    'database_changed'     => false,
                ],
            ], 200);
        }
        $raw_content = (string) $request->get_param('content');
        $content     = function_exists('strip_shortcodes') ? strip_shortcodes($raw_content) : $raw_content;
        $content     = wp_strip_all_tags($content);
        $title       = (string) $request->get_param('title');
        $raw_excerpt = (string) $request->get_param('excerpt');
        $excerpt     = function_exists('strip_shortcodes') ? strip_shortcodes($raw_excerpt) : $raw_excerpt;
        $excerpt     = wp_strip_all_tags($excerpt);
        $default_limit = (int) get_option('zerisetaxo_tag_suggestion_limit', 5);
        $limit         = (int) ($request->get_param('limit') ?: $default_limit);

        // Limit limit parameter range (1 to 10)
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 10) {
            $limit = 10;
        }

        // Payload size safety guard (Unicode-safe with fallback)
        if (function_exists('mb_strlen') && mb_strlen($content, 'UTF-8') > self::MAX_CONTENT_LENGTH) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH, 'UTF-8');
        } elseif (strlen($content) > self::MAX_CONTENT_LENGTH) {
            $content = substr($content, 0, self::MAX_CONTENT_LENGTH);
        }

        $suggestions = TagCandidateExtractor::extract_candidates($content, $title, $excerpt, $limit);

        $explanation = !empty($suggestions)
            ? __('ZeriseTaxo found these terms to be strongly related to the post content.', 'zerisetaxo')
            : __('No strong tag suggestions found for this post.', 'zerisetaxo');

        return new \WP_REST_Response([
            'status'      => 'success',
            'suggestions' => $suggestions,
            'explanation' => $explanation,
            'debug'       => [
                'extracted_candidates' => count($suggestions),
                'database_changed'     => false,
            ],
        ], 200);
    }
}
