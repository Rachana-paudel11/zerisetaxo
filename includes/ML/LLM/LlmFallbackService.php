<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * LlmFallbackService
 *
 * Orchestrates the optional LLM escalation layer:
 *  - Enforces feature flags and explicit privacy consent
 *  - Validates escalation eligibility (filters out resolved local matches and gibberish)
 *  - Enforces daily provider call limits
 *  - Checks transient cache before calling provider
 *  - Sanitizes inputs and taxonomy payloads
 *  - Validates provider responses strictly
 *
 * Isolated component: NOT integrated into the production prediction cascade during Phase 12.
 */
class LlmFallbackService {

    const OPTION_ENABLED              = 'zerisetaxo_enable_llm';
    const OPTION_PRIVACY_CONSENT      = 'zerisetaxo_llm_privacy_consent';
    const OPTION_DAILY_LIMIT          = 'zerisetaxo_llm_daily_limit';
    const DEFAULT_DAILY_LIMIT         = 100;
    const PER_USER_DAILY_LIMIT        = 30;
    const TRANSIENT_CALLS_PREFIX      = 'zerisetaxo_llm_calls_';
    const TRANSIENT_USER_CALLS_PREFIX = 'zerisetaxo_llm_user_';
    const SCHEMA_VERSION              = 'v1';

    /**
     * Active LLM provider implementation.
     *
     * @var LlmProviderInterface|null
     */
    protected ?LlmProviderInterface $provider;

    public function __construct(?LlmProviderInterface $provider = null) {
        $this->provider = $provider;
    }

    /**
     * Get active provider.
     *
     * @return LlmProviderInterface|null
     */
    public function get_provider(): ?LlmProviderInterface {
        return $this->provider;
    }

    /**
     * Set or override provider.
     *
     * @param LlmProviderInterface|null $provider
     */
    public function set_provider(?LlmProviderInterface $provider): void {
        $this->provider = $provider;
    }

    /**
     * Check if LLM fallback feature is enabled by admin.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    /**
     * Check if explicit privacy consent has been granted.
     *
     * @return bool
     */
    public static function has_privacy_consent(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        return (bool) get_option(self::OPTION_PRIVACY_CONSENT, false);
    }

    /**
     * Determine whether an unresolved local prediction state is eligible for LLM escalation.
     *
     * Eligible states:
     *  - 'ambiguous' (local model unsure between two categories)
     *  - 'no_existing_category' (global model recognized topic, but category absent from site)
     *  - Selected semantic 'out_of_distribution' (genuine novel articles with real prose)
     *
     * Ineligible:
     *  - 'confident' or 'good_match' (already resolved locally)
     *  - 'insufficient_data' or empty text
     *  - Gibberish / pure symbols / spam OOD (0 active features with no natural word structure)
     *
     * @param array  $local_result Result from local prediction engine.
     * @param string $text         Raw input text.
     * @return array{eligible: bool, reason: string}
     */
    public static function is_eligible(array $local_result, string $text): array {
        $clean = trim($text);
        if ($clean === '' || mb_strlen($clean) < 3) {
            return [
                'eligible' => false,
                'reason'   => 'insufficient_content',
            ];
        }

        $state           = $local_result['state'] ?? '';
        $decision_source = $local_result['decision_source'] ?? '';
        $result_type     = $local_result['result_type'] ?? '';

        // 1. Resolved local predictions are NOT eligible
        if ($decision_source === 'php_site' && $result_type === 'existing') {
            return [
                'eligible' => false,
                'reason'   => 'site_model_already_resolved',
            ];
        }
        if (in_array($state, ['confident', 'good_match'], true) && $result_type === 'existing') {
            return [
                'eligible' => false,
                'reason'   => 'global_model_already_resolved',
            ];
        }

        // 2. Insufficient data is NOT eligible
        if ($state === 'insufficient_data') {
            return [
                'eligible' => false,
                'reason'   => 'insufficient_data',
            ];
        }

        // 3. Ambiguous and No Existing Category are immediately eligible
        if ($state === 'ambiguous') {
            return [
                'eligible' => true,
                'reason'   => 'ambiguous_prediction',
            ];
        }

        if ($state === 'no_existing_category') {
            return [
                'eligible' => true,
                'reason'   => 'category_missing_from_site',
            ];
        }

        // 4. Out-of-Distribution distinction: Semantic Novel vs. Gibberish
        if ($state === 'out_of_distribution' || !empty($local_result['is_ood'])) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
            $meaningful_words = 0;
            foreach ($words as $w) {
                if (mb_strlen($w) >= 3 && !ctype_digit($w)) {
                    $meaningful_words++;
                }
            }

            // If text contains fewer than 4 readable words, it's gibberish/symbols
            if ($meaningful_words < 4) {
                return [
                    'eligible' => false,
                    'reason'   => 'gibberish_or_unusable_content',
                ];
            }

            // Has genuine words, but ungrounded in vocabulary -> Semantic novel content
            return [
                'eligible' => true,
                'reason'   => 'semantic_novel_content',
            ];
        }

        return [
            'eligible' => false,
            'reason'   => 'unmatched_criteria',
        ];
    }

    /**
     * Check if the daily call budget limit has been reached for the site.
     *
     * @return bool True if within budget, false if limit exceeded.
     */
    public function check_daily_budget(): bool {
        if (!function_exists('get_option')) {
            return true;
        }

        $daily_limit = (int) get_option(self::OPTION_DAILY_LIMIT, self::DEFAULT_DAILY_LIMIT);
        if ($daily_limit <= 0) {
            $daily_limit = self::DEFAULT_DAILY_LIMIT;
        }

        $key   = self::TRANSIENT_CALLS_PREFIX . gmdate('Ymd');
        $calls = function_exists('get_transient') ? (int) get_transient($key) : 0;

        return $calls < $daily_limit;
    }

    /**
     * Check if the current user has exceeded their daily per-user call limit.
     *
     * Users with manage_options skip this limit.
     *
     * @param int|null $user_id Optional user ID, defaults to current logged-in user.
     * @return bool True if within limit, false if limit exceeded.
     */
    public function check_user_daily_budget(?int $user_id = null): bool {
        if (!function_exists('get_current_user_id')) {
            return true;
        }

        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            return true;
        }

        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }

        $key   = self::TRANSIENT_USER_CALLS_PREFIX . $user_id . '_' . gmdate('Ymd');
        $calls = function_exists('get_transient') ? (int) get_transient($key) : 0;

        return $calls < self::PER_USER_DAILY_LIMIT;
    }

    /**
     * Increment the daily call counter for the site.
     */
    public function increment_daily_budget(): void {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }

        $key   = self::TRANSIENT_CALLS_PREFIX . gmdate('Ymd');
        $calls = (int) get_transient($key);
        set_transient($key, $calls + 1, defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
    }

    /**
     * Increment the per-user daily call counter.
     *
     * @param int|null $user_id Optional user ID, defaults to current logged-in user.
     */
    public function increment_user_daily_budget(?int $user_id = null): void {
        if (!function_exists('get_current_user_id') || !function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }

        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            return;
        }

        $key   = self::TRANSIENT_USER_CALLS_PREFIX . $user_id . '_' . gmdate('Ymd');
        $calls = (int) get_transient($key);
        set_transient($key, $calls + 1, defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
    }

    /**
     * Escalate unresolved prediction to LLM provider.
     *
     * @param string $title        Post title.
     * @param string $content      Post content.
     * @param array  $categories   Active WordPress categories.
     * @param array  $local_result Result from local prediction engine.
     * @param array  $options      Optional parameters.
     * @return array Standardized result with status code.
     */
    public function escalate(
        string $title,
        string $content,
        array $categories,
        array $local_result = [],
        array $options = []
    ): array {
        // 1. Feature flag guard
        if (!self::is_enabled()) {
            return [
                'status'  => 'disabled',
                'message' => 'LLM fallback is disabled in settings.',
            ];
        }

        // 2. Privacy consent guard
        if (!self::has_privacy_consent()) {
            return [
                'status'  => 'no_privacy_consent',
                'message' => 'LLM fallback requires explicit privacy consent before sending content externally.',
            ];
        }

        // 3. Provider configuration guard
        if (!$this->provider || !$this->provider->is_configured()) {
            return [
                'status'  => 'not_configured',
                'message' => 'No active LLM provider configured.',
            ];
        }

        // 4. Escalation eligibility guard
        $eligibility = self::is_eligible($local_result, $title . ' ' . $content);
        if (!$eligibility['eligible']) {
            return [
                'status' => 'not_eligible',
                'reason' => $eligibility['reason'],
            ];
        }

        // 5. Daily call budget guard
        if (!$this->check_daily_budget() || !$this->check_user_daily_budget()) {
            return [
                'status'  => 'budget_exceeded',
                'message' => 'Daily LLM call budget limit reached. Using local prediction.',
            ];
        }

        // 6. Sanitize inputs and prepare minimal payload
        $payload = LlmInputSanitizer::prepare_payload($title, $content, $categories);

        // 7. Cache lookup
        $cache_key = LlmCache::build_cache_key(
            $payload['title'],
            $payload['content_sample'],
            $categories,
            $this->provider->get_id(),
            $this->provider->get_model_id(),
            self::SCHEMA_VERSION
        );

        $cached = LlmCache::get($cache_key);
        if ($cached !== null) {
            return [
                'status'          => 'cached',
                'data'            => $cached['data'],
                'cached_at'       => $cached['cached_at'] ?? time(),
                'decision_source' => 'llm_fallback',
            ];
        }

        // 8. Invoke provider safely
        try {
            $raw_response = $this->provider->classify(
                $payload['title'],
                $payload['content_sample'],
                $payload['categories'],
                $options
            );
        } catch (\Throwable $e) {
            return [
                'status'  => 'provider_error',
                'message' => $e->getMessage(),
            ];
        }

        // Increment budget after successful network/provider execution
        $this->increment_daily_budget();
        $this->increment_user_daily_budget();

        // 9. Strict local validation
        $validation = LlmResponseValidator::validate($raw_response, $payload['categories']);
        if (!$validation['valid']) {
            return [
                'status'  => 'invalid_response',
                'error'   => $validation['error'],
                'raw'     => $raw_response,
            ];
        }

        $validated_data = $validation['data'];

        // 10. Cache valid response
        LlmCache::set($cache_key, $validated_data);

        return [
            'status'          => 'success',
            'data'            => $validated_data,
            'decision_source' => 'llm_fallback',
        ];
    }
}
