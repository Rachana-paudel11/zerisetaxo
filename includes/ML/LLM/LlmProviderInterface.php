<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * Provider-Neutral LLM Interface for category classification.
 *
 * Receives plain sanitized input values only (no WordPress objects).
 * Implementations can wrap Google Gemini, OpenAI, Anthropic, Ollama, or test mocks.
 */
interface LlmProviderInterface {

    /**
     * Unique identifier for the provider (e.g. 'mock', 'gemini', 'openai').
     *
     * @return string
     */
    public function get_id(): string;

    /**
     * Specific model identifier (e.g. 'mock-v1', 'gemini-1.5-flash', 'gpt-4o-mini').
     * Used for cache invalidation when model version updates.
     *
     * @return string
     */
    public function get_model_id(): string;

    /**
     * Whether the provider has required credentials and configuration to execute.
     *
     * @return bool
     */
    public function is_configured(): bool;

    /**
     * Classify post content against active taxonomy.
     *
     * @param string $title          Sanitized post title (max 200 chars).
     * @param string $content_sample Sanitized content sample (max 600 chars).
     * @param array  $categories     Array of category descriptors: [['term_id' => int, 'name' => string], ...]
     * @param array  $options        Optional runtime overrides (temperature, timeout, etc.).
     * @return array Standardized response array matching Phase 12 contract.
     * @throws \Throwable On connection or transport errors.
     */
    public function classify(string $title, string $content_sample, array $categories, array $options = []): array;
}
