<?php
namespace ZeriseTaxo\Services;

defined('ABSPATH') || exit;

/**
 * Client for communicating with the local FastAPI ML inference microservice.
 */
class InferenceClient {

    /**
     * Default service base URL (empty: unconfigured by default).
     */
    const DEFAULT_BASE_URL = '';

    /**
     * Option name for storing the inference service endpoint.
     */
    const OPTION_SERVICE_URL = 'zerisetaxo_service_url';

    /**
     * Option name for storing the API Bearer token / secret credential.
     */
    const OPTION_API_TOKEN = 'zerisetaxo_api_token';

    /**
     * Option names for connection diagnostics tracking.
     */
    const OPTION_LAST_HEALTH_CHECK    = 'zerisetaxo_last_health_check';
    const OPTION_LAST_SUCCESSFUL_CONN = 'zerisetaxo_last_successful_connection';
    const OPTION_LAST_ERROR_REASON    = 'zerisetaxo_last_error_reason';

    /**
     * Request timeout constants (seconds).
     */
    const TIMEOUT_TEST_CONNECTION   = 30; // Tolerates free container wake-up (e.g. Render/Koyeb cold-start)
    const TIMEOUT_HEALTH_CHECK      = 10; // Routine service status check
    const TIMEOUT_CASCADE_PREDICT   = 30; // Multi-tier cascade prediction
    const TIMEOUT_SIMPLE_PREDICT    = 15; // Single-tier baseline predict
    const TIMEOUT_SITE_INTELLIGENCE = 10; // Site status metadata query
    const TIMEOUT_SYNC_START        = 15; // Snapshot sync initialization
    const TIMEOUT_SYNC_TAXONOMY     = 20; // Taxonomy stage
    const TIMEOUT_SYNC_POSTS        = 30; // Posts batch upload
    const TIMEOUT_SYNC_COMMIT       = 30; // Atomic snapshot commit
    const TIMEOUT_REBUILD_MODEL     = 60; // Site model cross-validation training
    const TIMEOUT_FEEDBACK          = 10; // Feedback dispatch

    /**
     * Check if the service URL is provided via wp-config.php constant.
     *
     * @return bool
     */
    public static function is_url_from_constant(): bool {
        if (!(defined('ZERISETAXO_SERVICE_URL') || defined('ML_WP_TAXONOMY_SERVICE_URL'))) {
            return false;
        }
        $val = trim((string) (defined('ZERISETAXO_SERVICE_URL') ? constant('ZERISETAXO_SERVICE_URL') : constant('ML_WP_TAXONOMY_SERVICE_URL')));
        return !empty($val);
    }

    /**
     * Alias for is_url_from_constant().
     *
     * @return bool
     */
    public function is_service_url_from_constant(): bool {
        return self::is_url_from_constant();
    }

    /**
     * Determine if a service URL points to localhost or a self-hosted local instance.
     *
     * @param string|null $url Optional URL to inspect; defaults to canonical service URL.
     * @return bool True if the URL host is localhost, 127.0.0.1, ::1, 0.0.0.0, or local domain.
     */
    public static function is_local_url(?string $url = null): bool {
        if ($url === null) {
            $url = self::get_canonical_service_url();
        }
        $url = trim((string) $url);
        if (empty($url)) {
            return true; // Unconfigured defaults to local development expectation
        }

        $host = function_exists('\\wp_parse_url') ? \wp_parse_url($url, PHP_URL_HOST) : wp_parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            $parts = explode(':', $url);
            $host = $parts[0];
        }

        if (empty($host)) {
            return true;
        }

        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    /**
     * Generate clear, accurate privacy copy based on the actual service endpoint and AI provider configuration.
     * Prevents making false claims that content never leaves the server when a remote hosted endpoint is configured.
     *
     * @param string|null $service_url
     * @param bool|null $enable_llm
     * @return string
     */
    public static function get_privacy_notice(?string $service_url = null, ?bool $enable_llm = null): string {
        $url = ($service_url !== null) ? trim($service_url) : self::get_canonical_service_url();
        if ($enable_llm === null) {
            $enable_llm = (bool) get_option('zerisetaxo_enable_llm', false);
        }

        if (empty($url)) {
            return __('No inference service endpoint is currently configured. Once configured with a local server, processing remains local; if configured with a remote hosted service, content is transmitted over HTTPS.', 'zerisetaxo');
        }

        $is_local = self::is_local_url($url);

        if ($is_local) {
            if ($enable_llm) {
                return __('The inference service is running locally on your server. Core ML predictions are processed locally; when prediction confidence is low or ambiguous, post content may additionally be sent to Google Gemini for category recommendations.', 'zerisetaxo');
            }
            return __('The inference service is running locally on your server. Core ML predictions are processed locally and no content ever leaves your server.', 'zerisetaxo');
        }

        // Remote hosted inference
        $display_url = preg_replace('#^https?://#', '', rtrim($url, '/'));
        if ($enable_llm) {
            return sprintf(
                /* translators: %s: Remote service hostname */
                __('Post content is transmitted securely to your configured inference service (%s) over HTTPS for Core ML processing. When prediction confidence is low or ambiguous, post content may additionally be sent to Google Gemini for category recommendations.', 'zerisetaxo'),
                esc_html($display_url)
            );
        }

        return sprintf(
            /* translators: %s: Remote service hostname */
            __('Post content is transmitted securely to your configured inference service (%s) over HTTPS for Core ML processing. Google Gemini is disabled, so no content is sent to third-party AI providers.', 'zerisetaxo'),
            esc_html($display_url)
        );
    }

    /**
     * Resolve the canonical service URL according to precedence:
     * 1. ML_WP_TAXONOMY_SERVICE_URL constant, if defined and non-empty.
     * 2. Saved WordPress option 'zerisetaxo_service_url'.
     *
     * @return string Canonical service URL without trailing slash, or empty string if unconfigured.
     */
    public static function get_canonical_service_url(): string {
        $url = '';

        if (self::is_url_from_constant()) {
            $url = trim((string) (defined('ZERISETAXO_SERVICE_URL') ? constant('ZERISETAXO_SERVICE_URL') : constant('ML_WP_TAXONOMY_SERVICE_URL')));
        } else {
            $saved = get_option(self::OPTION_SERVICE_URL, '');
            if ($saved !== null && $saved !== false) {
                $url = trim((string) $saved);
            }
        }

        $canonical = !empty($url) ? rtrim($url, '/') : '';

        return apply_filters('zerisetaxo_service_url', $canonical);
    }

    /**
     * Check whether an inference service endpoint has been explicitly configured.
     * Returns false if neither constant nor saved option provides a non-empty URL.
     *
     * @return bool
     */
    public function is_configured(): bool {
        $canonical = self::get_canonical_service_url();
        return !empty($canonical);
    }

    /**
     * Get the base URL for the inference service.
     *
     * @return string
     */
    public function get_base_url(): string {
        return self::get_canonical_service_url();
    }

    /**
     * Send post text to the Python inference service to predict its category.
     *
     * @param string $text Cleaned title + content of the post.
     * @return array|\WP_Error Array with 'category' and 'confidence' on success, or WP_Error on failure.
     */
    public function predict(string $text) {
        $endpoint = $this->get_base_url() . '/predict';

        $body = wp_json_encode([
            'text' => $text,
        ]);

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_SIMPLE_PREDICT,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => $body,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'zerisetaxo_service_offline',
                sprintf(
                    /* translators: %s: Error message */
                    /* translators: 1: Service URL endpoint, 2: Error message detail. */
                    __('ML inference service is unreachable at %1$s: %2$s', 'zerisetaxo'),
                    esc_html($endpoint),
                    $response->get_error_message()
                ),
                ['status' => 502]
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code !== 200 || !is_array($data)) {
            $detail = isset($data['detail']) ? $data['detail'] : __('Unexpected response from inference service.', 'zerisetaxo');
            return new \WP_Error(
                'zerisetaxo_service_error',
                $detail,
                ['status' => ($status_code >= 400 && $status_code < 600) ? $status_code : 502]
            );
        }

        if (!isset($data['category']) || !isset($data['confidence'])) {
            return new \WP_Error(
                'zerisetaxo_invalid_response',
                __('Inference response missing category or confidence data.', 'zerisetaxo'),
                ['status' => 502]
            );
        }

        return [
            'category'   => sanitize_text_field($data['category']),
            'confidence' => (float) $data['confidence'],
        ];
    }

    /**
     * Send post text and available categories to execute the 3-Tier Cascade.
     *
     * @param string $text Cleaned title + content of the post.
     * @param array $available_categories Current category names in WordPress.
     * @param float $threshold Confidence threshold (default 0.75).
     * @return array|\WP_Error Array with cascade details or WP_Error.
     */
    /**
     * Get the persistent site UUID as canonical ML identity.
     * Stored in wp_options so it does not change on HTTP->HTTPS or domain migrations.
     * Can be overridden by constant ML_WP_TAXONOMY_SITE_UUID if defined.
     *
     * @return string
     */
    public function get_site_id(): string {
        if ((defined('ZERISETAXO_SITE_UUID') || defined('ML_WP_TAXONOMY_SITE_UUID')) && !empty((defined('ZERISETAXO_SITE_UUID') ? constant('ZERISETAXO_SITE_UUID') : constant('ML_WP_TAXONOMY_SITE_UUID')))) {
            return (string) (defined('ZERISETAXO_SITE_UUID') ? constant('ZERISETAXO_SITE_UUID') : constant('ML_WP_TAXONOMY_SITE_UUID'));
        }

        $uuid = get_option('zerisetaxo_site_uuid', '');
        if (empty($uuid)) {
            $uuid = wp_generate_uuid4();
            if (empty($uuid)) {
                $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    wp_rand(0, 0xffff), wp_rand(0, 0xffff),
                    wp_rand(0, 0xffff),
                    wp_rand(0, 0x0fff) | 0x4000,
                    wp_rand(0, 0x3fff) | 0x8000,
                    wp_rand(0, 0xffff), wp_rand(0, 0xffff), wp_rand(0, 0xffff)
                );
            }
            update_option('zerisetaxo_site_uuid', $uuid, true);
        }

        return $uuid;
    }

    /**
     * Send post text and available categories to execute the Site-Adaptive Cascade.
     *
     * @param string $text Cleaned title + content of the post.
     * @param array $available_categories Current category names in WordPress.
     * @param float $threshold Confidence threshold (default 0.75).
     * @param string|null $title Optional distinct post title.
     * @param bool $enable_zero_shot Whether to evaluate Tier 2 Zero-Shot embeddings.
     * @param bool $enable_llm Whether to evaluate Tier 3 Gemini LLM.
     * @param bool $enable_local_model Whether to evaluate Tier 1 Local/Site ML.
     * @param string|null $site_id Unique site identifier.
     * @param array $category_details Structured taxonomy metadata (descriptions, parents).
     * @return array|\WP_Error Array with cascade details or WP_Error.
     */
    public function cascade_predict(
        string $text,
        array $available_categories,
        float $threshold = 0.75,
        ?string $title = null,
        bool $enable_zero_shot = true,
        bool $enable_llm = true,
        bool $enable_local_model = true,
        ?string $site_id = null,
        array $category_details = []
    ) {
        $endpoint = $this->get_base_url() . '/cascade/predict';
        $resolved_site_id = !empty($site_id) ? $site_id : $this->get_site_id();

        $payload = [
            'text'                 => $text,
            'site_id'              => $resolved_site_id,
            'available_categories' => array_values($available_categories),
            'threshold'            => $threshold,
            'enable_local_model'   => $enable_local_model,
            'enable_zero_shot'     => $enable_zero_shot,
            'enable_llm'           => $enable_llm,
        ];

        if (!empty($title)) {
            $payload['title'] = $title;
        }

        if (!empty($category_details)) {
            $payload['category_details'] = $category_details;
        }

        $body = wp_json_encode($payload);

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_CASCADE_PREDICT,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => $body,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'zerisetaxo_service_offline',
                sprintf(
                    /* translators: %s: Error message */
                    /* translators: 1: Service URL endpoint, 2: Error message detail. */
                    __('ML inference cascade service is unreachable at %1$s: %2$s', 'zerisetaxo'),
                    esc_html($endpoint),
                    $response->get_error_message()
                ),
                ['status' => 502]
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code !== 200 || !is_array($data)) {
            $detail = isset($data['detail']) ? $data['detail'] : __('Unexpected response from cascade service.', 'zerisetaxo');
            return new \WP_Error(
                'zerisetaxo_service_error',
                $detail,
                ['status' => ($status_code >= 400 && $status_code < 600) ? $status_code : 502]
            );
        }

        return [
            'tier_used'            => (int) ($data['tier_used'] ?? 1),
            'tier_name'            => sanitize_text_field($data['tier_name'] ?? 'Local Model'),
            'result_type'          => sanitize_text_field($data['result_type'] ?? 'existing'),
            'category'             => sanitize_text_field($data['category'] ?? ''),
            'suggested_category'   => sanitize_text_field($data['suggested_category'] ?? ''),
            'confidence'           => (float) ($data['confidence'] ?? 0.0),
            'reasoning'            => sanitize_text_field($data['reasoning'] ?? ''),
            'scores'               => is_array($data['scores'] ?? null) ? $data['scores'] : [],
            'tier_breakdown'       => is_array($data['tier_breakdown'] ?? null) ? $data['tier_breakdown'] : [],
            'state'                => sanitize_text_field($data['state'] ?? 'confident'),
            'runner_up_category'   => sanitize_text_field($data['runner_up_category'] ?? ''),
            'runner_up_confidence' => isset($data['runner_up_confidence']) ? (float) $data['runner_up_confidence'] : null,
            'margin'               => isset($data['margin']) ? (float) $data['margin'] : null,
            'is_ood'               => (bool) ($data['is_ood'] ?? false),
            'decision_source'      => sanitize_text_field($data['decision_source'] ?? 'hybrid'),
        ];
    }

    /**
     * Query site intelligence status and model metadata.
     *
     * @param string|null $site_id
     * @return array|\WP_Error
     */
    /**
     * Query site intelligence status and model metadata.
     *
     * @param string|null $site_id
     * @return array|\WP_Error
     */
    public function get_site_intelligence(?string $site_id = null) {
        if (!$this->is_configured()) {
            return new \WP_Error('zerisetaxo_service_not_configured', __('Inference service endpoint is not configured.', 'zerisetaxo'), ['status' => 503]);
        }

        $resolved_id = !empty($site_id) ? $site_id : $this->get_site_id();
        $endpoint = $this->get_base_url() . '/site/' . urlencode($resolved_id) . '/status';

        $response = wp_remote_get($endpoint, [
            'timeout' => self::TIMEOUT_SITE_INTELLIGENCE,
            'headers' => $this->get_request_headers(),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('site_status_error', 'Service returned code ' . $code);
        }

        $raw_body = wp_remote_retrieve_body($response);
        $decoded  = json_decode($raw_body, true);
        if (!is_array($decoded)) {
            return new \WP_Error('site_status_invalid_json', __('Service returned invalid JSON', 'zerisetaxo'));
        }

        return $decoded;
    }

    /**
     * Check health status of the inference service.
     *
     * @return array|\WP_Error
     */
    public function health_check() {
        if (!$this->is_configured()) {
            return new \WP_Error('zerisetaxo_service_not_configured', __('Inference service endpoint is not configured.', 'zerisetaxo'), ['status' => 503]);
        }

        $endpoint = $this->get_base_url() . '/health';
        $response = wp_remote_get($endpoint, [
            'timeout' => self::TIMEOUT_HEALTH_CHECK,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('http_' . $code, sprintf('Service returned code %d', $code), ['status' => $code]);
        }

        $raw_body = wp_remote_retrieve_body($response);
        $decoded  = json_decode($raw_body, true);
        if (!is_array($decoded)) {
            return new \WP_Error('invalid_json', __('Service returned invalid JSON', 'zerisetaxo'), ['status' => 200]);
        }

        return $decoded;
    }

    /**
     * Get the single source of truth inference service state and site intelligence.
     * Guaranteed to return a normalized array view-model that never throws PHP errors.
     * Clearly distinguishes:
     * - READY / CONNECTED ('connected')
     * - DISCONNECTED ('disconnected')
     * - NOT_CONFIGURED ('not_configured')
     * - ERROR ('error')
     *
     * @param string|null $site_id
     * @return array Normalized service state:
     *   - 'status'        => string ('connected' | 'disconnected' | 'not_configured' | 'error')
     *   - 'status_label'  => string ('Ready' | 'Inference service unavailable' | 'Setup required' | 'Service Error')
     *   - 'is_connected'  => bool
     *   - 'message'       => string
     *   - 'classes'       => array
     *   - 'class_count'   => int
     *   - 'endpoint'      => string
     *   - 'site_model'    => array (available, status, active_version, accuracy, macro_f1, reason)
     *   - 'error_detail'  => string
     *   - 'last_checked'  => int (UNIX timestamp)
     *   - 'last_success'  => int (UNIX timestamp)
     *   - 'last_error'    => string
     */
    public function get_inference_service_state(?string $site_id = null): array {
        $last_checked = (int) get_option(self::OPTION_LAST_HEALTH_CHECK, 0);
        $last_success = (int) get_option(self::OPTION_LAST_SUCCESSFUL_CONN, 0);
        $last_error   = (string) get_option(self::OPTION_LAST_ERROR_REASON, '');

        if (!$this->is_configured()) {
            return [
                'status'        => 'setup_required',
                'status_label'  => __('Setup required', 'zerisetaxo'),
                'is_connected'  => false,
                'message'       => __('The inference service has not been configured.', 'zerisetaxo'),
                'classes'       => [],
                'class_count'   => 0,
                'endpoint'      => '',
                'site_model'    => [
                    'available'      => false,
                    'status'         => __('Setup required', 'zerisetaxo'),
                    'active_version' => 'None',
                    'accuracy'       => null,
                    'macro_f1'       => null,
                    'reason'         => __('Inference service setup required', 'zerisetaxo'),
                ],
                'error_detail'  => __('No service endpoint configured.', 'zerisetaxo'),
                'last_checked'  => $last_checked,
                'last_success'  => $last_success,
                'last_error'    => $last_error ?: __('Inference service setup required', 'zerisetaxo'),
            ];
        }

        $now = time();
        update_option(self::OPTION_LAST_HEALTH_CHECK, $now);
        $last_checked = $now;

        $health = $this->health_check();

        if (is_wp_error($health)) {
            $err_code = strtolower((string) $health->get_error_code());
            $is_network_failure = in_array($err_code, ['http_request_failed', 'zerisetaxo_service_offline', 'offline', 'timeout'], true)
                || str_contains($err_code, 'curl')
                || str_contains($err_code, 'timeout')
                || str_contains($err_code, 'connect');

            $status       = $is_network_failure ? 'disconnected' : 'error';
            $status_label = $is_network_failure ? __('Inference service unavailable', 'zerisetaxo') : __('Service Error', 'zerisetaxo');
            $reason       = $is_network_failure ? __('Inference service disconnected', 'zerisetaxo') : __('Inference service error', 'zerisetaxo');
            $err_msg      = sanitize_text_field($health->get_error_message());
            $safe_error   = $is_network_failure
                ? ($err_msg ? /* translators: %s: Error message detail. */
                sprintf(__('Inference service is temporarily unavailable/disconnected: %s', 'zerisetaxo'), $err_msg) : __('Inference service is temporarily unavailable/disconnected.', 'zerisetaxo'))
                : ($err_msg ?: $reason);

            update_option(self::OPTION_LAST_ERROR_REASON, $safe_error);

            return [
                'status'        => $status,
                'status_label'  => $status_label,
                'is_connected'  => false,
                'message'       => $is_network_failure
                    ? __('Category suggestions are temporarily unavailable because the inference service is disconnected. Normal WordPress editing and publishing are unaffected.', 'zerisetaxo')
                    : __('The inference service encountered an error. Normal WordPress editing and publishing are unaffected.', 'zerisetaxo'),
                'classes'       => [],
                'class_count'   => 0,
                'endpoint'      => $this->get_base_url(),
                'site_model'    => [
                    'available'      => false,
                    'status'         => __('Unavailable', 'zerisetaxo'),
                    'active_version' => 'None',
                    'accuracy'       => null,
                    'macro_f1'       => null,
                    'reason'         => $reason,
                ],
                'error_detail'  => $err_msg,
                'last_checked'  => $last_checked,
                'last_success'  => $last_success,
                'last_error'    => $safe_error,
            ];
        }

        if (!is_array($health) || empty($health['status']) || 'healthy' !== $health['status']) {
            $err_msg = __('Inference service reported an unhealthy status.', 'zerisetaxo');
            update_option(self::OPTION_LAST_ERROR_REASON, $err_msg);

            return [
                'status'        => 'error',
                'status_label'  => __('Service Error', 'zerisetaxo'),
                'is_connected'  => false,
                'message'       => __('The inference service encountered an error. Normal WordPress editing and publishing are unaffected.', 'zerisetaxo'),
                'classes'       => [],
                'class_count'   => 0,
                'endpoint'      => $this->get_base_url(),
                'site_model'    => [
                    'available'      => false,
                    'status'         => __('Unavailable', 'zerisetaxo'),
                    'active_version' => 'None',
                    'accuracy'       => null,
                    'macro_f1'       => null,
                    'reason'         => __('Inference service reported unhealthy status', 'zerisetaxo'),
                ],
                'error_detail'  => is_array($health) ? wp_json_encode($health) : 'Unhealthy response',
                'last_checked'  => $last_checked,
                'last_success'  => $last_success,
                'last_error'    => $err_msg,
            ];
        }

        // Service is healthy & connected!
        update_option(self::OPTION_LAST_SUCCESSFUL_CONN, $now);
        update_option(self::OPTION_LAST_ERROR_REASON, '');
        $last_success = $now;

        $classes    = is_array($health['classes'] ?? null) ? $health['classes'] : [];
        $site_intel = $this->get_site_intelligence($site_id);

        if (is_wp_error($site_intel) || !is_array($site_intel)) {
            $site_model = [
                'available'      => false,
                'status'         => __('Unavailable', 'zerisetaxo'),
                'active_version' => 'None',
                'accuracy'       => null,
                'macro_f1'       => null,
                'reason'         => __('Site model metadata unavailable', 'zerisetaxo'),
            ];
        } else {
            $model_available = !empty($site_intel['site_model_available']);
            $site_model = [
                'available'      => $model_available,
                'status'         => $model_available
                    ? __('Trained & Active', 'zerisetaxo')
                    : sanitize_text_field($site_intel['status'] ?? __('Semantic / Hybrid', 'zerisetaxo')),
                'active_version' => sanitize_text_field($site_intel['active_version'] ?? 'None'),
                'accuracy'       => isset($site_intel['accuracy']) && is_numeric($site_intel['accuracy']) ? (float) $site_intel['accuracy'] : null,
                'macro_f1'       => isset($site_intel['macro_f1']) && is_numeric($site_intel['macro_f1']) ? (float) $site_intel['macro_f1'] : null,
                'reason'         => '',
            ];
        }

        return [
            'status'        => 'connected',
            'status_label'  => __('Ready', 'zerisetaxo'),
            'is_connected'  => true,
            'message'       => __('Category prediction is available.', 'zerisetaxo'),
            'classes'       => $classes,
            'class_count'   => count($classes),
            'endpoint'      => $this->get_base_url(),
            'site_model'    => $site_model,
            'error_detail'  => '',
            'last_checked'  => $last_checked,
            'last_success'  => $last_success,
            'last_error'    => '',
        ];
    }

    /**
     * Test connection to the inference microservice.
     * Safely handles online, offline, timeout, connection refused, HTTP 500, invalid JSON, empty, and unconfigured responses.
     *
     * @param string|null $custom_url Optional custom URL to test.
     * @return array Normalized test result:
     *   - 'connected'    => bool
     *   - 'is_connected' => bool
     *   - 'status'       => string ('connected' | 'disconnected' | 'setup_required' | 'error')
     *   - 'code'         => int|string
     *   - 'message'      => string
     *   - 'classes'      => array
     */
    public function test_connection(?string $custom_url = null): array {
        $now = time();
        update_option(self::OPTION_LAST_HEALTH_CHECK, $now);

        $url_to_test = ($custom_url !== null) ? trim((string) $custom_url) : $this->get_base_url();

        // 1. Not configured / empty
        if (empty($url_to_test)) {
            $msg = __('No service endpoint configured.', 'zerisetaxo');
            update_option(self::OPTION_LAST_ERROR_REASON, $msg);
            return [
                'connected'    => false,
                'is_connected' => false,
                'status'       => 'setup_required',
                'code'         => 'setup_required',
                'message'      => $msg,
                'classes'      => [],
            ];
        }

        // 2. Invalid endpoint URL format
        $sanitized_url = esc_url_raw($url_to_test);
        if (empty($sanitized_url) || !filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
            $msg = __('Invalid service endpoint URL format.', 'zerisetaxo');
            update_option(self::OPTION_LAST_ERROR_REASON, $msg);
            return [
                'connected'    => false,
                'is_connected' => false,
                'status'       => 'error',
                'code'         => 'invalid_endpoint',
                'message'      => $msg,
                'classes'      => [],
            ];
        }

        $base_url = rtrim($sanitized_url, '/');
        $endpoint = $base_url . '/health';

        $response = wp_remote_get($endpoint, [
            'timeout'     => self::TIMEOUT_TEST_CONNECTION,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            $msg = sprintf(
                /* translators: %s: Error message */
                __('Inference service is temporarily unavailable/disconnected: %s', 'zerisetaxo'),
                $response->get_error_message()
            );
            update_option(self::OPTION_LAST_ERROR_REASON, $msg);
            return [
                'connected'    => false,
                'is_connected' => false,
                'status'       => 'disconnected',
                'code'         => $response->get_error_code(),
                'message'      => $msg,
                'classes'      => [],
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $is_temporary_offline = in_array((int) $status_code, [502, 503, 504], true);
            $msg = $is_temporary_offline
                ? sprintf(
                    /* translators: %d: Status code */
                    __('Inference service is temporarily unavailable/disconnected (HTTP %d).', 'zerisetaxo'),
                    $status_code
                )
                : sprintf(
                    /* translators: %d: Status code */
                    __('Inference service returned HTTP error %d.', 'zerisetaxo'),
                    $status_code
                );
            update_option(self::OPTION_LAST_ERROR_REASON, $msg);
            return [
                'connected'    => false,
                'is_connected' => false,
                'status'       => $is_temporary_offline ? 'disconnected' : 'error',
                'code'         => $status_code,
                'message'      => $msg,
                'classes'      => [],
            ];
        }

        if (empty($raw_body)) {
            $msg = __('Inference service returned an empty response.', 'zerisetaxo');
            update_option(self::OPTION_LAST_ERROR_REASON, $msg);
            return [
                'connected'    => false,
                'is_connected' => false,
                'status'       => 'error',
                'code'         => 'empty_response',
                'message'      => $msg,
                'classes'      => [],
            ];
        }

        $data = json_decode($raw_body, true);
        if (!is_array($data) || empty($data['status'])) {
            $msg = __('Inference service returned invalid JSON response.', 'zerisetaxo');
            update_option(self::OPTION_LAST_ERROR_REASON, $msg);
            return [
                'connected'    => false,
                'is_connected' => false,
                'status'       => 'error',
                'code'         => 'invalid_json',
                'message'      => $msg,
                'classes'      => [],
            ];
        }

        if ('healthy' !== $data['status']) {
            $msg = __('Inference service reported an unhealthy status.', 'zerisetaxo');
            update_option(self::OPTION_LAST_ERROR_REASON, $msg);
            return [
                'connected'    => false,
                'is_connected' => false,
                'status'       => 'error',
                'code'         => 'unhealthy',
                'message'      => $msg,
                'classes'      => [],
            ];
        }

        // Connection successful!
        update_option(self::OPTION_LAST_SUCCESSFUL_CONN, $now);
        update_option(self::OPTION_LAST_ERROR_REASON, '');

        $classes = is_array($data['classes'] ?? null) ? $data['classes'] : [];

        return [
            'connected'    => true,
            'is_connected' => true,
            'status'       => 'connected',
            'code'         => 200,
            'message'      => sprintf(
                /* translators: %d: Number of classes */
                __('Inference service is connected and ready (%d categories loaded).', 'zerisetaxo'),
                count($classes)
            ),
            'classes'      => $classes,
        ];
    }

    /**
     * Check if the API token is provided via wp-config.php constant.
     *
     * @return bool
     */
    public function is_token_from_constant(): bool {
        return (defined('ZERISETAXO_API_TOKEN') || defined('ML_WP_TAXONOMY_API_TOKEN')) && !empty((defined('ZERISETAXO_API_TOKEN') ? constant('ZERISETAXO_API_TOKEN') : constant('ML_WP_TAXONOMY_API_TOKEN')));
    }

    /**
     * Check whether an API token is currently configured (via constant or database option).
     *
     * @return bool
     */
    public function has_api_token(): bool {
        return !empty($this->get_api_token());
    }

    /**
     * Retrieve the API Bearer token.
     * Checks constant ML_WP_TAXONOMY_API_TOKEN first, then database option.
     *
     * @return string
     */
    public function get_api_token(): string {
        if ((defined('ZERISETAXO_API_TOKEN') || defined('ML_WP_TAXONOMY_API_TOKEN')) && !empty((defined('ZERISETAXO_API_TOKEN') ? constant('ZERISETAXO_API_TOKEN') : constant('ML_WP_TAXONOMY_API_TOKEN')))) {
            return trim((string) (defined('ZERISETAXO_API_TOKEN') ? constant('ZERISETAXO_API_TOKEN') : constant('ML_WP_TAXONOMY_API_TOKEN')));
        }
        return trim((string) get_option(self::OPTION_API_TOKEN, ''));
    }

    /**
     * Get request headers with Authorization Bearer header if token configured.
     *
     * @return array
     */
    public function get_request_headers(): array {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
        $token = $this->get_api_token();
        if (!empty($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * Start a snapshot synchronization session on FastAPI.
     * POST /ingest/sync/start
     *
     * @param int|null $total_posts_expected Expected unique count of posts to be uploaded.
     * @return array|\WP_Error
     */
    public function sync_start(?int $total_posts_expected = null) {
        $endpoint = $this->get_base_url() . '/ingest/sync/start';
        $payload  = [];
        if ($total_posts_expected !== null) {
            $payload['total_posts_expected'] = (int) $total_posts_expected;
        }

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_SYNC_START,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        return $this->parse_json_response($response, $endpoint);
    }

    /**
     * Stage category taxonomy profile on FastAPI.
     * POST /ingest/taxonomy
     *
     * @param string $sync_id
     * @param array $categories
     * @return array|\WP_Error
     */
    public function sync_taxonomy(string $sync_id, array $categories) {
        $endpoint = $this->get_base_url() . '/ingest/taxonomy';
        $payload  = [
            'sync_id'    => $sync_id,
            'categories' => $categories,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_SYNC_TAXONOMY,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        return $this->parse_json_response($response, $endpoint);
    }

    /**
     * Stage a batch of posts on FastAPI.
     * POST /ingest/posts
     *
     * @param string $sync_id
     * @param array $posts
     * @param int $batch_index
     * @return array|\WP_Error
     */
    public function sync_posts(string $sync_id, array $posts, int $batch_index = 0) {
        $endpoint = $this->get_base_url() . '/ingest/posts';
        $payload  = [
            'sync_id'     => $sync_id,
            'batch_index' => $batch_index,
            'posts'       => $posts,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_SYNC_POSTS,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        return $this->parse_json_response($response, $endpoint);
    }

    /**
     * Atomically commit the staged snapshot on FastAPI.
     * POST /ingest/sync/commit
     *
     * @param string $sync_id
     * @return array|\WP_Error
     */
    public function sync_commit(string $sync_id) {
        $endpoint = $this->get_base_url() . '/ingest/sync/commit';
        $payload  = [
            'sync_id' => $sync_id,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_SYNC_COMMIT,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        return $this->parse_json_response($response, $endpoint);
    }

    /**
     * Rebuild the site-specific classifier model on FastAPI from the committed snapshot.
     * POST /ingest/rebuild
     *
     * @param string $type Rebuild type (defaults to 'site_model').
     * @return array|\WP_Error
     */
    public function rebuild_site_model(string $type = 'site_model') {
        $endpoint = $this->get_base_url() . '/ingest/rebuild';
        $payload  = [
            'type' => $type,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_REBUILD_MODEL,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        return $this->parse_json_response($response, $endpoint);
    }

    /**
     * Dispatch a single feedback event to FastAPI /feedback (Phase C).
     *
     * The caller is responsible for DB persistence (via FeedbackRepository) BEFORE
     * calling this method. This method only performs the network dispatch.
     *
     * Uses the Bearer token so FastAPI can resolve the authenticated tenant context.
     * Sends event_id as event_hash for FastAPI-side idempotency deduplication.
     *
     * @param string $event_id   Immutable random UUID for this feedback event.
     * @param int    $post_id    WordPress post ID.
     * @param string $category   Admin-approved / corrected category label.
     * @param string $source     Feedback source ('admin_apply', 'admin_create_and_apply', 'admin_approval', …).
     * @param array  $extra {
     *     @type string|null $predicted_category Original ML prediction.
     *     @type float|null  $confidence         Prediction confidence.
     *     @type string|null $decision_source    Decision source.
     *     @type bool        $accepted           True if prediction was accepted.
     *     @type string|null $corrected_category Corrected category (when accepted=false).
     * }
     * @return array|\WP_Error Parsed FastAPI response array on success, WP_Error on failure.
     */
    public function send_feedback(string $event_id, int $post_id, string $category, string $source, array $extra = []) {
        $endpoint = $this->get_base_url() . '/feedback';

        // Build full_text from WordPress post so we never store it in the DB.
        $post = function_exists('get_post') ? get_post($post_id) : null;
        if ($post) {
            $post_title   = (string) $post->post_title;
            $post_content = function_exists('wp_strip_all_tags')
                ? wp_strip_all_tags((string) $post->post_content)
                : wp_strip_all_tags((string) $post->post_content);
            $full_text    = trim($post_title . '. ' . $post_content);
        } else {
            $full_text = '';
        }

        $predicted_category = isset($extra['predicted_category']) ? (string) $extra['predicted_category'] : null;
        $accepted           = !empty($extra['accepted']);
        $corrected_category = isset($extra['corrected_category']) ? (string) $extra['corrected_category'] : $category;

        $payload = [
            'text'               => $full_text,
            'category'           => $category,
            'source'             => $source,
            'site_id'            => $this->get_site_id(),
            'post_id'            => $post_id,
            'predicted_category' => $predicted_category,
            'confidence'         => isset($extra['confidence']) && $extra['confidence'] !== '' ? (float) $extra['confidence'] : null,
            'decision_source'    => isset($extra['decision_source']) ? (string) $extra['decision_source'] : 'hybrid',
            'accepted'           => $accepted,
            'corrected_category' => $corrected_category,
            'event_id'           => $event_id,   // Canonical idempotency key (Phase C)
            'event_hash'         => $event_id,   // Legacy backwards-compatibility alias
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_FEEDBACK,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        return $this->parse_json_response($response, $endpoint);
    }

    /**
     * Dispatch cold-start taxonomy bootstrap request to FastAPI.
     *
     * @param array $data {
     *     @type string $site_description
     *     @type array  $topics
     *     @type string $niche
     *     @type array  $keywords
     *     @type array  $pages_context
     *     @type array  $existing_taxonomy
     * }
     * @return array|\WP_Error
     */
    public function bootstrap_taxonomy_suggest(array $data) {
        $endpoint = $this->get_base_url() . '/bootstrap/taxonomy/suggest';

        $payload = [
            'site_description'  => (string) ($data['site_description'] ?? ''),
            'topics'            => (array) ($data['topics'] ?? []),
            'niche'             => isset($data['niche']) ? (string) $data['niche'] : null,
            'keywords'          => (array) ($data['keywords'] ?? []),
            'pages_context'     => (array) ($data['pages_context'] ?? []),
            'existing_taxonomy' => (array) ($data['existing_taxonomy'] ?? []),
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'     => self::TIMEOUT_SITE_INTELLIGENCE,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => $this->get_request_headers(),
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        return $this->parse_json_response($response, $endpoint);
    }

    /**
     * Generic response parser for FastAPI JSON responses.
     *
     * @param array|\WP_Error $response
     * @param string $endpoint
     * @return array|\WP_Error
     */
    protected function parse_json_response($response, string $endpoint) {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) && isset($data['detail']) ? (is_string($data['detail']) ? $data['detail'] : wp_json_encode($data['detail'])) : "HTTP {$code}";
            return new \WP_Error('http_' . $code, $msg, ['status' => $code, 'raw' => $raw]);
        }

        if (!is_array($data)) {
            return new \WP_Error('invalid_json', 'Service returned invalid JSON', ['status' => 502, 'raw' => $raw]);
        }

        return $data;
    }
}
