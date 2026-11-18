<?php
namespace ZeriseTaxo\ML;

use ZeriseTaxo\Services\InferenceClient;

defined('ABSPATH') || exit;

/**
 * Adapter wrapping the legacy FastAPI / Python InferenceClient
 * behind the common PredictionEngineInterface.
 * Preserved for reference comparisons, developer diagnostics, and regressions.
 */
class FastApiPredictionEngine implements PredictionEngineInterface {

    /**
     * Legacy inference client.
     */
    protected InferenceClient $client;

    /**
     * @param InferenceClient $client
     */
    public function __construct(InferenceClient $client) {
        $this->client = $client;
    }

    /**
     * Executes prediction through the legacy FastAPI service.
     *
     * @param string $text
     * @param array  $context
     * @return array
     */
    public function predict_category(string $text, array $context = []): array {
        $available_categories = $context['available_categories'] ?? [];
        $category_details     = $context['category_details'] ?? [];
        $threshold            = $context['confidence_threshold'] ?? 0.75;
        $title                = $context['title'] ?? '';
        $enable_zero_shot     = $context['enable_zero_shot'] ?? true;
        $enable_llm           = $context['enable_llm'] ?? true;
        $enable_local_model   = $context['enable_local_model'] ?? true;

        $result = $this->client->cascade_predict(
            $text,
            $available_categories,
            $threshold,
            $title,
            $enable_zero_shot,
            $enable_llm,
            $enable_local_model,
            null,
            $category_details
        );

        if (is_wp_error($result)) {
            return [
                'error'           => true,
                'wp_error'        => $result,
                'tier_used'       => 0,
                'tier_name'       => 'FastAPI (Error)',
                'result_type'     => 'none',
                'category'        => '',
                'confidence'      => 0.0,
                'state'           => 'out_of_distribution',
                'decision_source' => 'fastapi_error'
            ];
        }

        $result['decision_source'] = $result['decision_source'] ?? 'fastapi';
        return $result;
    }

    /**
     * Returns underlying InferenceClient instance.
     *
     * @return InferenceClient
     */
    public function get_client(): InferenceClient {
        return $this->client;
    }
}
