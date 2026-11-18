<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * LlmResponseValidator
 *
 * Enforces strict local validation on all data returned by an LLM provider.
 * Does NOT trust raw provider strings or uncalibrated provider confidence scores.
 *
 * Supported outcomes:
 *  1. existing_category      (term_id verified in both payload and WordPress DB)
 *  2. new_category_suggestion (sanitized name, max 50 chars, duplicate checked, NO DB mutation)
 *  3. uncertain              (safe unclassified fallback)
 */
class LlmResponseValidator {

    const MAX_NAME_LENGTH      = 50;
    const MAX_REASONING_LENGTH = 200;

    const DECISION_EXISTING = 'existing_category';
    const DECISION_NEW      = 'new_category_suggestion';
    const DECISION_UNCERTAIN = 'uncertain';

    /**
     * Validates raw provider response against strict contract.
     *
     * @param mixed $response        Raw output from LlmProviderInterface::classify().
     * @param array $sent_categories The exact taxonomy array sent to the provider.
     * @return array{valid: bool, error?: string, data?: array}
     */
    public static function validate($response, array $sent_categories = []): array {
        if (!is_array($response)) {
            return [
                'valid' => false,
                'error' => 'Provider response is not an array or valid JSON object.',
            ];
        }

        $decision_type = $response['decision_type'] ?? null;
        if (!is_string($decision_type) || !in_array($decision_type, [self::DECISION_EXISTING, self::DECISION_NEW, self::DECISION_UNCERTAIN], true)) {
            return [
                'valid' => false,
                'error' => sprintf('Invalid or missing decision_type: "%s".', is_scalar($decision_type) ? (string)$decision_type : gettype($decision_type)),
            ];
        }

        // Validate and clamp confidence (informational only; not treated as mathematically calibrated)
        $raw_confidence = $response['confidence'] ?? 0.0;
        if (!is_numeric($raw_confidence)) {
            return [
                'valid' => false,
                'error' => 'Confidence score must be numeric.',
            ];
        }
        $confidence = max(0.0, min(1.0, (float) $raw_confidence));

        // Sanitize reasoning text
        $raw_reasoning = (string) ($response['reasoning'] ?? '');
        $reasoning     = trim((string) (function_exists('\wp_strip_all_tags') ? \wp_strip_all_tags($raw_reasoning) : preg_replace('@<[^>]*>@', '', (string) $raw_reasoning)));
        if (mb_strlen($reasoning) > self::MAX_REASONING_LENGTH) {
            $reasoning = mb_substr($reasoning, 0, self::MAX_REASONING_LENGTH);
        }

        // Build quick lookup map of sent categories: [term_id => name]
        $valid_sent_ids = [];
        $name_to_id_map = [];
        foreach ($sent_categories as $cat) {
            $tid = (int) ($cat['term_id'] ?? 0);
            $name = trim((string)($cat['name'] ?? ''));
            if ($tid > 0) {
                $valid_sent_ids[$tid] = $name;
                $name_to_id_map[strtolower($name)] = $tid;
            }
        }

        // 1. Existing Category Verification
        if ($decision_type === self::DECISION_EXISTING) {
            $term_id = $response['term_id'] ?? null;
            if (!is_int($term_id) && (!is_string($term_id) || !ctype_digit((string)$term_id))) {
                return [
                    'valid' => false,
                    'error' => 'existing_category requires an integer term_id.',
                ];
            }
            $term_id = (int) $term_id;
            if ($term_id <= 0) {
                return [
                    'valid' => false,
                    'error' => 'term_id must be a positive integer > 0.',
                ];
            }

            // Must exist in the taxonomy array that was sent to provider
            if (!isset($valid_sent_ids[$term_id])) {
                return [
                    'valid' => false,
                    'error' => sprintf('Returned term_id %d was not in the taxonomy payload sent to provider.', $term_id),
                ];
            }

            // Must still exist in WordPress database
            if (function_exists('get_term')) {
                $term = get_term($term_id, 'category');
                if (!$term || (function_exists('is_wp_error') && is_wp_error($term))) {
                    return [
                        'valid' => false,
                        'error' => sprintf('Returned term_id %d does not exist in WordPress category taxonomy.', $term_id),
                    ];
                }
                $canonical_name = $term->name;
            } else {
                $canonical_name = $valid_sent_ids[$term_id];
            }

            return [
                'valid' => true,
                'data'  => [
                    'decision_type' => self::DECISION_EXISTING,
                    'term_id'       => $term_id,
                    'category_name' => $canonical_name,
                    'confidence'    => $confidence,
                    'reasoning'     => $reasoning,
                ],
            ];
        }

        // 2. New Category Suggestion Verification
        if ($decision_type === self::DECISION_NEW) {
            $raw_name = $response['suggested_category_name'] ?? null;
            if (!is_string($raw_name)) {
                return [
                    'valid' => false,
                    'error' => 'suggested_category_name must be a non-empty string.',
                ];
            }

            $cat_name = trim((string) (function_exists('\wp_strip_all_tags') ? \wp_strip_all_tags($raw_name) : preg_replace('@<[^>]*>@', '', (string) $raw_name)));
            if ($cat_name === '') {
                return [
                    'valid' => false,
                    'error' => 'suggested_category_name cannot be empty or whitespace.',
                ];
            }

            if (mb_strlen($cat_name) > self::MAX_NAME_LENGTH) {
                return [
                    'valid' => false,
                    'error' => sprintf('suggested_category_name exceeds maximum length of %d characters.', self::MAX_NAME_LENGTH),
                ];
            }

            // Duplicate Check: Check if suggested category already exists in active taxonomy
            $lower_name = strtolower($cat_name);
            if (isset($name_to_id_map[$lower_name])) {
                // Safely convert duplicate new category suggestion into existing_category
                $existing_id = $name_to_id_map[$lower_name];
                return [
                    'valid' => true,
                    'data'  => [
                        'decision_type' => self::DECISION_EXISTING,
                        'term_id'       => $existing_id,
                        'category_name' => $valid_sent_ids[$existing_id],
                        'confidence'    => $confidence,
                        'reasoning'     => $reasoning ?: sprintf('Suggested category "%s" matched existing category.', $cat_name),
                    ],
                ];
            }

            if (function_exists('term_exists')) {
                $exists = term_exists($cat_name, 'category');
                if ($exists) {
                    $existing_id = is_array($exists) ? (int) $exists['term_id'] : (int) $exists;
                    return [
                        'valid' => true,
                        'data'  => [
                            'decision_type' => self::DECISION_EXISTING,
                            'term_id'       => $existing_id,
                            'category_name' => function_exists('get_cat_name') ? get_cat_name($existing_id) : $cat_name,
                            'confidence'    => $confidence,
                            'reasoning'     => $reasoning ?: sprintf('Suggested category "%s" matched existing category in database.', $cat_name),
                        ],
                    ];
                }
            }

            // CRITICAL: NEVER call wp_insert_term() here. Return structured suggestion only.
            return [
                'valid' => true,
                'data'  => [
                    'decision_type'           => self::DECISION_NEW,
                    'suggested_category_name' => $cat_name,
                    'confidence'              => $confidence,
                    'reasoning'               => $reasoning,
                ],
            ];
        }

        // 3. Uncertain Decision
        return [
            'valid' => true,
            'data'  => [
                'decision_type' => self::DECISION_UNCERTAIN,
                'confidence'    => $confidence,
                'reasoning'     => $reasoning ?: 'Provider could not determine a suitable category.',
            ],
        ];
    }
}
