<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * Site model storage manager.
 *
 * Stores the site-specific trained model compactly in wp_options.
 * The full model is serialized as compressed JSON.
 *
 * Storage key: zerisetaxo_site_model
 * Metadata key: zerisetaxo_site_model_meta
 *
 * Model entry format:
 * {
 *   "algorithm":    "naive_bayes" | "centroid",
 *   "version":      "1.0.0",
 *   "trained_at":   <unix timestamp>,
 *   "training_docs": <int>,
 *   "class_count":   <int>,
 *   "model_data":   { ... algorithm-specific ... }
 * }
 *
 * Separate lightweight meta row kept for status checks
 * (never loads full model data unless prediction needed).
 */
class SiteModelStore {

    const OPTION_MODEL      = 'zerisetaxo_site_model';
    const OPTION_META       = 'zerisetaxo_site_model_meta';
    const OPTION_ALGORITHM  = 'zerisetaxo_site_model_algorithm';

    /**
     * Save a trained model to wp_options.
     *
     * @param array  $model_export  Export from NaiveBayesTrainer::export() or CentroidTrainer::export()
     * @param int    $training_docs Number of training documents used
     * @param string $algorithm     'naive_bayes' or 'centroid'
     */
    public static function save(array $model_export, int $training_docs, string $algorithm): bool {
        $entry = [
            'algorithm'    => $algorithm,
            'version'      => '1.0.0',
            'trained_at'   => time(),
            'training_docs' => $training_docs,
            'class_count'  => count($model_export['class_names'] ?? []),
            'model_data'   => $model_export,
        ];

        $json   = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $stored = update_option(self::OPTION_MODEL, $json, false);

        // Update lightweight meta (always succeeds)
        $meta = [
            'algorithm'    => $algorithm,
            'trained_at'   => $entry['trained_at'],
            'training_docs' => $training_docs,
            'class_count'  => $entry['class_count'],
            'class_names'  => array_values($model_export['class_names'] ?? []),
            'status'       => 'ready',
        ];
        update_option(self::OPTION_META, $meta, false);
        update_option(self::OPTION_ALGORITHM, $algorithm, false);

        return $stored;
    }

    /**
     * Load the full model from wp_options.
     *
     * @return array|null Full model entry or null if not stored
     */
    public static function load(): ?array {
        $json = get_option(self::OPTION_MODEL, null);
        if (empty($json) || !is_string($json)) {
            return null;
        }
        $entry = json_decode($json, true);
        if (!is_array($entry)) {
            return null;
        }
        return $entry;
    }

    /**
     * Load lightweight metadata without parsing the full model.
     *
     * @return array|null
     */
    public static function load_meta(): ?array {
        $meta = get_option(self::OPTION_META, null);
        return is_array($meta) ? $meta : null;
    }

    /**
     * Delete the stored site model.
     */
    public static function delete(): void {
        delete_option(self::OPTION_MODEL);
        delete_option(self::OPTION_META);
        delete_option(self::OPTION_ALGORITHM);
    }

    /**
     * Returns true if a trained site model exists and is marked ready, healthy, or limited.
     */
    public static function is_available(): bool {
        $meta = self::load_meta();
        return !empty($meta) && in_array($meta['status'] ?? '', ['ready', 'healthy', 'limited'], true);
    }

    /**
     * Returns raw size of stored model in bytes.
     */
    public static function get_stored_size_bytes(): int {
        $json = get_option(self::OPTION_MODEL, '');
        return is_string($json) ? strlen($json) : 0;
    }

    /**
     * Restore a NaiveBayesTrainer or CentroidTrainer from stored model.
     *
     * @return NaiveBayesTrainer|CentroidTrainer|null
     */
    public static function restore_trainer() {
        $entry = self::load();
        if (!$entry) {
            return null;
        }

        $algorithm   = $entry['algorithm'] ?? 'naive_bayes';
        $model_data  = $entry['model_data'] ?? [];

        if ($algorithm === 'centroid') {
            return CentroidTrainer::from_export($model_data);
        }
        return NaiveBayesTrainer::from_export($model_data);
    }
}
