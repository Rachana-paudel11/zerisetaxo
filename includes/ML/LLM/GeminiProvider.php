<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * GeminiProvider
 *
 * Official Google Gemini API integration using WordPress native HTTP functions.
 * Implements LlmProviderInterface for vendor-neutral execution.
 *
 * Endpoint:
 *  https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *
 * Hardening:
 *  - HTTPS only, fixed endpoint (zero arbitrary host SSRF risk).
 *  - Native wp_remote_post() with conservative timeout.
 *  - Structured JSON output via generationConfig and responseSchema.
 *  - HTTP 429 automatic 5-minute cooldown transient (zerisetaxo_llm_cooldown_gemini).
 *  - Secure API key resolution via LlmCredentialStore.
 */
class GeminiProvider implements LlmProviderInterface {

    const PROVIDER_ID       = 'gemini';
    const DEFAULT_MODEL     = 'gemini-3-flash-preview';
    // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Direct integration with Gemini API fallback for environments where wp_ai_client_prompt() is not present.
    const BASE_ENDPOINT     = 'https://generativelanguage.googleapis.com/v1beta/models/';
    const COOLDOWN_KEY      = 'zerisetaxo_llm_cooldown_gemini';
    const COOLDOWN_DURATION = 300; // 5 minutes in seconds
    const DEFAULT_TIMEOUT   = 12;  // 12.0 seconds maximum to guarantee reliable AI responses

    protected string $model_id;
    protected int $timeout;

    public function __construct(string $model_id = self::DEFAULT_MODEL, ?int $timeout = null) {
        if (empty($model_id) || 'gemini-3.8-flash' === $model_id || 'gemini-1.5-flash' === $model_id) {
            $this->model_id = self::DEFAULT_MODEL;
        } else {
            $this->model_id = $model_id;
        }
        $default_t      = function_exists('apply_filters') ? (int) apply_filters('zerisetaxo_llm_timeout', self::DEFAULT_TIMEOUT) : self::DEFAULT_TIMEOUT;
        $this->timeout  = ($timeout !== null && $timeout > 0) ? $timeout : $default_t;
    }

    public function get_id(): string {
        return self::PROVIDER_ID;
    }

    public function get_model_id(): string {
        return $this->model_id;
    }

    public function set_model_id(string $model_id): void {
        $this->model_id = $model_id;
    }

    /**
     * Check if Gemini API key is configured.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return LlmCredentialStore::has_api_key(self::PROVIDER_ID);
    }

    /**
     * Check if provider is currently in cooldown following a 429 rate limit.
     *
     * @return bool
     */
    public function is_in_cooldown(): bool {
        if (!function_exists('get_transient')) {
            return false;
        }
        return (bool) get_transient(self::COOLDOWN_KEY);
    }

    /**
     * Activate rate limit cooldown transient.
     *
     * @param int $duration Seconds.
     */
    public function activate_cooldown(int $duration = self::COOLDOWN_DURATION): void {
        if (function_exists('set_transient')) {
            set_transient(self::COOLDOWN_KEY, 1, $duration);
        }
    }

    /**
     * Clear cooldown transient.
     */
    public function clear_cooldown(): void {
        if (function_exists('delete_transient')) {
            delete_transient(self::COOLDOWN_KEY);
        }
    }

    /**
     * Classify post content using Gemini structured outputs.
     *
     * @param string $title
     * @param string $content_sample
     * @param array  $categories
     * @param array  $options
     * @return array Raw parsed response array.
     * @throws \RuntimeException On transport or provider errors.
     */
    public function classify(string $title, string $content_sample, array $categories, array $options = []): array {
        if ($this->is_in_cooldown()) {
            throw new \RuntimeException(esc_html__('Gemini API is temporarily in cooldown following a rate limit (HTTP 429). Please try again in a few minutes.', 'zerisetaxo'));
        }

        $api_key = LlmCredentialStore::get_api_key(self::PROVIDER_ID);
        if (empty($api_key)) {
            throw new \RuntimeException(esc_html__('Gemini API key is not configured.', 'zerisetaxo'));
        }

        $system_instruction = GeminiPromptBuilder::build_system_instruction();
        $user_prompt        = GeminiPromptBuilder::build_user_prompt($title, $content_sample, $categories);
        $response_schema    = GeminiPromptBuilder::build_response_schema();

        $url = self::BASE_ENDPOINT . $this->model_id . ':generateContent';

        $body_payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $system_instruction],
                ],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $user_prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'      => 0.1,
                'maxOutputTokens'  => 2048,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $response_schema,
            ],
        ];

        $request_args = [
            'method'      => 'POST',
            'timeout'     => $this->timeout,
            'redirection' => 0,
            'httpversion' => '1.1',
            'sslverify'   => true,
            'headers'     => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $api_key,
            ],
            'body'        => json_encode($body_payload),
        ];

        // Execute request via WordPress HTTP API or transport hook
        $response = $this->execute_http_request($url, $request_args);

        $is_err = (function_exists('is_wp_error') && is_wp_error($response)) || !empty($response['error']);
        if ($is_err) {
            $msg = (function_exists('is_wp_error') && is_wp_error($response)) ? $response->get_error_message() : (string) $response['error'];
            if (str_contains(strtolower($msg), 'timed out') || str_contains(strtolower($msg), 'timeout')) {
                throw new \RuntimeException(esc_html__('Gemini API request timed out.', 'zerisetaxo'));
            }
            throw new \RuntimeException(sprintf(/* translators: %s: Error detail message. */
            esc_html__('Gemini transport error: %s', 'zerisetaxo'), esc_html($msg)));
        }

        $status_code = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : (int) ($response['response']['code'] ?? 0);

        $body = function_exists('wp_remote_retrieve_body')
            ? wp_remote_retrieve_body($response)
            : (string) ($response['body'] ?? '');

        // Handle HTTP error codes cleanly
        if ($status_code === 401 || $status_code === 403) {
            throw new \RuntimeException(esc_html__('Gemini API key is invalid or unauthorized (HTTP 401/403).', 'zerisetaxo'));
        }

        if ($status_code === 429) {
            $this->activate_cooldown();
            throw new \RuntimeException(esc_html__('Gemini rate limit exceeded (HTTP 429). Cooldown activated.', 'zerisetaxo'));
        }

        if ($status_code >= 500) {
            throw new \RuntimeException(sprintf(/* translators: %d: HTTP status code. */
            esc_html__('Gemini service temporarily unavailable (HTTP %d).', 'zerisetaxo'), (int) $status_code));
        }

        if ($status_code !== 200) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message
            throw new \RuntimeException(sprintf(/* translators: 1: HTTP status code, 2: Error detail message. */
            esc_html__('Gemini request failed with HTTP status %1$d: %2$s', 'zerisetaxo'), (int) $status_code, esc_html(substr($body, 0, 400))));
        }

        // Parse JSON output from Gemini structure
        $decoded = json_decode($body, true);
        if (!$decoded || !is_array($decoded)) {
            throw new \RuntimeException(esc_html__('Gemini returned non-JSON response payload.', 'zerisetaxo'));
        }

        // Robust part extraction across all content parts (supports thinking tokens and split text)
        $raw_text = '';
        if (!empty($decoded['candidates'][0]['content']['parts'])) {
            foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
                if (!empty($part['text'])) {
                    $raw_text .= $part['text'];
                }
            }
        }

        if (empty($raw_text)) {
            throw new \RuntimeException(esc_html__('Gemini response contained empty candidates.', 'zerisetaxo'));
        }

        // Clean markdown backticks and extract inner JSON object if wrapped in fences
        $raw_text = trim($raw_text);
        if (preg_match('/\{[\s\S]*\}/', $raw_text, $matches)) {
            $raw_text = $matches[0];
        }

        $parsed_result = json_decode($raw_text, true);
        if (!$parsed_result || !is_array($parsed_result)) {
            throw new \RuntimeException(esc_html__('Gemini output text was not valid JSON.', 'zerisetaxo'));
        }

        // Capture usage metadata if available
        if (!empty($decoded['usageMetadata'])) {
            $parsed_result['usage_metadata'] = $decoded['usageMetadata'];
        }

        return $parsed_result;
    }

    /**
     * Executes minimal connection test without user content.
     *
     * @return array{success: bool, status: string, message: string}
     */
    public function test_connection(): array {
        $api_key = LlmCredentialStore::get_api_key(self::PROVIDER_ID);
        if (empty($api_key)) {
            return [
                'success' => false,
                'status'  => 'not_configured',
                'message' => __('No API key configured.', 'zerisetaxo'),
            ];
        }

        $url = self::BASE_ENDPOINT . $this->model_id . ':generateContent';

        $body_payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => 'Respond with single word: OK'],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 10,
                'temperature'     => 0.0,
            ],
        ];

        $request_args = [
            'method'      => 'POST',
            'timeout'     => 10,
            'redirection' => 0,
            'httpversion' => '1.1',
            'sslverify'   => true,
            'headers'     => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $api_key,
            ],
            'body'        => json_encode($body_payload),
        ];

        $t0 = microtime(true);
        $response = $this->execute_http_request($url, $request_args);
        $latency_ms = round((microtime(true) - $t0) * 1000, 1);

        $is_err = (function_exists('is_wp_error') && is_wp_error($response)) || !empty($response['error']);
        if ($is_err) {
            $msg = (function_exists('is_wp_error') && is_wp_error($response)) ? $response->get_error_message() : (string) $response['error'];
            if (str_contains(strtolower($msg), 'timed out') || str_contains(strtolower($msg), 'timeout')) {
                return [
                    'success' => false,
                    'status'  => 'Timeout',
                    'message' => __('Connection timed out while reaching Google Gemini.', 'zerisetaxo'),
                ];
            }
            return [
                'success' => false,
                'status'  => 'Provider unavailable',
                'message' => __('Could not reach Gemini service. Check server network connection.', 'zerisetaxo'),
            ];
        }

        $code = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : (int) ($response['response']['code'] ?? 0);

        if ($code === 200) {
            return [
                'success'    => true,
                'status'     => 'Connected',
                'message'    => sprintf(/* translators: 1: Model name, 2: Response time in milliseconds. */
            __('Successfully connected to Google Gemini (%1$s) in %2$sms.', 'zerisetaxo'), $this->model_id, $latency_ms),
                'latency_ms' => $latency_ms,
            ];
        }

        if ($code === 401 || $code === 403) {
            return [
                'success' => false,
                'status'  => 'Invalid API key',
                'message' => __('The provided API key was rejected by Google Gemini.', 'zerisetaxo'),
            ];
        }

        if ($code === 429) {
            return [
                'success' => false,
                'status'  => 'Rate limited',
                'message' => __('Gemini rate limit reached (HTTP 429).', 'zerisetaxo'),
            ];
        }

        return [
            'success' => false,
            'status'  => 'Provider unavailable',
            'message' => sprintf(/* translators: %d: HTTP status code. */
            __('Gemini returned HTTP error status %d.', 'zerisetaxo'), $code),
        ];
    }

    /**
     * Internal HTTP executor. Uses wp_remote_post or cURL fallback.
     *
     * @param string $url
     * @param array  $args
     * @return array|\WP_Error
     */
    protected function execute_http_request(string $url, array $args) {
        if (!function_exists('wp_remote_post')) {
            require_once ABSPATH . WPINC . '/http.php';
        }
        return wp_remote_post($url, $args);
    }
}
