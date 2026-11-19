<?php
namespace ZeriseTaxo\ML;

use ZeriseTaxo\ML\Site\SiteModelService;
use ZeriseTaxo\ML\LLM\LlmFallbackService;
use ZeriseTaxo\ML\LLM\GeminiProvider;
use ZeriseTaxo\ML\LLM\LlmResponseValidator;

defined('ABSPATH') || exit;

/**
 * SiteAdaptivePredictionEngine (Phase 10 & Phase 13).
 *
 * Implements PredictionEngineInterface.
 * Manages the production prediction cascade:
 *
 *   New Post
 *       ↓
 *   Site model available & READY? (Feature flag enabled)
 *       ↓
 *   YES → Run SiteModelService::predict()
 *         Safety Gates check:
 *           - token coverage >= 0.30
 *           - confidence >= 0.45
 *           - Top1-Top2 margin >= 0.10
 *           - canonical term_id exists in WordPress
 *               ↓
 *             PASS → return site category (decision_source = 'php_site')
 *             FAIL → fall back to Global 42-class model
 *       ↓
 *   NO  → Global 42-class model immediately
 *       ↓
 *   Global model confident / good match?
 *   YES → return global category (decision_source = 'php_native')
 *   NO  → (Ambiguous, No existing category, or Semantic Novel OOD)
 *         Check Optional LLM Fallback:
 *           - Enabled & Privacy Consent?
 *           - Provider configured & Budget available?
 *           - Cache lookup
 *               ↓
 *             YES → return LLM category/suggestion (decision_source = 'llm_gemini')
 *             NO / Failure → preserve and return local prediction state
 */
class SiteAdaptivePredictionEngine implements PredictionEngineInterface {

    /**
     * Fallback global prediction engine (e.g. PhpPredictionEngine).
     *
     * @var PredictionEngineInterface|null
     */
    private ?PredictionEngineInterface $global_engine;

    /**
     * Site-specific model service.
     *
     * @var SiteModelService|null
     */
    private ?SiteModelService $site_service;

    /**
     * Optional LLM Fallback Service.
     *
     * @var LlmFallbackService|null
     */
    private ?LlmFallbackService $llm_service;

    public function __construct(
        ?PredictionEngineInterface $global_engine = null,
        ?SiteModelService $site_service = null,
        ?LlmFallbackService $llm_service = null
    ) {
        $this->global_engine = $global_engine;
        $this->site_service  = $site_service ?: new SiteModelService();
        $this->llm_service   = $llm_service ?: new LlmFallbackService(new GeminiProvider());
    }

    /**
     * Get underlying global engine.
     *
     * @return PredictionEngineInterface|null
     */
    public function get_global_engine(): ?PredictionEngineInterface {
        return $this->global_engine;
    }

    /**
     * Get underlying site model service.
     *
     * @return SiteModelService|null
     */
    public function get_site_service(): ?SiteModelService {
        return $this->site_service;
    }

    /**
     * Get LLM fallback service.
     *
     * @return LlmFallbackService|null
     */
    public function get_llm_service(): ?LlmFallbackService {
        return $this->llm_service;
    }

    /**
     * Set or override LLM fallback service.
     *
     * @param LlmFallbackService|null $llm_service
     */
    public function set_llm_service(?LlmFallbackService $llm_service): void {
        $this->llm_service = $llm_service;
    }

    /**
     * Execute prediction across the cascade:
     * Site Model -> Safety Gates -> Global Model -> Optional LLM Fallback.
     *
     * @param string $text
     * @param array  $context
     * @return array Standardized result matching PredictionEngineInterface specification.
     */
    public function predict_category(string $text, array $context = []): array {
        // 1. Check if site learning is active and enabled
        $site_learning_enabled = SiteModelService::is_enabled();

        if ($site_learning_enabled && $this->site_service !== null) {
            $site_res = $this->site_service->predict($text, $context);

            if (($site_res['status'] ?? '') === 'success') {
                $term_name   = sanitize_text_field($site_res['name']);
                $confidence  = (float) $site_res['confidence'];
                $margin      = (float) $site_res['margin'];
                $term_id     = (int) $site_res['term_id'];
                $runner_up   = sanitize_text_field($site_res['runner_up_name'] ?? '');
                $runner_conf = isset($site_res['runner_up_confidence']) ? (float) $site_res['runner_up_confidence'] : null;

                return [
                    'tier_used'            => 1,
                    'tier_name'            => 'Tier 1 (Site-Specific Naive Bayes)',
                    'result_type'          => 'existing',
                    'category'             => $term_name,
                    'term_id'              => $term_id,
                    'suggested_category'   => '',
                    'confidence'           => $confidence,
                    'reasoning'            => sprintf(
                        /* translators: 1: Category name, 2: Confidence score percentage, 3: Prediction margin percentage. */
            __('Site-specific model matched category "%1$s" (Confidence: %2$.1f%%, Margin: %3$.1f%%).', 'zerisetaxo'),
                        $term_name,
                        $confidence * 100,
                        $margin * 100
                    ),
                    'state'                => 'confident',
                    'runner_up_category'   => $runner_up,
                    'runner_up_confidence' => $runner_conf,
                    'margin'               => $margin,
                    'is_ood'               => false,
                    'decision_source'      => 'php_site',
                    'scores'               => $site_res['scores'] ?? [],
                    'tier_breakdown'       => [
                        'site_model' => [
                            'term_id'    => $term_id,
                            'name'       => $term_name,
                            'confidence' => $confidence,
                            'margin'     => $margin,
                            'coverage'   => $site_res['coverage'] ?? 0.0,
                        ],
                    ],
                ];
            }
        }

        // 2. Fallback to Global 42-class Model
        if ($this->global_engine === null) {
            return [
                'tier_used'            => 0,
                'tier_name'            => 'None',
                'result_type'          => 'none',
                'category'             => '',
                'suggested_category'   => '',
                'confidence'           => 0.0,
                'reasoning'            => __('No prediction engine is currently available.', 'zerisetaxo'),
                'state'                => 'insufficient_data',
                'runner_up_category'   => '',
                'runner_up_confidence' => null,
                'margin'               => 0.0,
                'is_ood'               => true,
                'decision_source'      => 'none',
                'scores'               => [],
                'tier_breakdown'       => [],
            ];
        }

        $global_res = $this->global_engine->predict_category($text, $context);

        // 2.5 Direct Taxonomy & Semantic Keyword Grounding Check
        $title = $context['title'] ?? '';
        if (empty($title)) {
            $lines = explode("\n", trim($text));
            $title = !empty($lines[0]) ? mb_substr(trim($lines[0]), 0, 100) : '';
        }
        $categories = $context['category_details'] ?? $context['available_categories'] ?? [];
        if (empty($categories) && function_exists('get_categories')) {
            $categories = get_categories(['hide_empty' => false]);
        }

        $tax_match = TaxonomyMatcher::match($title, $text, (array) $categories, $context['category_details'] ?? []);

        // If taxonomy matcher found a direct keyword or synonym match on the site's actual categories:
        if (!empty($tax_match['matched'])) {
            $tax_cat = $tax_match['category'];
            $is_global_resolved = (
                ($global_res['result_type'] ?? '') === 'existing' &&
                in_array($global_res['state'] ?? '', ['confident', 'good_match'], true) &&
                !empty($global_res['category'])
            );

            $tax_confidence    = (float) ($tax_match['confidence'] ?? 0.0);
            $global_confidence = (float) ($global_res['confidence'] ?? 0.0);

            // Taxonomy matcher takes precedence when:
            // 1. Global model failed to resolve an existing category (OOD or none)
            // 2. Both agree on the same category (boosting confidence)
            // 3. Taxonomy matcher has higher confidence or strong direct title match (>= 0.70)
            $use_tax_match = (
                !$is_global_resolved ||
                strtolower($global_res['category']) === strtolower($tax_cat) ||
                ($tax_confidence >= 0.70 && $tax_confidence >= $global_confidence)
            );

            if ($use_tax_match) {
                $confidence = $is_global_resolved 
                    ? max($global_confidence, $tax_confidence) 
                    : $tax_confidence;

                return [
                    'tier_used'            => 1,
                    'tier_name'            => 'PHP Native Taxonomy Matcher',
                    'result_type'          => 'existing',
                    'category'             => $tax_cat,
                    'term_id'              => $tax_match['term_id'] ?? 0,
                    'suggested_category'   => '',
                    'confidence'           => $confidence,
                    'reasoning'            => $tax_match['reasoning'],
                    'state'                => $confidence >= 0.75 ? 'confident' : 'good_match',
                    'runner_up_category'   => $tax_match['runner_up_category'] ?? ($global_res['runner_up_category'] ?? ''),
                    'runner_up_confidence' => $tax_match['runner_up_confidence'] ?? ($global_res['runner_up_confidence'] ?? null),
                    'margin'               => $tax_match['margin'] ?? 0.15,
                    'is_ood'               => false,
                    'decision_source'      => 'php_taxonomy_grounding',
                    'scores'               => $tax_match['scores'] ?? [],
                    'tier_breakdown'       => [
                        'taxonomy_matcher' => [
                            'category'   => $tax_cat,
                            'confidence' => $tax_match['confidence'],
                        ],
                    ],
                ];
            }
        }

        // If global model safely and confidently resolved the category, STOP here (Zero LLM call)
        $is_safe_existing = (
            ($global_res['result_type'] ?? '') === 'existing' &&
            in_array($global_res['state'] ?? '', ['confident', 'good_match'], true)
        );

        if ($is_safe_existing) {
            return $global_res;
        }

        // 3. Optional LLM Fallback (Ambiguous, No existing category, or Semantic Novel OOD)
        if ($this->llm_service !== null) {
            $title = $context['title'] ?? '';
            if (empty($title)) {
                $lines = explode("\n", trim($text));
                $title = !empty($lines[0]) ? mb_substr(trim($lines[0]), 0, 100) : 'Post';
            }

            $categories = $context['category_details'] ?? $context['available_categories'] ?? [];
            if (empty($categories) && function_exists('get_categories')) {
                $categories = get_categories(['hide_empty' => false]);
            }

            $llm_res = $this->llm_service->escalate($title, $text, (array)$categories, $global_res);

            if (($llm_res['status'] ?? '') === 'success' || ($llm_res['status'] ?? '') === 'cached') {
                $llm_data      = $llm_res['data'] ?? [];
                $decision_type = $llm_data['decision_type'] ?? '';

                if ($decision_type === LlmResponseValidator::DECISION_EXISTING && !empty($llm_data['term_id'])) {
                    return [
                        'tier_used'            => 2,
                        'tier_name'            => 'Tier 2 (AI Assistance: Google Gemini)',
                        'result_type'          => 'existing',
                        'category'             => sanitize_text_field($llm_data['category_name'] ?? ''),
                        'term_id'              => (int) $llm_data['term_id'],
                        'suggested_category'   => '',
                        'confidence'           => (float) ($llm_data['confidence'] ?? 0.85),
                        'reasoning'            => sanitize_text_field($llm_data['reasoning'] ?? ''),
                        'state'                => 'confident',
                        'runner_up_category'   => '',
                        'runner_up_confidence' => null,
                        'margin'               => null,
                        'is_ood'               => false,
                        'decision_source'      => 'llm_gemini',
                        'scores'               => [],
                        'tier_breakdown'       => [
                            'llm_fallback' => $llm_data,
                            'cached'       => ($llm_res['status'] === 'cached'),
                        ],
                    ];
                }

                if ($decision_type === LlmResponseValidator::DECISION_NEW && !empty($llm_data['suggested_category_name'])) {
                    $new_cat = sanitize_text_field($llm_data['suggested_category_name']);
                    return [
                        'tier_used'            => 2,
                        'tier_name'            => 'Tier 2 (AI Assistance: Google Gemini)',
                        'result_type'          => 'new_category_suggestion',
                        'category'             => $new_cat,
                        'term_id'              => 0,
                        'suggested_category'   => $new_cat,
                        'confidence'           => (float) ($llm_data['confidence'] ?? 0.80),
                        'reasoning'            => sanitize_text_field($llm_data['reasoning'] ?? ''),
                        'state'                => 'no_existing_category',
                        'runner_up_category'   => '',
                        'runner_up_confidence' => null,
                        'margin'               => null,
                        'is_ood'               => false,
                        'decision_source'      => 'llm_gemini',
                        'scores'               => [],
                        'tier_breakdown'       => [
                            'llm_fallback' => $llm_data,
                            'cached'       => ($llm_res['status'] === 'cached'),
                        ],
                    ];
                }

                // If LLM returned 'uncertain', preserve the local result
                return $global_res;
            }
        }

        // When LLM is disabled, unconfigured, or fails, PRESERVE the original local result
        return $global_res;
    }
}
