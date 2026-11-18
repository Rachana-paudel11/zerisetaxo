<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * Model Loader for PHP-Native Machine Learning Artifacts.
 * Loads, validates, and caches the portable JSON model representation.
 */
class ModelLoader {

    /**
     * In-memory model cache to prevent repeated disk I/O and JSON parsing.
     *
     * @var array<string, array>
     */
    private static array $cache = [];

    /**
     * Supported format versions.
     */
    const SUPPORTED_FORMAT_VERSIONS = ['1.0.0', '2.0.0'];
    const EXPECTED_NUM_CLASSES  = 42;
    const EXPECTED_NUM_FEATURES = 20776;
    const EXPECTED_NUM_FOLDS    = 3;

    /**
     * Loads and validates the portable model artifact.
     * Supports both canonical Format 1.0.0 (JSON) and optimized Format 2.0.0 (metadata + binary weights).
     *
     * @param string $model_path Absolute path to model JSON file or runtime directory.
     * @param bool   $use_cache  Whether to return cached instance if available.
     * @return array Loaded and validated model array.
     * @throws \RuntimeException If file is missing, invalid, or corrupted.
     */
    public static function load(string $model_path, bool $use_cache = true): array {
        if ($use_cache && isset(self::$cache[$model_path])) {
            return self::$cache[$model_path];
        }

        // If path is a directory, look for metadata inside it
        if (is_dir($model_path)) {
            $f32_meta     = rtrim($model_path, '/\\') . '/metadata_float32.json';
            $default_meta = rtrim($model_path, '/\\') . '/metadata.json';
            $f64_meta     = rtrim($model_path, '/\\') . '/metadata_float64.json';
            if (file_exists($f32_meta)) {
                $model_path = $f32_meta;
            } elseif (file_exists($default_meta)) {
                $model_path = $default_meta;
            } elseif (file_exists($f64_meta)) {
                $model_path = $f64_meta;
            } else {
                throw new \RuntimeException(sprintf('No metadata found in runtime directory: %s', esc_html($model_path)));
            }
        }

        if (!self::is_file_readable($model_path)) {
            throw new \RuntimeException(sprintf(
                'ML model artifact not found or unreadable at: %s',
                esc_html($model_path)
            ));
        }

        $raw = file_get_contents($model_path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf(
                'Failed to read model artifact from: %s',
                esc_html($model_path)
            ));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Invalid JSON model artifact at: %s. Error: %s',
                esc_html($model_path),
                esc_html(json_last_error_msg())
            ));
        }

        self::validate_schema($data, $model_path);

        // Format 2.0.0: Load runtime weights (CSV preferred, binary fallback)
        if ($data['format_version'] === '2.0.0') {
            self::load_runtime_weights($data, $model_path);
        }

        if ($use_cache) {
            self::$cache[$model_path] = $data;
        }

        return $data;
    }

    /**
     * Validates required keys and dimensions in model artifact.
     *
     * @param array  $data
     * @param string $model_path
     * @throws \RuntimeException
     */
    private static function validate_schema(array $data, string $model_path): void {
        $required_keys = ['format_version', 'model_metadata', 'classes', 'vectorizer', 'classifier'];
        foreach ($required_keys as $key) {
            if (!isset($data[$key])) {
                throw new \RuntimeException(sprintf(
                    'Model artifact at %s missing required key: %s',
                    esc_html($model_path),
                    esc_html($key)
                ));
            }
        }

        if (!in_array($data['format_version'], self::SUPPORTED_FORMAT_VERSIONS, true)) {
            throw new \RuntimeException(sprintf(
                'Unsupported model format version: %s (expected %s)',
                esc_html($data['format_version']),

                esc_html(implode(' or ', self::SUPPORTED_FORMAT_VERSIONS))
            ));
        }

        $classes_count = count($data['classes'] ?? []);
        if ($classes_count !== self::EXPECTED_NUM_CLASSES) {
            throw new \RuntimeException(sprintf(
                'Model artifact class count %d does not match expected %d.',
                (int) $classes_count,
                (int) self::EXPECTED_NUM_CLASSES
            ));
        }

        $vocab_count = count($data['vectorizer']['vocabulary'] ?? []);
        $idf_count   = count($data['vectorizer']['idf'] ?? []);
        if ($vocab_count !== self::EXPECTED_NUM_FEATURES || $idf_count !== self::EXPECTED_NUM_FEATURES) {
            throw new \RuntimeException(sprintf(
                'Model artifact feature dimensions invalid: vocab=%d, idf=%d (expected %d).',
                (int) $vocab_count,
                (int) $idf_count,
                (int) self::EXPECTED_NUM_FEATURES
            ));
        }

        $folds_count = count($data['classifier']['folds'] ?? []);
        if ($folds_count !== self::EXPECTED_NUM_FOLDS) {
            throw new \RuntimeException(sprintf(
                'Model artifact fold count %d does not match expected %d.',
                (int) $folds_count,
                (int) self::EXPECTED_NUM_FOLDS
            ));
        }
    }

    /**
     * Validates and loads runtime weights for Format 2.0.0.
     * Prefers random-access CSV weights for WordPress.org compliance; falls back to packed binary if present.
     *
     * @param array  &$data
     * @param string $model_path
     * @throws \RuntimeException
     */
    /**
     * Cross-platform check whether a file exists and is readable (handles Windows PHP is_readable bug).
     */
    private static function is_file_readable(string $path): bool {
        if (!file_exists($path)) {
            return false;
        }
        if (is_readable($path)) {
            return true;
        }
        $fp = @fopen($path, 'rb');
        if ($fp !== false) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private static function load_runtime_weights(array &$data, string $model_path): void {
        $meta = $data['model_metadata'] ?? [];
        $dir  = dirname($model_path);

        // 1. Check for CSV weights (Option 1 - WordPress.org compatible text format)
        $csv_filename     = $meta['weights_filename'] ?? 'weights_feature_major.csv';
        $offsets_filename = $meta['offsets_filename'] ?? 'weights_offsets.json';
        $csv_path         = $dir . '/' . $csv_filename;
        $offsets_path     = $dir . '/' . $offsets_filename;

        if (self::is_file_readable($csv_path) && self::is_file_readable($offsets_path)) {
            $offsets_raw = file_get_contents($offsets_path);
            $offsets     = json_decode($offsets_raw, true);
            if (!is_array($offsets) || count($offsets) !== self::EXPECTED_NUM_FEATURES) {
                throw new \RuntimeException(sprintf(
                    'Invalid or corrupt weights offsets at: %s (expected %d entries)',
                    esc_html($offsets_path),
                    (int) self::EXPECTED_NUM_FEATURES
                ));
            }

            $data['is_csv']           = true;
            $data['weights_csv_path'] = $csv_path;
            $data['offsets']          = $offsets;
            $data['precision']        = $meta['precision'] ?? 'float32';
            return;
        }

        // 2. Fallback to packed binary weights if present
        $bin_filename = $meta['binary_filename'] ?? 'weights_float32.bin';
        $bin_path     = $dir . '/' . $bin_filename;
        if (file_exists($bin_path) && is_readable($bin_path)) {
            self::load_binary_weights($data, $model_path);
            return;
        }

        throw new \RuntimeException(sprintf(
            'Runtime weights file missing or unreadable in directory: %s',
            esc_html($dir)
        ));
    }

    /**
     * Validates and loads packed binary weights for Format 2.0.0.
     *
     * @param array  &$data
     * @param string $model_path
     * @throws \RuntimeException
     */
    private static function load_binary_weights(array &$data, string $model_path): void {
        $meta = $data['model_metadata'] ?? [];

        $required_meta = [
            'num_features', 'num_classes', 'num_folds',
            'precision', 'binary_filename', 'binary_size_bytes', 'binary_sha256'
        ];
        foreach ($required_meta as $key) {
            if (!isset($meta[$key])) {
                throw new \RuntimeException(sprintf(
                    'Format 2.0.0 model metadata missing required parameter: %s',
                    esc_html($key)
                ));
            }
        }

        $precision = $meta['precision'];
        if (!in_array($precision, ['float32', 'float64'], true)) {
            throw new \RuntimeException(sprintf('Unsupported numeric precision: %s', esc_html($precision)));
        }

        $expected_bytes = (int)$meta['binary_size_bytes'];
        $bin_filename   = $meta['binary_filename'];
        $bin_path       = dirname($model_path) . '/' . $bin_filename;

        if (!self::is_file_readable($bin_path)) {
            throw new \RuntimeException(sprintf(
                'Binary weights file missing or unreadable at: %s',
                esc_html($bin_path)
            ));
        }

        $actual_size = filesize($bin_path);
        if ($actual_size !== $expected_bytes) {
            throw new \RuntimeException(sprintf(
                'Binary weights file size mismatch at %s: expected %d bytes, found %d bytes (truncated or corrupt)',
                esc_html($bin_path),
                (int) $expected_bytes,
                (int) $actual_size
            ));
        }

        $raw_binary = file_get_contents($bin_path);
        if ($raw_binary === false) {
            throw new \RuntimeException(sprintf('Failed to read binary weights from: %s', esc_html($bin_path)));
        }

        $actual_sha256 = hash('sha256', $raw_binary);
        if ($actual_sha256 !== $meta['binary_sha256']) {
            throw new \RuntimeException(sprintf(
                'Binary weights checksum mismatch: expected %s, got %s',
                esc_html($meta['binary_sha256']),
                esc_html($actual_sha256)
            ));
        }

        $data['is_binary']   = true;
        $data['weights_bin'] = $raw_binary;
        $data['precision']   = $precision;
    }

    /**
     * Clears in-memory model cache.
     */
    public static function clear_cache(): void {
        self::$cache = [];
    }
}
