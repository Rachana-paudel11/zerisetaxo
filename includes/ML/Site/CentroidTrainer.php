<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * Category Centroid Classifier for site-specific category learning.
 *
 * Algorithm (Nearest Centroid / Rocchio):
 *   - Training: builds a TF-IDF-like centroid vector per class by averaging
 *     normalized token frequency vectors of all training documents.
 *   - Inference: cosine similarity between query vector and each centroid;
 *     highest similarity wins.
 *   - No vocabulary limit; only tokens from training data are used.
 *
 * Why Centroid for site-specific models:
 *   + Extremely fast inference: one dot product per class
 *   + Compact model: stores one float vector per class (not per document)
 *   + Handles moderate-to-large datasets well
 *   + Works well with keyword-rich WordPress content
 *   + Scales gracefully with number of categories
 *   - Performs poorly with very few (<3) training documents per class
 *   - Less theoretically justified for small counts than Naive Bayes
 *   - Centroid representation loses per-document detail
 *
 * Identity: category term_id is the canonical class key throughout.
 */
class CentroidTrainer {

    /** @var array<int, array<string, float>> $centroids [term_id => [token => centroid_weight]] */
    private array $centroids = [];

    /** @var array<int, int> $class_doc_counts [term_id => document count] */
    private array $class_doc_counts = [];

    /** @var array<int, string> $class_names [term_id => category name] */
    private array $class_names = [];

    /**
     * Train on a batch of categorized posts.
     *
     * @param array<array{term_id: int, name: string, title: string, content: string}> $posts
     */
    public function train(array $posts): void {
        // Accumulate raw TF vectors per class
        // centroid = mean of L2-normalized document vectors
        $accumulators = [];  // [term_id => [token => sum_of_normed_weights]]

        foreach ($posts as $post) {
            $term_id = (int) $post['term_id'];
            $name    = (string) $post['name'];
            $text    = trim(($post['title'] ?? '') . ' ' . ($post['content'] ?? ''));

            if (empty($text)) {
                continue;
            }

            $tokens = SiteTokenizer::tokenize($text);
            if (empty($tokens)) {
                continue;
            }

            // Register class
            if (!isset($this->class_doc_counts[$term_id])) {
                $this->class_doc_counts[$term_id] = 0;
                $accumulators[$term_id] = [];
            }
            $this->class_names[$term_id] = $name;
            $this->class_doc_counts[$term_id]++;

            // Count raw token frequencies
            $token_counts = [];
            foreach ($tokens as $tok) {
                $token_counts[$tok] = ($token_counts[$tok] ?? 0) + 1;
            }

            // Compute TF (sublinear: 1 + log(count)) and L2 normalize
            $tf_vector = [];
            $sum_sq = 0.0;
            foreach ($token_counts as $tok => $count) {
                $tf = 1.0 + log((float)$count);
                $tf_vector[$tok] = $tf;
                $sum_sq += $tf * $tf;
            }
            $l2 = $sum_sq > 0.0 ? sqrt($sum_sq) : 1.0;

            // Accumulate normalized vector
            if (!isset($accumulators[$term_id])) {
                $accumulators[$term_id] = [];
            }
            foreach ($tf_vector as $tok => $val) {
                $accumulators[$term_id][$tok] = ($accumulators[$term_id][$tok] ?? 0.0) + ($val / $l2);
            }
        }

        // Build centroids: average accumulator, then L2-normalize
        foreach ($accumulators as $term_id => $acc) {
            $n = $this->class_doc_counts[$term_id];
            if ($n === 0) {
                continue;
            }

            // Average (divide by document count)
            $avg_sq = 0.0;
            $avg = [];
            foreach ($acc as $tok => $sum_val) {
                $a = $sum_val / $n;
                $avg[$tok] = $a;
                $avg_sq += $a * $a;
            }

            // L2-normalize centroid
            $l2 = $avg_sq > 0.0 ? sqrt($avg_sq) : 1.0;
            foreach ($avg as $tok => $val) {
                $avg[$tok] = $val / $l2;
            }

            $this->centroids[$term_id] = $avg;
        }
    }

    /**
     * Predict a class for new text using cosine similarity.
     *
     * @param string     $text
     * @param array<int> $restrict_term_ids  Optional: only consider these class IDs
     * @return array{
     *   term_id: int,
     *   name: string,
     *   confidence: float,
     *   runner_up_id: int|null,
     *   runner_up_name: string,
     *   runner_up_confidence: float|null,
     *   margin: float,
     *   scores: array<int, float>,
     *   active_classes: int
     * }|null  null if no model data
     */
    public function predict(string $text, array $restrict_term_ids = []): ?array {
        if (empty($this->centroids)) {
            return null;
        }

        $class_ids = empty($restrict_term_ids)
            ? array_keys($this->centroids)
            : array_intersect(array_keys($this->centroids), $restrict_term_ids);

        if (empty($class_ids)) {
            return null;
        }

        $tokens = SiteTokenizer::tokenize($text);
        if (empty($tokens)) {
            // Return the largest class as default (by doc count)
            arsort($this->class_doc_counts);
            $top_id = array_key_first($this->class_doc_counts);
            return [
                'term_id'              => $top_id,
                'name'                 => $this->class_names[$top_id] ?? '',
                'confidence'           => 0.0,
                'runner_up_id'         => null,
                'runner_up_name'       => '',
                'runner_up_confidence' => null,
                'margin'               => 0.0,
                'scores'               => [],
                'active_classes'       => count($class_ids),
            ];
        }

        // Build query TF vector (sublinear, L2-normalized)
        $query_counts = [];
        foreach ($tokens as $tok) {
            $query_counts[$tok] = ($query_counts[$tok] ?? 0) + 1;
        }
        $query_tf = [];
        $sum_sq = 0.0;
        foreach ($query_counts as $tok => $cnt) {
            $v = 1.0 + log((float)$cnt);
            $query_tf[$tok] = $v;
            $sum_sq += $v * $v;
        }
        $q_l2 = $sum_sq > 0.0 ? sqrt($sum_sq) : 1.0;
        foreach ($query_tf as $tok => $v) {
            $query_tf[$tok] = $v / $q_l2;
        }

        // Cosine similarity: dot product with each centroid (already L2-normalized)
        $cosines = [];
        foreach ($class_ids as $tid) {
            $centroid = $this->centroids[$tid];
            $dot = 0.0;
            foreach ($query_tf as $tok => $qv) {
                if (isset($centroid[$tok])) {
                    $dot += $qv * $centroid[$tok];
                }
            }
            // Clamp to [0, 1] (should already be in range for normalized vectors)
            $cosines[$tid] = max(0.0, min(1.0, $dot));
        }

        // Convert raw cosines to softmax probabilities
        $max_cos = max($cosines);
        $exp_scores = [];
        $sum_exp = 0.0;
        foreach ($cosines as $tid => $cos) {
            $e = exp(($cos - $max_cos) * 10.0); // temperature scaling
            $exp_scores[$tid] = $e;
            $sum_exp += $e;
        }
        $probs = [];
        foreach ($exp_scores as $tid => $e) {
            $probs[$tid] = $sum_exp > 0.0 ? $e / $sum_exp : 0.0;
        }

        arsort($probs);
        $sorted_ids = array_keys($probs);

        $top_id = $sorted_ids[0];
        $top_p  = $probs[$top_id];
        $run_id = $sorted_ids[1] ?? null;
        $run_p  = isset($run_id) ? $probs[$run_id] : null;
        $margin = isset($run_p) ? $top_p - $run_p : 1.0;

        return [
            'term_id'              => $top_id,
            'name'                 => $this->class_names[$top_id] ?? '',
            'confidence'           => round($top_p, 6),
            'runner_up_id'         => $run_id,
            'runner_up_name'       => isset($run_id) ? ($this->class_names[$run_id] ?? '') : '',
            'runner_up_confidence' => isset($run_p) ? round($run_p, 6) : null,
            'margin'               => round($margin, 6),
            'scores'               => $probs,
            'active_classes'       => count($class_ids),
        ];
    }

    /**
     * Serialize model state for storage.
     *
     * @return array
     */
    public function export(): array {
        return [
            'algorithm'        => 'centroid',
            'version'          => '1.0.0',
            'class_names'      => $this->class_names,
            'class_doc_counts' => $this->class_doc_counts,
            'centroids'        => $this->centroids,
        ];
    }

    /**
     * Restore a previously exported model.
     *
     * @param array $data
     * @return self
     */
    public static function from_export(array $data): self {
        $obj = new self();
        $obj->class_names      = $data['class_names'] ?? [];
        $obj->class_doc_counts = $data['class_doc_counts'] ?? [];
        $obj->centroids        = $data['centroids'] ?? [];
        return $obj;
    }

    /**
     * Returns per-class training statistics.
     *
     * @return array<int, array{name: string, doc_count: int, centroid_terms: int}>
     */
    public function get_class_stats(): array {
        $stats = [];
        foreach ($this->class_doc_counts as $tid => $docs) {
            $stats[$tid] = [
                'name'          => $this->class_names[$tid] ?? '',
                'doc_count'     => $docs,
                'centroid_terms' => count($this->centroids[$tid] ?? []),
            ];
        }
        return $stats;
    }

    public function get_class_count(): int   { return count($this->class_doc_counts); }
    public function get_class_names(): array { return $this->class_names; }
    public function get_total_docs(): int {
        return array_sum($this->class_doc_counts);
    }
}
