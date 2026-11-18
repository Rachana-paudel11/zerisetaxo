<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * WordPress page content collector for cold-start domain understanding.
 *
 * Pages are NOT treated as labelled training examples for site models.
 * They serve strictly as contextual domain evidence to understand the website's topics.
 */
class WordPressPageCollector {

    const MIN_PAGE_WORD_COUNT = 5;

    /**
     * Collect published pages and return cleaned titles, excerpts, and body text.
     *
     * @param int $limit Maximum number of pages to collect. Default 20.
     * @return array<array{page_id: int, title: string, excerpt: string, text: string}>
     */
    public static function collect(int $limit = 20): array {
        $query_args = [
            'post_type'      => 'page',
            'post_status'    => ['publish'],
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query    = new \WP_Query($query_args);
        $page_ids = $query->posts;

        $pages = [];

        foreach ($page_ids as $page_id) {
            $page = get_post($page_id);
            if (!$page) {
                continue;
            }

            $title   = trim($page->post_title);
            $excerpt = trim($page->post_excerpt);

            // Clean page content (strip nav, footer, scripts, style, HTML noise)
            $clean_content = self::clean_page_content($page->post_content);
            $combined_text = trim($title . ' ' . $excerpt . ' ' . $clean_content);

            if (str_word_count($combined_text) < self::MIN_PAGE_WORD_COUNT) {
                continue;
            }

            $pages[] = [
                'page_id' => (int) $page_id,
                'title'   => $title,
                'excerpt' => $excerpt,
                'text'    => $combined_text,
            ];
        }

        return $pages;
    }

    /**
     * Clean page HTML content removing scripts, style tags, navigation, footer, and boilerplate tags.
     *
     * @param string $content Raw page content.
     * @return string Cleaned plain text.
     */
    public static function clean_page_content(string $content): string {
        if (empty($content)) {
            return '';
        }

        // Remove script and style elements
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);

        // Remove nav, footer, header HTML tags
        $content = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $content);
        $content = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', '', $content);
        $content = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', '', $content);

        // Strip remaining HTML tags
        $text = wp_strip_all_tags($content);

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }
}
