<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * Probability Calibrator for PHP-Native Inference.
 * Implements scikit-learn's Platt Sigmoid calibration:
 * prob'[c] = 1.0 / (1.0 + exp(a[c] * margin[c] + b[c]))
 * followed by per-fold L1 probability normalization.
 */
class ProbabilityCalibrator {

    /**
     * Calibrates raw margins into probabilities and normalizes across classes.
     *
     * @param array<int, float> $margins      Array of margins indexed by class.
     * @param array             $calibrations Array of calibration parameters [{a, b}].
     * @return array<int, float> Normalized probabilities summing to 1.0.
     */
    public function calibrate(array $margins, array $calibrations): array {
        $num_classes = count($margins);
        $raw_probs   = [];
        $sum_prob    = 0.0;

        for ($c = 0; $c < $num_classes; $c++) {
            $margin = (float)$margins[$c];
            $cal    = $calibrations[$c];
            $a      = (float)$cal['a'];
            $b      = (float)$cal['b'];

            $z = $a * $margin + $b;

            // Prevent floating point overflow / underflow with exp()
            if ($z > 700.0) {
                $p = 0.0;
            } elseif ($z < -700.0) {
                $p = 1.0;
            } else {
                $p = 1.0 / (1.0 + exp($z));
            }

            $raw_probs[$c] = $p;
            $sum_prob += $p;
        }

        // L1 Normalization (sum to 1.0 across classes for multiclass CalibratedClassifierCV)
        $normalized = [];
        if ($sum_prob > 0.0) {
            for ($c = 0; $c < $num_classes; $c++) {
                $normalized[$c] = $raw_probs[$c] / $sum_prob;
            }
        } else {
            $uniform = 1.0 / $num_classes;
            for ($c = 0; $c < $num_classes; $c++) {
                $normalized[$c] = $uniform;
            }
        }

        return $normalized;
    }
}
