<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * LlmInputSanitizer
 *
 * Prepares and sanitizes post content before sending to an external LLM provider.
 * Implements strict data minimization:
 *  - Title capped at 200 characters.
 *  - Body excerpt capped at 600 characters.
 *  - Strips HTML tags, Gutenberg block comments, shortcodes, URLs, emails, phone numbers.
 *  - Filters out all WordPress metadata (post ID, author ID, timestamps, custom fields).
 */
class LlmInputSanitizer {

    const MAX_TITLE_LENGTH   = 200;
    const MAX_CONTENT_LENGTH = 600;

    /**
     * Sanitize post title.
     *
     * @param string $title
     * @return string
     */
    public static function sanitize_title(string $title): string {
        $clean = self::strip_markup($title);
        $clean = self::remove_pii($clean);
        $clean = self::normalize_whitespace($clean);

        if (mb_strlen($clean) > self::MAX_TITLE_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_TITLE_LENGTH);
        }

        return trim($clean);
    }

    /**
     * Sanitize post body content and truncate to sample size.
     *
     * @param string $content
     * @return string
     */
    public static function sanitize_content(string $content): string {
        $clean = self::strip_markup($content);
        $clean = self::remove_pii($clean);
        $clean = self::normalize_whitespace($clean);

        if (mb_strlen($clean) > self::MAX_CONTENT_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_CONTENT_LENGTH);
        }

        return trim($clean);
    }

    /**
     * Strips HTML, Gutenberg comments, and WordPress shortcodes.
     *
     * @param string $text
     * @return string
     */
    public static function strip_markup(string $text): string {
        // 1. Remove Gutenberg block comments: <!-- wp:... --> and <!-- /wp:... -->
        $text = preg_replace('/<!--\s*\/?wp:[^>]*-->/s', ' ', $text);

        // 2. Remove standard HTML comments
        $text = preg_replace('/<!--.*?-->/s', ' ', $text);

        // 3. Remove script and style blocks including contents
        $text = preg_replace('/<(script|style)[^>]*?>.*?<\/\\1>/si', ' ', $text);

        // 4. Remove all HTML tags
        $text = function_exists('\wp_strip_all_tags') ? \wp_strip_all_tags($text) : (string) preg_replace('@<[^>]*>@', '', (string) $text);

        // 5. Remove shortcodes: [gallery ...], [caption]...[/caption], etc.
        $text = preg_replace('/\[[\w\-_]+(?:\s+[^\]]*)?\](?:.*?\[\/[\w\-_]+\])?/s', ' ', $text);
        // Clean any leftover stray brackets like [shortcode]
        $text = preg_replace('/\[\/?[\w\-_]+.*?\]/', ' ', $text);

        return $text;
    }

    /**
     * Redacts or removes URLs, email addresses, and phone numbers.
     *
     * @param string $text
     * @return string
     */
    public static function remove_pii(string $text): string {
        // 1. Remove URLs (http, https, ftp, www)
        $text = preg_replace('/\b(?:https?|ftp):\/\/[^\s<>()"\']+/i', ' ', $text);
        $text = preg_replace('/\bwww\.[^\s<>()"\']+/i', ' ', $text);

        // 2. Remove email addresses
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', ' ', $text);

        // 3. Remove common phone number patterns (e.g. +1-800-555-1234, (555) 123-4567, 555.123.4567)
        $text = preg_replace('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/', ' ', $text);

        return $text;
    }

    /**
     * Normalizes multiple spaces, tabs, and newlines into single spaces.
     *
     * @param string $text
     * @return string
     */
    public static function normalize_whitespace(string $text): string {
        return preg_replace('/[\r\n\t ]+/', ' ', $text);
    }

    /**
     * Extracts and normalizes minimal category descriptors for provider payload.
     * Retains ONLY term_id and name. Discards all other WP term attributes.
     *
     * @param array $categories Array of WP_Term objects, associative arrays, or [['term_id' => ..., 'name' => ...]]
     * @return array<array{term_id: int, name: string}>
     */
    public static function prepare_taxonomy_payload(array $categories): array {
        $clean_tax = [];
        foreach ($categories as $cat) {
            if (is_object($cat)) {
                $term_id = (int) ($cat->term_id ?? 0);
                $name    = (string) ($cat->name ?? '');
            } elseif (is_array($cat)) {
                $term_id = (int) ($cat['term_id'] ?? $cat['id'] ?? 0);
                $name    = (string) ($cat['name'] ?? '');
            } else {
                continue;
            }

            $name = trim((string) (function_exists('\wp_strip_all_tags') ? \wp_strip_all_tags($name) : preg_replace('@<[^>]*>@', '', (string) $name)));
            if ($term_id > 0 && !empty($name)) {
                $clean_tax[] = [
                    'term_id' => $term_id,
                    'name'    => $name,
                ];
            }
        }

        // Sort deterministically by term_id
        usort($clean_tax, function ($a, $b) {
            return $a['term_id'] <=> $b['term_id'];
        });

        return array_values($clean_tax);
    }

    /**
     * Produces the full minimized, sanitized payload for external submission.
     * Explicitly contains zero post IDs, author IDs, or internal metadata.
     *
     * @param string $title
     * @param string $content
     * @param array  $categories
     * @return array{title: string, content_sample: string, categories: array}
     */
    public static function prepare_payload(string $title, string $content, array $categories): array {
        return [
            'title'          => self::sanitize_title($title),
            'content_sample' => self::sanitize_content($content),
            'categories'     => self::prepare_taxonomy_payload($categories),
        ];
    }
}
