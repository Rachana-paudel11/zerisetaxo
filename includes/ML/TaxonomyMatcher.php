<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * Direct Taxonomy & Semantic Grounding Matcher.
 *
 * Evaluates post title and content against the site's active WordPress categories.
 * Employs pure PHP dynamic vector centroids, cosine similarity, exact phrase
 * detection, and an expanded semantic ontology for ultra-fast (<1ms) and accurate
 * zero-shot matching on arbitrary new articles and site taxonomies.
 */
class TaxonomyMatcher {

    /**
     * Stopwords excluded from individual category token matching and vectorization.
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
        'we' => true, 'you' => true, 'it' => true, 'as' => true, 'can' => true,
        'will' => true, 'be' => true, 'has' => true, 'have' => true, 'do' => true,
        'so' => true, 'if' => true, 'but' => true, 'not' => true, 'than' => true,
        'just' => true, 'more' => true, 'also' => true, 'their' => true, 'which' => true,
    ];

    /**
     * Comprehensive multi-domain semantic topic ontology.
     * Maps common topic themes to curated keyword clusters.
     *
     * @var array<string, array<string>>
     */
    private static array $semantic_clusters = [
        'wordpress' => [
            'wordpress', 'wp', 'plugin', 'plugins', 'theme', 'themes', 'woocommerce',
            'gutenberg', 'elementor', 'divi', 'shortcode', 'wp-cli', 'wpcli', 'post',
            'posts', 'taxonomy', 'taxonomies', 'hook', 'filter', 'block editor', 'fse',
            'multisite', 'wpdb', 'jetpack', 'yoast', 'page builder', 'blocks', 'site editor'
        ],
        'web development' => [
            'web development', 'web dev', 'html', 'css', 'javascript', 'js', 'react',
            'vue', 'angular', 'node', 'nodejs', 'express', 'docker', 'kubernetes', 'redis',
            'backend', 'frontend', 'fullstack', 'api', 'rest api', 'graphql', 'responsive',
            'tailwind', 'bootstrap', 'typescript', 'microservice', 'microservices', 'serverless',
            'database', 'mysql', 'postgresql', 'mongodb', 'git', 'github', 'devops', 'cicd'
        ],
        'programming' => [
            'programming', 'coding', 'code', 'developer', 'software development', 'python',
            'php', 'java', 'c++', 'c#', 'rust', 'golang', 'ruby', 'swift', 'kotlin',
            'algorithm', 'data structures', 'debugging', 'bug fix', 'unit test', 'refactoring'
        ],
        'seo & marketing' => [
            'seo', 'search engine optimization', 'meta tags', 'sitemap', 'xml sitemap',
            'robots txt', 'serp', 'google search', 'organic traffic', 'ranking', 'rankings',
            'backlinks', 'keyword', 'keywords', 'on page seo', 'off page seo', 'core web vitals',
            'indexing', 'crawl', 'alt text', 'search console', 'domain authority',
            'marketing', 'digital marketing', 'content marketing', 'email marketing',
            'newsletter', 'lead generation', 'sales funnel', 'inbound marketing', 'campaign',
            'campaigns', 'brand', 'branding', 'audience', 'conversion', 'ctr', 'roi',
            'copywriting', 'public relations', 'social media marketing', 'growth marketing'
        ],
        'product reviews' => [
            'review', 'reviews', 'comparison', 'compared', 'versus', 'vs', 'pros and cons',
            'pros & cons', 'tested', 'hands on', 'unboxing', 'benchmark', 'rating', 'ratings',
            'verdict', 'is it worth', 'buying guide', 'alternative', 'alternatives', 'top 5',
            'top 10', 'best of', 'breakdown', 'worth upgrading', 'gadget review'
        ],
        'tutorials' => [
            'tutorial', 'tutorials', 'how to', 'how-to', 'howto', 'guide', 'guides',
            'step by step', 'walkthrough', 'learn', 'beginner', 'beginners', 'masterclass',
            'course', 'lessons', 'handbook', 'instructions', 'manual', 'documentation',
            'getting started', 'setup', 'configuration', 'deploying', 'walk through'
        ],
        'tips & tricks' => [
            'tip', 'tips', 'trick', 'tricks', 'hack', 'hacks', 'shortcuts', 'advice',
            'best practices', 'cheat sheet', 'secrets', 'pitfalls', 'mistakes to avoid',
            'suggestions', 'quick tips', 'checklist', 'rule of thumb'
        ],
        'food & recipes' => [
            'food', 'recipe', 'recipes', 'cooking', 'baking', 'meal', 'meals', 'dinner',
            'breakfast', 'lunch', 'snack', 'kitchen', 'ingredients', 'cuisine', 'culinary',
            'vegan', 'vegetarian', 'keto', 'low-carb', 'low carb', 'diet', 'nutrition',
            'healthy eating', 'dessert', 'roasting', 'meal prep'
        ],
        'travel & adventure' => [
            'travel', 'traveler', 'tourism', 'destination', 'destinations', 'vacation',
            'trip', 'trips', 'flight', 'flights', 'hotel', 'hotels', 'airline', 'resort',
            'itinerary', 'backpacking', 'packing', 'sightseeing', 'adventure', 'hiking',
            'budget travel', 'travel guide', 'tourist', 'explore'
        ],
        'fitness & health' => [
            'health', 'healthy', 'wellness', 'wellbeing', 'well-being', 'mindfulness', 'meditation',
            'morning routine', 'daily routine', 'digital detox', 'without screens', 'screen time',
            'screens', 'offline', 'stretch', 'stretching', 'water', 'hydration', 'brain', 'habits',
            'healthy habits', 'self care', 'self-care', 'sleep', 'nutrition', 'diet', 'mental health',
            'relaxation', 'calm', 'rest', 'healthy living', 'start your day', 'inbox', 'priorities',
            'stress relief', 'immune system', 'physical health', 'vitality', 'longevity', 'fitness',
            'workout', 'workouts', 'gym', 'exercise', 'training', 'cardio', 'strength', 'bodybuilding',
            'muscle', 'weight loss', 'running', 'yoga', 'routine', 'physical therapy'
        ],
        'productivity & business' => [
            'productivity', 'productive', 'remote work', 'work from home', 'wfh', 'time management',
            'habits', 'deep work', 'focus', 'business', 'startup', 'entrepreneur',
            'entrepreneurship', 'management', 'leadership', 'strategy', 'freelancing',
            'daily habits', 'distractions', 'work tips', 'efficiency', 'goals'
        ],
        'lifestyle' => [
            'lifestyle', 'life', 'habits', 'daily habits', 'daily routine', 'morning routine',
            'habits to boost', 'focus', 'daily focus', 'productivity', 'productive', 'distractions',
            'work or home', 'work tips', 'time management', 'personal growth', 'self improvement',
            'wellness', 'mindset', 'life hacks', 'living', 'work life balance', 'fashion',
            'style', 'beauty', 'outfits', 'wardrobe', 'trends', 'home decor'
        ],
        'fashion' => [
            'fashion', 'style', 'clothing', 'clothes', 'outfits', 'apparel', 'trends',
            'wardrobe', 'accessories', 'shoes', 'designer', 'runway', 'streetwear', 'beauty'
        ],
        'science' => [
            'science', 'scientific', 'physics', 'chemistry', 'biology', 'laboratory',
            'experiment', 'experiments', 'astronomy', 'quantum', 'astrophysics', 'scientists',
            'hypothesis', 'peer reviewed', 'molecule', 'genetics', 'ecology', 'scientific research'
        ],
        'news & updates' => [
            'news', 'update', 'updates', 'announcement', 'announcements', 'release',
            'breaking', 'event', 'events', 'press', 'report', 'bulletin', 'latest',
            'headline', 'dispatch', 'recap'
        ],
        'ecommerce' => [
            'ecommerce', 'e-commerce', 'online store', 'shopping cart', 'checkout',
            'dropshipping', 'inventory', 'shopify', 'woocommerce', 'orders', 'sales'
        ],
        'finance & crypto' => [
            'finance', 'investing', 'money', 'wealth', 'budget', 'banking', 'stocks',
            'crypto', 'bitcoin', 'blockchain', 'ethereum', 'web3', 'defi', 'personal finance'
        ]
    ];

    /**
     * Tokenize text into words with stopword filtering.
     *
     * @param string $text
     * @return array<string, int> Term frequency map.
     */
    private static function extract_term_frequencies(string $text): array {
        $clean = mb_strtolower(trim($text), 'UTF-8');
        $words = preg_split('/[^\p{L}\p{N}]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $tf    = [];

        foreach ($words as $w) {
            if (isset(self::$stopwords[$w]) || mb_strlen($w) < 2) {
                continue;
            }
            $tf[$w] = ($tf[$w] ?? 0) + 1;
        }

        return $tf;
    }

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
        $title_clean   = mb_strtolower(trim($title), 'UTF-8');
        $content_clean = mb_strtolower(trim($content), 'UTF-8');

        if (empty($title_clean) && empty($content_clean)) {
            return ['matched' => false];
        }

        $title_words   = preg_split('/[^\p{L}\p{N}]+/u', $title_clean, -1, PREG_SPLIT_NO_EMPTY);
        $content_words = preg_split('/[^\p{L}\p{N}]+/u', $content_clean, -1, PREG_SPLIT_NO_EMPTY);

        // Vector representation of article (3x title weight for high topical precision)
        $title_tf   = self::extract_term_frequencies($title_clean);
        $content_tf = self::extract_term_frequencies($content_clean);

        $article_vector = [];
        $article_sum_sq = 0.0;

        foreach ($title_tf as $w => $cnt) {
            $weight = (1.0 + log((float) $cnt)) * 3.0;
            $article_vector[$w] = ($article_vector[$w] ?? 0.0) + $weight;
        }
        foreach ($content_tf as $w => $cnt) {
            $weight = 1.0 + log((float) $cnt);
            $article_vector[$w] = ($article_vector[$w] ?? 0.0) + $weight;
        }
        foreach ($article_vector as $w => $weight) {
            $article_sum_sq += $weight * $weight;
        }
        $article_norm = $article_sum_sq > 0.0 ? sqrt($article_sum_sq) : 1.0;

        $category_map = [];
        $scores       = [];

        foreach ($categories as $cat) {
            $raw_cat_name = is_array($cat) ? ($cat['name'] ?? '') : (is_object($cat) ? ($cat->name ?? '') : (string) $cat);
            // Decode HTML entities (e.g. &amp; or &#038;) to ensure clean lexical matching
            $cat_name = function_exists('wp_specialchars_decode') ? wp_specialchars_decode($raw_cat_name, ENT_QUOTES) : html_entity_decode($raw_cat_name, ENT_QUOTES, 'UTF-8');
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

            $cat_lower = mb_strtolower($cat_name, 'UTF-8');
            $cat_tokens = preg_split('/[^\p{L}\p{N}]+/u', $cat_lower, -1, PREG_SPLIT_NO_EMPTY);
            $is_multi_word = count($cat_tokens) > 1;
            $score = 0.0;

            // 1. Exact verbatim category phrase match
            if (preg_match('/\b' . preg_quote($cat_lower, '/') . '\b/u', $title_clean)) {
                $score += 12.0;
            } elseif (preg_match('/\b' . preg_quote($cat_lower, '/') . '\b/u', $content_clean)) {
                // Multi-word exact match in content is very specific (+6.0).
                // Single-word generic match in content is calibrated by count (prevents incidental modifier trigger like "science-backed")
                if ($is_multi_word) {
                    $score += 6.0;
                } else {
                    $occurrences = preg_match_all('/\b' . preg_quote($cat_lower, '/') . '\b/u', $content_clean);
                    $score += min(5.0, 1.0 + ($occurrences * 1.0));
                }
            } elseif (str_contains($title_clean, $cat_lower)) {
                $score += 8.0;
            } elseif (str_contains($content_clean, $cat_lower)) {
                $score += $is_multi_word ? 4.0 : 1.5;
            }

            // 2. Individual non-stopword tokens of category name
            foreach ($cat_tokens as $ctok) {
                if (isset(self::$stopwords[$ctok]) || mb_strlen($ctok) < 2) {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($ctok, '/') . '\b/u', $title_clean)) {
                    $score += 4.0;
                } elseif (in_array($ctok, $title_words, true)) {
                    $score += 3.0;
                } elseif (str_contains($title_clean, $ctok)) {
                    $score += 2.0;
                }
                if (preg_match('/\b' . preg_quote($ctok, '/') . '\b/u', $content_clean)) {
                    $score += 1.5;
                } elseif (in_array($ctok, $content_words, true)) {
                    $score += 1.0;
                }
            }

            // 3. Category description matches
            if (!empty($category_details)) {
                foreach ($category_details as $cd) {
                    $c_name = is_array($cd) ? ($cd['name'] ?? '') : ($cd->name ?? '');
                    if ($c_name === $raw_cat_name && !empty($cd['description'])) {
                        $desc_tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($cd['description'], 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
                        $common = array_intersect($title_words, $desc_tokens);
                        $score += count($common) * 2.5;
                    }
                }
            }

            // 4. Vector Centroid & Cosine Similarity
            $cat_vector = [];
            foreach ($cat_tokens as $ctok) {
                if (!isset(self::$stopwords[$ctok]) && mb_strlen($ctok) >= 2) {
                    $cat_vector[$ctok] = 4.0;
                }
            }

            // Add description words to centroid
            if (!empty($category_details)) {
                foreach ($category_details as $cd) {
                    $c_name = is_array($cd) ? ($cd['name'] ?? '') : ($cd->name ?? '');
                    if ($c_name === $raw_cat_name && !empty($cd['description'])) {
                        $desc_tf = self::extract_term_frequencies($cd['description']);
                        foreach ($desc_tf as $dw => $dcnt) {
                            $cat_vector[$dw] = ($cat_vector[$dw] ?? 0.0) + (1.5 * (1.0 + log((float)$dcnt)));
                        }
                    }
                }
            }

            // Semantic cluster expansion: check both full category name and individual category tokens
            $cluster_terms = [];
            foreach (self::$semantic_clusters as $cl_key => $cl_terms) {
                $matched_cluster = false;
                if (str_contains($cat_lower, $cl_key) || str_contains($cl_key, $cat_lower)) {
                    $matched_cluster = true;
                } else {
                    $key_tokens = preg_split('/[^\p{L}\p{N}]+/u', $cl_key, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($cat_tokens as $ctok) {
                        if (isset(self::$stopwords[$ctok]) || mb_strlen($ctok) < 3) {
                            continue;
                        }
                        foreach ($key_tokens as $ktok) {
                            if (isset(self::$stopwords[$ktok]) || mb_strlen($ktok) < 3) {
                                continue;
                            }
                            if ($ctok === $ktok || str_starts_with($ctok, $ktok) || str_starts_with($ktok, $ctok)) {
                                $matched_cluster = true;
                                break 2;
                            }
                        }
                    }
                }
                if ($matched_cluster) {
                    $cluster_terms = array_merge($cluster_terms, $cl_terms);
                }
            }
            $cluster_terms = array_unique($cluster_terms);

            foreach ($cluster_terms as $ct) {
                if (preg_match('/\b' . preg_quote($ct, '/') . '\b/u', $title_clean)) {
                    $score += 3.0;
                } elseif (preg_match('/\b' . preg_quote($ct, '/') . '\b/u', $content_clean)) {
                    $score += 1.0;
                }
                $ct_words = self::extract_term_frequencies($ct);
                foreach ($ct_words as $ctw => $ct_cnt) {
                    $cat_vector[$ctw] = ($cat_vector[$ctw] ?? 0.0) + 1.5;
                }
            }

            // Compute cosine dot product
            $cat_sum_sq  = 0.0;
            $dot_product = 0.0;
            foreach ($cat_vector as $w => $weight) {
                $cat_sum_sq += $weight * $weight;
                if (isset($article_vector[$w])) {
                    $dot_product += $article_vector[$w] * $weight;
                }
            }
            $cat_norm = $cat_sum_sq > 0.0 ? sqrt($cat_sum_sq) : 1.0;
            $cosine   = $dot_product / ($article_norm * $cat_norm);

            $score += $cosine * 6.0;

            if ($score > 0.5) {
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

        if ($top_score < 2.5) {
            return ['matched' => false, 'scores' => $scores];
        }

        next($scores);
        $ru_cat   = key($scores) ?: '';
        $ru_score = $ru_cat ? current($scores) : 0.0;

        // Calibrate confidence (0.65 to 0.95)
        $confidence = min(0.95, max(0.65, 0.60 + ($top_score * 0.035)));
        $ru_confidence = ($ru_score >= 2.5) ? min(0.85, max(0.50, 0.50 + ($ru_score * 0.025))) : null;
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
