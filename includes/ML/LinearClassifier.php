<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * Linear Classifier for PHP-Native Inference.
 * Computes decision margins for one-vs-rest LinearSVC models
 * using sparse vector dot products.
 */
class LinearClassifier {

    /**
     * File handle for lazy CSV weights streaming.
     *
     * @var resource|null
     */
    private $csv_handle = null;

    /**
     * Active CSV file path.
     *
     * @var string|null
     */
    private ?string $csv_path = null;

    /**
     * Computes linear decision function margins for each class in a fold:
     * margin[c] = intercept[c] + dot_product(sparse_vector, coefficients[c])
     *
     * @param array<int, float> $sparse_vector Sparse vector [feature_index => weight].
     * @param array             $fold          Fold parameters containing 'intercept' and 'coefficients'.
     * @return array<int, float> Array of margins indexed by class index.
     */
    public function compute_decision_functions(array $sparse_vector, array $fold): array {
        $intercepts   = $fold['intercept'];
        $coefficients = $fold['coefficients'];
        $num_classes  = count($intercepts);

        $margins = [];

        for ($c = 0; $c < $num_classes; $c++) {
            $margin = (float)$intercepts[$c];
            $class_coefs = $coefficients[$c];

            foreach ($sparse_vector as $feat_idx => $feat_val) {
                $margin += $feat_val * (float)$class_coefs[$feat_idx];
            }

            $margins[$c] = $margin;
        }

        return $margins;
    }

    /**
     * Computes linear decision function margins for each fold and class
     * from a feature-major packed binary weights string.
     * Evaluates all 3 folds simultaneously in a single pass over active features.
     *
     * @param array<int, float> $sparse_vector Sparse vector [feature_index => weight].
     * @param array             $folds         Array of fold metadata (containing 'intercept').
     * @param string            $weights_bin   Raw packed binary weights string.
     * @param string            $precision     'float64' or 'float32'.
     * @return array<int, array<int, float>> Margins array [fold_idx => [class_idx => margin]].
     */
    /**
     * Computes linear decision function margins for each fold and class
     * from a feature-major CSV weights file via lazy fseek/fgets random access.
     * Evaluates all 3 folds simultaneously in a single pass over active features.
     *
     * @param array<int, float> $sparse_vector Sparse vector [feature_index => weight].
     * @param array             $folds         Array of fold metadata (containing 'intercept').
     * @param string            $csv_path      Absolute path to weights_feature_major.csv.
     * @param array<int, int>   $offsets       Array of byte offsets indexed by feature_index.
     * @return array<int, array<int, float>> Margins array [fold_idx => [class_idx => margin]].
     */
    public function compute_csv_decision_functions(
        array $sparse_vector,
        array $folds,
        string $csv_path,
        array $offsets
    ): array {
        $num_folds   = count($folds);
        $num_classes = count($folds[0]['intercept']);

        $margins = [];
        for ($f = 0; $f < $num_folds; $f++) {
            $margins[$f] = $folds[$f]['intercept'];
        }

        if (empty($sparse_vector)) {
            return $margins;
        }

        if (!$this->csv_handle || $this->csv_path !== $csv_path) {
            if (is_resource($this->csv_handle)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file handle for random-access dataset streaming.
                fclose($this->csv_handle);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file handle for read-only random-access dataset streaming.
            $this->csv_handle = fopen($csv_path, 'rb');
            $this->csv_path   = $csv_path;
        }

        if (!$this->csv_handle) {
            throw new \RuntimeException(sprintf('Cannot open weights CSV file: %s', esc_html($csv_path)));
        }

        foreach ($sparse_vector as $feat_idx => $feat_val) {
            if (!isset($offsets[$feat_idx])) {
                continue;
            }
            fseek($this->csv_handle, (int)$offsets[$feat_idx]);
            $line = fgets($this->csv_handle);
            if ($line === false) {
                continue;
            }
            $w = explode(',', rtrim($line, "\r\n"));

            // w is 0-indexed:
            // Fold 0: 0..41
            // Fold 1: 42..83
            // Fold 2: 84..125
            for ($c = 0; $c < $num_classes; $c++) {
                $margins[0][$c] += $feat_val * (float)$w[$c];
                $margins[1][$c] += $feat_val * (float)$w[$c + 42];
                $margins[2][$c] += $feat_val * (float)$w[$c + 84];
            }
        }

        return $margins;
    }

    /**
     * Closes open CSV file handle on object destruction.
     */
    public function __destruct() {
        if (is_resource($this->csv_handle)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close dataset stream on destruction.
            fclose($this->csv_handle);
            $this->csv_handle = null;
        }
    }

    public function compute_binary_decision_functions(
        array $sparse_vector,
        array $folds,
        string $weights_bin,
        string $precision = 'float64'
    ): array {
        $num_folds   = count($folds);
        $num_classes = count($folds[0]['intercept']);

        $margins = [];
        for ($f = 0; $f < $num_folds; $f++) {
            $margins[$f] = $folds[$f]['intercept'];
        }

        if (empty($sparse_vector)) {
            return $margins;
        }

        $is_f32         = ('float32' === $precision);
        $unpack_code    = $is_f32 ? 'g126' : 'e126';
        $bytes_per_feat = $is_f32 ? 504 : 1008; // 126 * 4 or 126 * 8

        foreach ($sparse_vector as $feat_idx => $feat_val) {
            $slice = substr($weights_bin, $feat_idx * $bytes_per_feat, $bytes_per_feat);
            $w     = unpack($unpack_code, $slice);

            // w is 1-indexed by unpack: 1..126
            // Fold 0: 1..42
            // Fold 1: 43..84
            // Fold 2: 85..126
            for ($c = 0; $c < $num_classes; $c++) {
                $margins[0][$c] += $feat_val * $w[$c + 1];
                $margins[1][$c] += $feat_val * $w[$c + 43];
                $margins[2][$c] += $feat_val * $w[$c + 85];
            }
        }

        return $margins;
    }
}
