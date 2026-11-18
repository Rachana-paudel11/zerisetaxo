<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * LlmCache
 *
 * Manages transient caching of validated LLM category predictions.
 *
 * Cache key is formed from:
 *  - Sanitized title
 *  - Sanitized 600-character content sample
 *  - Deterministic taxonomy hash (includes term_id, name, parent, description)
 *  - Provider ID
 *  - Provider Model ID
 *  - Schema / Prompt version
 *
 * Any change to content, taxonomy (including renames and hierarchy updates),
 * provider, or prompt schema triggers an immediate cache MISS.
 */
class LlmCache {

    const TRANSIENT_PREFIX = 'zerisetaxo_llm_c_';
    const DEFAULT_TTL      = 604800; // 7 days in seconds

    /**
     * Builds a deterministic hash of the active taxonomy.
     * Incorporates term_id, name, parent, and description to ensure any rename,
     * parent change, or deletion invalidates cached predictions.
     *
     * @param array $categories Array of categories (WP_Term objects or arrays).
     * @return string 32-character md5 hash.
     */
    public static function build_taxonomy_hash(array $categories): string {
        $normalized = [];
        foreach ($categories as $cat) {
            if (is_object($cat)) {
                $term_id     = (int) ($cat->term_id ?? 0);
                $name        = (string) ($cat->name ?? '');
                $parent      = (int) ($cat->parent ?? 0);
                $description = (string) ($cat->description ?? '');
            } elseif (is_array($cat)) {
                $term_id     = (int) ($cat['term_id'] ?? $cat['id'] ?? 0);
                $name        = (string) ($cat['name'] ?? '');
                $parent      = (int) ($cat['parent'] ?? 0);
                $description = (string) ($cat['description'] ?? '');
            } else {
                continue;
            }

            if ($term_id > 0) {
                $normalized[] = sprintf('%d:%s:%d:%s', $term_id, trim($name), $parent, trim($description));
            }
        }

        // Sort deterministically
        sort($normalized, SORT_STRING);

        return md5(implode('|', $normalized));
    }

    /**
     * Builds unique cache key for the prediction request.
     *
     * @param string $title
     * @param string $content_sample
     * @param array  $categories
     * @param string $provider_id
     * @param string $model_id
     * @param string $schema_version
     * @return string
     */
    public static function build_cache_key(
        string $title,
        string $content_sample,
        array $categories,
        string $provider_id,
        string $model_id,
        string $schema_version = 'v1'
    ): string {
        $tax_hash = self::build_taxonomy_hash($categories);

        $identity = implode('::', [
            $title,
            $content_sample,
            $tax_hash,
            $provider_id,
            $model_id,
            $schema_version,
        ]);

        return self::TRANSIENT_PREFIX . md5($identity);
    }

    /**
     * Retrieve cached prediction if available.
     *
     * @param string $cache_key
     * @return array|null
     */
    public static function get(string $cache_key): ?array {
        if (!function_exists('get_transient')) {
            return null;
        }

        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['data'])) {
            return $cached;
        }

        return null;
    }

    /**
     * Store validated prediction in cache.
     *
     * @param string $cache_key
     * @param array  $data
     * @param int    $ttl Seconds (default 7 days).
     * @return bool
     */
    public static function set(string $cache_key, array $data, int $ttl = self::DEFAULT_TTL): bool {
        if (!function_exists('set_transient')) {
            return false;
        }

        $payload = [
            'data'      => $data,
            'cached_at' => time(),
        ];

        return set_transient($cache_key, $payload, $ttl);
    }

    /**
     * Invalidate specific cached key.
     *
     * @param string $cache_key
     * @return bool
     */
    public static function delete(string $cache_key): bool {
        if (!function_exists('delete_transient')) {
            return false;
        }
        return delete_transient($cache_key);
    }
}
