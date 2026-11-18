<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * MockLlmProvider
 *
 * Deterministic in-memory test provider for Phase 12 validation.
 * Strictly zero network connections, zero external SDKs, zero API keys.
 */
class MockLlmProvider implements LlmProviderInterface {

    const MODE_EXISTING   = 'existing_category';
    const MODE_NEW        = 'new_category_suggestion';
    const MODE_UNCERTAIN  = 'uncertain';
    const MODE_INVALID_TERM = 'invalid_term';
    const MODE_MALFORMED  = 'malformed';
    const MODE_EXCEPTION  = 'exception';
    const MODE_TIMEOUT    = 'timeout_simulation';

    public int $call_count = 0;
    public ?string $last_title = null;
    public ?string $last_content_sample = null;
    public ?array $last_categories = null;
    public ?array $last_options = null;

    protected string $mode;
    protected string $provider_id;
    protected string $model_id;
    protected bool $configured;
    protected ?array $custom_response = null;

    /**
     * @param string $mode        One of the MODE_* constants.
     * @param string $provider_id Provider identifier (default 'mock').
     * @param string $model_id    Model identifier (default 'mock-v1').
     * @param bool   $configured  Whether provider reports configured status.
     */
    public function __construct(
        string $mode = self::MODE_EXISTING,
        string $provider_id = 'mock',
        string $model_id = 'mock-v1',
        bool $configured = true
    ) {
        $this->mode        = $mode;
        $this->provider_id = $provider_id;
        $this->model_id    = $model_id;
        $this->configured  = $configured;
    }

    public function get_id(): string {
        return $this->provider_id;
    }

    public function get_model_id(): string {
        return $this->model_id;
    }

    public function is_configured(): bool {
        return $this->configured;
    }

    public function set_configured(bool $configured): void {
        $this->configured = $configured;
    }

    public function set_mode(string $mode): void {
        $this->mode = $mode;
    }

    public function set_model_id(string $model_id): void {
        $this->model_id = $model_id;
    }

    public function set_provider_id(string $provider_id): void {
        $this->provider_id = $provider_id;
    }

    public function set_custom_response(?array $response): void {
        $this->custom_response = $response;
    }

    public function classify(string $title, string $content_sample, array $categories, array $options = []): array {
        $this->call_count++;
        $this->last_title          = $title;
        $this->last_content_sample = $content_sample;
        $this->last_categories     = $categories;
        $this->last_options        = $options;

        if ($this->custom_response !== null) {
            return $this->custom_response;
        }

        switch ($this->mode) {
            case self::MODE_EXCEPTION:
                throw new \RuntimeException('Mock provider simulated network exception.');

            case self::MODE_TIMEOUT:
                throw new \RuntimeException('Mock provider simulated HTTP request timeout after 5000ms.');

            case self::MODE_MALFORMED:
                // Return invalid structure (missing decision_type)
                return [
                    'random_unsupported_key' => 'garbage',
                    'text'                   => 'Some unformatted conversational text',
                ];

            case self::MODE_INVALID_TERM:
                // Return existing_category with term_id not present in taxonomy payload
                return [
                    'decision_type' => 'existing_category',
                    'term_id'       => 999999,
                    'confidence'    => 0.95,
                    'reasoning'     => 'Mock hallucinated an invalid term ID.',
                ];

            case self::MODE_NEW:
                return [
                    'decision_type'           => 'new_category_suggestion',
                    'suggested_category_name' => $options['suggested_name'] ?? 'Headless Architecture',
                    'confidence'              => 0.85,
                    'reasoning'               => 'Mock identified novel topic not covered by existing taxonomy.',
                ];

            case self::MODE_UNCERTAIN:
                return [
                    'decision_type' => 'uncertain',
                    'confidence'    => 0.20,
                    'reasoning'     => 'Mock could not identify a clear category.',
                ];

            case self::MODE_EXISTING:
            default:
                $first_cat = $categories[0] ?? ['term_id' => 10, 'name' => 'Default Category'];
                return [
                    'decision_type' => 'existing_category',
                    'term_id'       => (int) ($first_cat['term_id'] ?? 10),
                    'confidence'    => 0.91,
                    'reasoning'     => sprintf('Mock classified content as %s.', $first_cat['name'] ?? 'Category'),
                ];
        }
    }
}
