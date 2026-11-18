<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * TF-IDF Vectorizer for PHP-Native Inference.
 * Implements scikit-learn compatible sublinear TF, smooth IDF,
 * and L2 unit-norm normalization producing sparse feature vectors.
 */
class TfidfVectorizer {

    /**
     * Text preprocessor.
     */
    private TextPreprocessor $preprocessor;

    /**
     * Vocabulary dictionary [term => feature_index].
     */
    private array $vocabulary;

    /**
     * Inverse Document Frequency array [feature_index => idf_float].
     */
    private array $idf;

    /**
     * Whether to apply sublinear TF scaling (1 + ln(tf)).
     */
    private bool $sublinear_tf;

    /**
     * Whether smooth IDF was used during training.
     */
    private bool $smooth_idf;

    /**
     * Normalization method ('l2', 'l1', or null).
     */
    private ?string $norm;

    /**
     * @param array            $config       Vectorizer config from model JSON.
     * @param TextPreprocessor $preprocessor
     */
    public function __construct(array $config, TextPreprocessor $preprocessor) {
        $this->preprocessor  = $preprocessor;
        $this->vocabulary    = $config['vocabulary'] ?? [];
        $this->idf           = $config['idf'] ?? [];
        $this->sublinear_tf  = !empty($config['sublinear_tf']);
        $this->smooth_idf    = !empty($config['smooth_idf']);
        $this->norm          = $config['norm'] ?? 'l2';
    }

    /**
     * Transforms raw text into a sparse TF-IDF feature vector.
     *
     * @param string $text
     * @return array<int, float> Sparse dictionary of [feature_index => tfidf_weight].
     */
    public function transform(string $text): array {
        $tokens = $this->preprocessor->preprocess($text);
        if (empty($tokens)) {
            return [];
        }

        // Count frequencies of terms that exist in the trained vocabulary
        $term_counts = [];
        foreach ($tokens as $token) {
            if (isset($this->vocabulary[$token])) {
                $term_counts[$token] = ($term_counts[$token] ?? 0) + 1;
            }
        }

        if (empty($term_counts)) {
            return [];
        }

        // Calculate unnormalized TF-IDF values
        $sparse_vector = [];
        $sum_squares   = 0.0;

        foreach ($term_counts as $term => $count) {
            $feat_idx = $this->vocabulary[$term];

            // Sublinear TF: 1.0 + log(count)
            $tf = $this->sublinear_tf ? (1.0 + log((float)$count)) : (float)$count;
            $idf_val = (float)($this->idf[$feat_idx] ?? 0.0);
            $weight = $tf * $idf_val;

            $sparse_vector[$feat_idx] = $weight;
            $sum_squares += $weight * $weight;
        }

        // Apply L2 Normalization (Euclidean norm)
        if ($this->norm === 'l2' && $sum_squares > 0.0) {
            $l2_norm = sqrt($sum_squares);
            foreach ($sparse_vector as $feat_idx => $weight) {
                $sparse_vector[$feat_idx] = $weight / $l2_norm;
            }
        }

        return $sparse_vector;
    }

    /**
     * Returns vocabulary mapping.
     *
     * @return array<string, int>
     */
    public function get_vocabulary(): array {
        return $this->vocabulary;
    }

    /**
     * Returns IDF weights array.
     *
     * @return array<int, float>
     */
    public function get_idf(): array {
        return $this->idf;
    }
}
