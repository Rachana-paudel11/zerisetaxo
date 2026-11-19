<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * Direct Taxonomy & Semantic Grounding Matcher.
 *
 * Evaluates post title and content against the site's active WordPress categories.
 * Provides high-precision heuristic grounding for new posts and drafts where
 * global statistical TF-IDF might otherwise suffer from vocabulary dilution.
 */
class TaxonomyMatcher {

    /**
     * Curated English taxonomy synonym ontology.
     * Maps common topic themes to synonym keywords.
     *
     * @var array<string, array<string>>
     */
    private static array $topic_ontology = [
        'wordpress'     => ['wordpress', 'wp', 'plugin', 'plugins', 'theme', 'themes', 'woocommerce', 'gutenberg', 'elementor', 'divi', 'shortcode', 'wp-cli', 'wpcli', 'post', 'posts', 'taxonomy', 'taxonomies'],
        'technology'    => ['technology', 'tech', 'software', 'hardware', 'computers', 'coding', 'programming', 'web', 'developer', 'development', 'app', 'apps', 'mobile', 'ai', 'digital', 'cyber', 'hosting', 'server', 'database', 'cloud'],
        'tutorials'     => ['tutorial', 'tutorials', 'how to', 'howto', 'guide', 'guides', 'walkthrough', 'step by step', 'learn', 'beginner', 'beginners', 'lessons', 'course', 'documentation', 'overview', 'masterclass'],
        'seo & marketing' => ['seo', 'marketing', 'search engine', 'ranking', 'rankings', 'traffic', 'analytics', 'backlinks', 'keyword', 'keywords', 'optimization', 'affiliate', 'conversion', 'social media', 'advertising', 'growth'],
        'product reviews' => ['review', 'reviews', 'comparison', 'compared', 'vs', 'versus', 'best', 'top 5', 'top 10', 'pros and cons', 'ratings', 'tested', 'benchmark', 'unboxing', 'worth buying'],
        'tips & tricks' => ['tip', 'tips', 'trick', 'tricks', 'hacks', 'hack', 'advice', 'best practices', 'shortcuts', 'secrets', 'cheat sheet', 'pitfalls'],
        'food'          => ['food', 'recipe', 'recipes', 'cooking', 'baking', 'meal', 'meals', 'breakfast', 'lunch', 'dinner', 'dessert', 'salad', 'healthy eating', 'ingredients', 'snack', 'cuisine', 'culinary'],
        'travel'        => ['travel', 'tourism', 'tourist', 'destinations', 'vacation', 'vacations', 'flight', 'flights', 'hotel', 'hotels', 'trip', 'trips', 'hiking', 'adventure', 'resort', 'itinerary', 'backpacking'],
        'business'      => ['business', 'finance', 'investing', 'money', 'productivity', 'remote work', 'career', 'job', 'jobs', 'startup', 'startups', 'entrepreneur', 'entrepreneurship', 'wealth', 'budget', 'management'],
        'health'        => ['health', 'fitness', 'workout', 'diet', 'wellness', 'mental health', 'exercise', 'weight loss', 'nutrition', 'medicine', 'medical', 'cardio', 'gym', 'routine'],
        'design'        => ['design', 'ui', 'ux', 'web design', 'graphic design', 'graphics', 'photography', 'photo', 'photos', 'camera', 'art', 'creative', 'illustration', 'typography', 'colors', 'figma'],
        'news'          => ['news', 'update', 'updates', 'announcement', 'announcements', 'release', 'breaking', 'event', 'events', 'press', 'report', 'bulletin', 'latest'],
    ];

    /**
     * Stopwords excluded from individual category token matching.
     *
     * @var array<string, bool>
     */
    private static array $stopwords = [
        'and' => true, 'or' => true, 'the' => true, 'a' => true, 'an' => true,
        'in' => true, 'on' => true, 'at' => true, 'to' => true, 'for' => true,
        'of' => true, 'with' => true, 'by' => true, 'from' => true, 'is' => true,
        'are' => true, 'was' => true, 'were' => true, 'how' => true, 'what' => true,
        'your' => true, 'my' => true, 'this' => true, 'that' => true, 'all' => true,
        'new' => true, 'our' => true, 'get' => true, 'into' => true, 'about' => true,
    ];

    /**
     * Score available WordPress categories against post title and content.
     *
     * @param string $title              Post title.
     * @param string $content            Post content.
     * @param array  $categories         List of category names or objects.
     * @param array  $category_details   Optional category details including descriptions.
     * @return array Standardized matching result.
     */
    public static function match(string $title, string $content, array $categories, array $category_details = []): array {
        $title_clean   = mb_strtolower(trim($title));
        $content_clean = mb_strtolower(trim($content));

        if (empty($title_clean) && empty($content_clean)) {
            return ['matched' => false];
        }

        $title_words   = preg_split('/[^\p{L}\p{N}]+/u', $title_clean, -1, PREG_SPLIT_NO_EMPTY);
        $content_words = preg_split('/[^\p{L}\p{N}]+/u', $content_clean, -1, PREG_SPLIT_NO_EMPTY);

        $category_map = [];
        foreach ($categories as $cat) {
            $cat_name = is_array($cat) ? ($cat['name'] ?? '') : (is_object($cat) ? ($cat->name ?? '') : (string) $cat);
            if (empty($cat_name) || mb_strtolower($cat_name) === 'uncategorized') {
                continue;
            }
            $term_id = 0;
            if (is_array($cat) && isset($cat['term_id'])) {
                $term_id = (int) $cat['term_id'];
            } elseif (is_object($cat) && isset($cat->term_id)) {
                $term_id = (int) $cat->term_id;
            }
            $category_map[$cat_name] = $term_id;
        }

        if (empty($category_map)) {
            return ['matched' => false];
        }

        $scores = [];

        foreach ($category_map as $cat_name => $term_id) {
            $cat_lower = mb_strtolower($cat_name);
            $score     = 0.0;

            // 1. Exact full category name match in title (Strongest signal: e.g. "WordPress" in title)
            if (preg_match('/\b' . preg_quote($cat_lower, '/') . '\b/u', $title_clean)) {
                $score += 10.0;
            } elseif (preg_match('/\b' . preg_quote($cat_lower, '/') . '\b/u', $content_clean)) {
                $score += 5.0;
            } elseif (str_contains($title_clean, $cat_lower)) {
                $score += 6.0;
            } elseif (str_contains($content_clean, $cat_lower)) {
                $score += 3.0;
            }

            // 2. Individual words of multi-word categories
            $cat_words = preg_split('/[^\p{L}\p{N}]+/u', $cat_lower, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($cat_words as $cw) {
                if (isset(self::$stopwords[$cw]) || mb_strlen($cw) < 3) {
                    continue;
                }
                // Title word boundary match
                if (preg_match('/\b' . preg_quote($cw, '/') . '\b/u', $title_clean)) {
                    $score += 4.0;
                } elseif (in_array($cw, $title_words, true)) {
                    $score += 3.0;
                } elseif (str_contains($title_clean, $cw)) {
                    $score += 2.0;
                }
                // Content word match
                if (preg_match('/\b' . preg_quote($cw, '/') . '\b/u', $content_clean)) {
                    $score += 1.5;
                } elseif (in_array($cw, $content_words, true)) {
                    $score += 1.0;
                }
            }

            // 3. Synonym ontology lookup
            $synonyms = self::$topic_ontology[$cat_lower] ?? [];
            if (empty($synonyms)) {
                foreach (self::$topic_ontology as $syn_key => $syn_list) {
                    if (str_contains($cat_lower, $syn_key) || str_contains($syn_key, $cat_lower)) {
                        $synonyms = array_merge($synonyms, $syn_list);
                    }
                }
            }

            foreach ($synonyms as $syn) {
                if (preg_match('/\b' . preg_quote($syn, '/') . '\b/u', $title_clean)) {
                    $score += 3.0;
                } elseif (preg_match('/\b' . preg_quote($syn, '/') . '\b/u', $content_clean)) {
                    $score += 1.2;
                }
            }

            // 4. Category description match if present
            if (!empty($category_details)) {
                foreach ($category_details as $cd) {
                    $c_name = is_array($cd) ? ($cd['name'] ?? '') : ($cd->name ?? '');
                    if ($c_name === $cat_name && !empty($cd['description'])) {
                        $desc_words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($cd['description']), -1, PREG_SPLIT_NO_EMPTY);
                        $common = array_intersect($title_words, $desc_words);
                        $score += count($common) * 2.0;
                    }
                }
            }

            if ($score > 0) {
                $scores[$cat_name] = $score;
            }
        }

        if (empty($scores)) {
            return ['matched' => false];
        }

        arsort($scores);

        reset($scores);
        $top_cat   = key($scores);
        $top_score = current($scores);

        // Require meaningful evidence threshold (at least 3.0 points)
        if ($top_score < 3.0) {
            return ['matched' => false, 'scores' => $scores];
        }

        next($scores);
        $ru_cat   = key($scores) ?: '';
        $ru_score = $ru_cat ? current($scores) : 0.0;

        // Calibrate confidence (0.65 to 0.95)
        $confidence = min(0.95, max(0.65, 0.60 + ($top_score * 0.04)));
        $ru_confidence = ($ru_score >= 3.0) ? min(0.85, max(0.50, 0.50 + ($ru_score * 0.03))) : null;
        $margin = $ru_confidence !== null ? round($confidence - $ru_confidence, 3) : 1.0;

        $state = ($confidence >= 0.75 && $margin >= 0.10) ? 'confident' : 'good_match';

        return [
            'matched'              => true,
            'category'             => $top_cat,
            'term_id'              => $category_map[$top_cat] ?? 0,
            'confidence'           => round($confidence, 3),
            'state'                => $state,
            'result_type'          => 'existing',
            'suggested_category'   => '',
            'runner_up_category'   => $ru_cat,
            'runner_up_confidence' => $ru_confidence,
            'runner_up_term_id'    => $ru_cat ? ($category_map[$ru_cat] ?? 0) : 0,
            'margin'               => $margin,
            'is_ood'               => false,
            'decision_source'      => 'php_taxonomy_grounding',
            'scores'               => $scores,
            'reasoning'            => sprintf(
                /* translators: 1: Category name, 2: Relevance score. */
                function_exists('__') ? __('Matched category "%1$s" from post title and content keywords (Relevance score: %2$.1f).', 'zerisetaxo') : 'Matched category "%1$s" from post title and content keywords (Relevance score: %2$.1f).',
                $top_cat,
                $top_score
            ),
        ];
    }
}
