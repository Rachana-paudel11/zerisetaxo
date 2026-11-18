<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * Multinomial Naive Bayes classifier for site-specific category learning.
 *
 * Algorithm:
 *   - Training: collects per-class token frequency counts + document counts.
 *   - Inference: computes log-posterior for each class using Laplace
 *     smoothing (α = 1.0) and returns softmax-normalized probabilities.
 *   - Complementary token set: global vocabulary grows from training data —
 *     no fixed vocabulary size required.
 *
 * Why Naive Bayes for site-specific models:
 *   + Handles very small datasets (even 1–5 posts per class)
 *   + O(V) inference (fast)
 *   + Incremental updates possible (just add counts)
 *   + Memory-efficient (only non-zero counts stored)
 *   + Battle-tested for short text / noisy text
 *   - Assumes feature independence (rarely perfectly true)
 *   - Can be over-confident when classes are unbalanced
 *
 * Identity: category term_id is the canonical class key throughout.
 */
class NaiveBayesTrainer {

    /** @var array<int, array<string, int>> $class_token_counts [term_id => [token => count]] */
    private array $class_token_counts = [];

    /** @var array<int, int> $class_doc_counts [term_id => document count] */
    private array $class_doc_counts = [];

    /** @var array<int, string> $class_names [term_id => category name] */
    private array $class_names = [];

    /** @var int Total training documents */
    private int $total_docs = 0;

    /** Laplace smoothing alpha */
    private float $alpha;

    public function __construct(float $alpha = 1.0) {
        $this->alpha = $alpha;
    }

    /**
     * Train on a batch of categorized posts.
     *
     * @param array<array{term_id: int, name: string, title: string, content: string}> $posts
     */
    public function train(array $posts): void {
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
            if (!isset($this->class_token_counts[$term_id])) {
                $this->class_token_counts[$term_id] = [];
                $this->class_doc_counts[$term_id]   = 0;
            }
            $this->class_names[$term_id] = $name;
            $this->class_doc_counts[$term_id]++;
            $this->total_docs++;

            // Accumulate token counts
            foreach ($tokens as $tok) {
                $this->class_token_counts[$term_id][$tok] =
                    ($this->class_token_counts[$term_id][$tok] ?? 0) + 1;
            }
        }
    }

    /**
     * Predict a class for new text.
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
        if (empty($this->class_token_counts)) {
            return null;
        }

        $class_ids = empty($restrict_term_ids)
            ? array_keys($this->class_token_counts)
            : array_intersect(array_keys($this->class_token_counts), $restrict_term_ids);

        if (empty($class_ids)) {
            return null;
        }

        $tokens = SiteTokenizer::tokenize($text);

        // Build global vocabulary token counts (for smoothing denominator)
        $global_vocab_size = $this->get_vocabulary_size();

        // Log-prior + log-likelihood for each class
        $log_scores = [];
        foreach ($class_ids as $tid) {
            // Log prior: ln(doc_count / total_docs)
            $log_prior = log($this->class_doc_counts[$tid] / $this->total_docs);

            // Total tokens in this class (sum of all counts)
            $class_total_tokens = array_sum($this->class_token_counts[$tid]);

            // Log-likelihood with Laplace smoothing
            $log_ll = 0.0;
            foreach ($tokens as $tok) {
                $tok_count  = $this->class_token_counts[$tid][$tok] ?? 0;
                // Laplace: (count + alpha) / (total + alpha * vocab_size)
                $log_ll += log(($tok_count + $this->alpha) / ($class_total_tokens + $this->alpha * $global_vocab_size));
            }

            $log_scores[$tid] = $log_prior + $log_ll;
        }

        // Softmax normalization for probabilities
        $max_log = max($log_scores);
        $exp_scores = [];
        $sum_exp = 0.0;
        foreach ($log_scores as $tid => $ls) {
            $exp_scores[$tid] = exp($ls - $max_log);
            $sum_exp += $exp_scores[$tid];
        }

        $probs = [];
        foreach ($exp_scores as $tid => $es) {
            $probs[$tid] = $sum_exp > 0.0 ? $es / $sum_exp : 0.0;
        }

        // Sort descending
        arsort($probs);
        $sorted_ids = array_keys($probs);

        $top_id  = $sorted_ids[0];
        $top_p   = $probs[$top_id];
        $run_id  = $sorted_ids[1] ?? null;
        $run_p   = isset($run_id) ? $probs[$run_id] : null;
        $margin  = isset($run_p) ? $top_p - $run_p : 1.0;

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
            'algorithm'          => 'naive_bayes',
            'version'            => '1.0.0',
            'alpha'              => $this->alpha,
            'class_names'        => $this->class_names,
            'class_doc_counts'   => $this->class_doc_counts,
            'class_token_counts' => $this->class_token_counts,
            'total_docs'         => $this->total_docs,
        ];
    }

    /**
     * Restore a previously exported model.
     *
     * @param array $data
     * @return self
     */
    public static function from_export(array $data): self {
        $obj = new self($data['alpha'] ?? 1.0);
        $obj->class_names        = $data['class_names'] ?? [];
        $obj->class_doc_counts   = $data['class_doc_counts'] ?? [];
        $obj->class_token_counts = $data['class_token_counts'] ?? [];
        $obj->total_docs         = $data['total_docs'] ?? 0;
        return $obj;
    }

    /**
     * Size of the vocabulary (union across all classes).
     */
    public function get_vocabulary_size(): int {
        $vocab = [];
        foreach ($this->class_token_counts as $counts) {
            foreach ($counts as $tok => $_) {
                $vocab[$tok] = true;
            }
        }
        return count($vocab);
    }

    /**
     * Returns per-class training statistics.
     *
     * @return array<int, array{name: string, doc_count: int, token_count: int}>
     */
    public function get_class_stats(): array {
        $stats = [];
        foreach ($this->class_doc_counts as $tid => $docs) {
            $stats[$tid] = [
                'name'        => $this->class_names[$tid] ?? '',
                'doc_count'   => $docs,
                'token_count' => array_sum($this->class_token_counts[$tid] ?? []),
            ];
        }
        return $stats;
    }

    public function get_total_docs(): int    { return $this->total_docs; }
    public function get_class_count(): int   { return count($this->class_doc_counts); }
    public function get_class_names(): array { return $this->class_names; }
}
