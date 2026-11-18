<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * Common interface for WordPress prediction engines.
 * Decouples WordPress controllers from underlying ML runtime implementations
 * (Native PHP vs Legacy FastAPI).
 */
interface PredictionEngineInterface {

    /**
     * Executes category prediction for a given text and context.
     *
     * @param string $text    Post text (title + content).
     * @param array  $context Contextual parameters:
     *   - available_categories: array<string> List of category names existing in WordPress.
     *   - category_details: array<array> List of taxonomy metadata (term_id, name, slug, parent).
     *   - post_id: int Current WordPress post ID.
     *   - title: string Post title.
     *   - confidence_threshold: float Minimum confidence for confident state (default 0.75).
     *   - enable_zero_shot: bool Whether semantic discovery is enabled.
     *   - enable_llm: bool Whether LLM discovery is enabled.
     *   - enable_local_model: bool Whether core model is enabled.
     * @return array Standardized prediction result array:
     *   - tier_used: int (1, 2, or 3)
     *   - tier_name: string
     *   - result_type: string ('existing' | 'new_category_suggestion' | 'none')
     *   - category: string Canonical category name if matched, else empty string
     *   - suggested_category: string Proposed new category name if applicable
     *   - confidence: float Confidence score between 0.0 and 1.0
     *   - reasoning: string Human-readable decision explanation
     *   - state: string ('confident' | 'ambiguous' | 'out_of_distribution' | 'no_existing_category' | 'insufficient_data')
     *   - runner_up_category: string
     *   - runner_up_confidence: float|null
     *   - margin: float|null
     *   - is_ood: bool
     *   - decision_source: string ('php_native' | 'fastapi')
     *   - scores: array<string, float>
     *   - tier_breakdown: array
     */
    public function predict_category(string $text, array $context = []): array;
}
