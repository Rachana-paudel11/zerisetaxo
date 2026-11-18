<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * WordPress post data collector for site-specific model training.
 *
 * Reads existing categorized WordPress posts without modifying anything.
 * Provides structured training documents keyed by WordPress term_id.
 *
 * Task 1: AUDIT what data WordPress provides.
 */
class WordPressDataCollector {

    /**
     * Minimum word count for a post to be considered usable for training.
     */
    const MIN_WORD_COUNT = 10;

    /**
     * Collect all categorized posts for training.
     *
     * Returns documents with term_id as canonical category identity.
     *
     * @param array $options {
     *   @type int    $posts_per_page  Max posts to load (-1 = all). Default -1.
     *   @type bool   $include_draft   Include draft posts? Default false.
     *   @type bool   $multi_cat_first_only  For multi-category posts, use first category only. Default true.
     * }
     * @return array{
     *   posts: array<array{term_id: int, name: string, title: string, content: string, post_id: int}>,
     *   stats: array,
     *   category_map: array<int, array{name: string, slug: string, count: int}>
     * }
     */
    public static function collect(array $options = []): array {
        $posts_per_page      = (int) ($options['posts_per_page'] ?? -1);
        $include_draft       = (bool) ($options['include_draft'] ?? false);
        $multi_cat_first_only = (bool) ($options['multi_cat_first_only'] ?? true);

        $statuses = ['publish'];
        if ($include_draft) {
            $statuses[] = 'draft';
        }

        // Fetch categorized posts
        $query_args = [
            'post_type'      => 'post',
            'post_status'    => $statuses,
            'posts_per_page' => $posts_per_page,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];

        $query    = new \WP_Query($query_args);
        $post_ids = $query->posts;

        $documents    = [];
        $skipped      = ['no_categories' => 0, 'too_short' => 0, 'uncategorized' => 0];
        $category_map = [];

        // Get Uncategorized term_id (always skip it)
        $uncategorized = get_category_by_slug('uncategorized');
        $uncategorized_id = $uncategorized ? (int) $uncategorized->term_id : 1;

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            // Get categories for this post
            $terms = get_the_terms($post_id, 'category');
            if (empty($terms) || is_wp_error($terms)) {
                $skipped['no_categories']++;
                continue;
            }

            // Filter out Uncategorized
            $valid_terms = array_filter($terms, function ($t) use ($uncategorized_id) {
                return (int) $t->term_id !== $uncategorized_id;
            });
            $valid_terms = array_values($valid_terms);

            if (empty($valid_terms)) {
                $skipped['uncategorized']++;
                continue;
            }

            // Build clean text
            $title   = trim($post->post_title);
            $content = trim(wp_strip_all_tags($post->post_content));
            $text    = $title . ' ' . $content;

            // Check minimum word count
            if (str_word_count($text) < self::MIN_WORD_COUNT) {
                $skipped['too_short']++;
                continue;
            }

            // Resolve which categories to assign (Task 8: Prefer Yoast/RankMath primary category, else first)
            $selected_term = $valid_terms[0];
            if (function_exists('get_post_meta')) {
                $yoast_id = (int) get_post_meta($post_id, '_yoast_wpseo_primary_category', true);
                if ($yoast_id > 0) {
                    foreach ($valid_terms as $vt) {
                        if ((int) $vt->term_id === $yoast_id) {
                            $selected_term = $vt;
                            break;
                        }
                    }
                } elseif ($rm_id = (int) get_post_meta($post_id, 'rank_math_primary_category', true)) {
                    foreach ($valid_terms as $vt) {
                        if ((int) $vt->term_id === $rm_id) {
                            $selected_term = $vt;
                            break;
                        }
                    }
                }
            }
            $categories_to_use = $multi_cat_first_only ? [$selected_term] : $valid_terms;

            foreach ($categories_to_use as $term) {
                $term_id = (int) $term->term_id;

                // Update category map
                if (!isset($category_map[$term_id])) {
                    $category_map[$term_id] = [
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'description' => $term->description,
                        'parent'      => (int) $term->parent,
                        'count'       => 0,
                    ];
                }
                $category_map[$term_id]['count']++;

                $documents[] = [
                    'post_id' => (int) $post_id,
                    'term_id' => $term_id,
                    'name'    => $term->name,
                    'title'   => $title,
                    'content' => $content,
                ];
            }
        }

        return [
            'posts'        => $documents,
            'category_map' => $category_map,
            'stats'        => [
                'total_posts_queried' => count($post_ids),
                'usable_documents'    => count($documents),
                'skipped'             => $skipped,
                'category_count'      => count($category_map),
                'min_docs_per_cat'    => empty($category_map) ? 0 : min(array_column($category_map, 'count')),
                'max_docs_per_cat'    => empty($category_map) ? 0 : max(array_column($category_map, 'count')),
            ],
        ];
    }

    /**
     * Audit a collected dataset and produce a human-readable diagnostic report.
     *
     * @param array $collected Output from collect()
     * @return array{
     *   has_enough_data: bool,
     *   quality_issues: string[],
     *   recommendations: string[],
     *   per_category: array
     * }
     */
    public static function audit(array $collected): array {
        $category_map = $collected['category_map'];
        $stats        = $collected['stats'];

        $quality_issues    = [];
        $recommendations   = [];
        $per_category      = [];

        foreach ($category_map as $term_id => $info) {
            $count = $info['count'];
            $quality = 'good';
            $notes   = [];

            if ($count === 0) {
                $quality = 'empty';
                $notes[] = 'No usable posts';
            } elseif ($count < 3) {
                $quality = 'critical';
                $notes[] = 'Fewer than 3 posts: predictions will be unreliable';
                $quality_issues[] = "Category '{$info['name']}' has only {$count} post(s)";
            } elseif ($count < 10) {
                $quality = 'low';
                $notes[] = 'Fewer than 10 posts: some categories may be confused';
            } elseif ($count < 30) {
                $quality = 'moderate';
                $notes[] = 'Adequate for basic learning';
            } else {
                $quality = 'good';
                $notes[] = 'Sufficient data for reliable learning';
            }

            $per_category[$term_id] = [
                'name'    => $info['name'],
                'count'   => $count,
                'quality' => $quality,
                'notes'   => $notes,
            ];
        }

        // Overall assessment
        $usable_cats = count(array_filter($category_map, fn($c) => $c['count'] >= 3));
        $total_cats  = count($category_map);

        $has_enough_data = $total_cats >= 2 && $usable_cats >= 2 && $stats['usable_documents'] >= 10;

        if ($total_cats < 2) {
            $quality_issues[] = 'Site has fewer than 2 categories: classification not possible';
            $recommendations[] = 'Add more WordPress categories before training';
        }
        if ($stats['usable_documents'] < 10) {
            $quality_issues[] = 'Fewer than 10 usable posts total';
            $recommendations[] = 'Publish more categorized posts before building a site model';
        }
        if ($usable_cats < $total_cats) {
            $missing = $total_cats - $usable_cats;
            $recommendations[] = "Add more posts to {$missing} category/categories with fewer than 3 posts";
        }
        if (empty($quality_issues)) {
            $recommendations[] = 'Dataset is suitable for site-specific model training';
        }

        return [
            'has_enough_data'  => $has_enough_data,
            'quality_issues'   => $quality_issues,
            'recommendations'  => $recommendations,
            'per_category'     => $per_category,
            'summary'          => [
                'total_categories'   => $total_cats,
                'usable_categories'  => $usable_cats,
                'total_documents'    => $stats['usable_documents'],
                'min_docs_per_cat'   => $stats['min_docs_per_cat'],
                'max_docs_per_cat'   => $stats['max_docs_per_cat'],
            ],
        ];
    }
}
