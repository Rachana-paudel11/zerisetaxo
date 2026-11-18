<?php
namespace ZeriseTaxo\ML\Tags;

use ZeriseTaxo\ML\Site\SiteTokenizer;

defined('ABSPATH') || exit;

/**
 * Tag Candidate Extractor (V1 Bootstrap System - Hardened & Explainable).
 *
 * Extracts, normalizes, ranks, and scores tag candidates from post text
 * without requiring a pre-trained ML classifier.
 *
 * Guarantees:
 * - 100% Read-Only: NEVER creates terms, assigns tags, or alters WordPress DB.
 * - Human in the loop: Output serves strictly as suggestions.
 * - Score semantics: Uses `candidate_score` (0.00 to 1.00), NOT ML confidence or probability.
 * - Quality Gate: Returns [] (empty array) when content lacks meaningful, high-confidence topical keywords.
 * - Non-redundancy: Suppresses redundant single-word sub-components when multi-word phrases exist.
 * - Quality Filtering: Strips boilerplate prefixes/suffixes ("Intro Rachana" -> "Rachana", filters "Click Here", "Page Content", etc.).
 * - Explainability: Returns per-tag reason, source, and match_label.
 */
class TagCandidateExtractor {

    /**
     * Canonical Synonym & Equivalent Variant Normalization Map.
     */
    private static array $SYNONYM_MAP = [
        // Web / Tech Frameworks & Equivalent Variants
        'wordpress'                 => 'WordPress',
        'wp'                        => 'WordPress',
        'javascript'                => 'JavaScript',
        'js'                        => 'JavaScript',
        'react'                     => 'React',
        'reactjs'                   => 'React',
        'react.js'                  => 'React',
        'react js'                  => 'React',
        'gutenberg'                 => 'Gutenberg',
        'gutenberg block'           => 'Gutenberg',
        'gutenberg blocks'          => 'Gutenberg',
        'css'                       => 'CSS',
        'css3'                      => 'CSS',
        'flexbox'                   => 'CSS Grid & Flexbox',
        'css grid'                  => 'CSS Grid',
        'html'                      => 'HTML',
        'html5'                     => 'HTML',
        'php'                       => 'PHP',
        'phpunit'                   => 'PHPUnit',
        'api'                       => 'API',
        'apis'                      => 'API',
        'rest api'                  => 'REST API',
        'rest-api'                  => 'REST API',
        'restful api'               => 'REST API',
        'reflection api'            => 'API',
        'node.js'                   => 'Node.js',
        'nodejs'                    => 'Node.js',
        'next.js'                   => 'Next.js',
        'nextjs'                    => 'Next.js',
        'vue.js'                    => 'Vue.js',
        'vuejs'                     => 'Vue.js',
        'mysql'                     => 'MySQL',
        'sql'                       => 'MySQL',
        'database'                  => 'Database',
        'redux'                     => 'Redux',
        'redux toolkit'             => 'Redux',
        'graphql'                   => 'GraphQL',
        'docker'                    => 'Docker',
        'containerized'             => 'Docker',
        'microservices'             => 'Microservices',
        'server components'         => 'Server Components',
        'machine learning'          => 'Machine Learning',
        'artificial intelligence'   => 'Artificial Intelligence',
        'deep learning'             => 'Deep Learning',
        'openai'                    => 'OpenAI',

        // Personal Finance
        'personal finance'          => 'Personal Finance',
        'budgeting'                 => 'Budgeting',
        'budget'                    => 'Budgeting',
        'savings'                   => 'Savings & Investment',
        'saving'                    => 'Savings & Investment',
        'investment'                => 'Investing',
        'investing'                 => 'Investing',
        'stocks'                    => 'Investing',
        'retirement'                => 'Retirement Planning',
        '401k'                      => 'Retirement Planning',

        // Healthy Living / Wellness
        'healthy living'            => 'Healthy Living',
        'nutrition'                 => 'Nutrition',
        'diet'                      => 'Nutrition',
        'mediterranean diet'        => 'Mediterranean Diet',
        'fitness'                   => 'Fitness & Wellness',
        'wellness'                  => 'Fitness & Wellness',
        'exercise'                  => 'Fitness & Wellness',
        'heart health'              => 'Heart Health',

        // Travel / Tourism
        'travel'                    => 'Travel',
        'tourism'                   => 'Travel & Tourism',
        'vacation'                  => 'Vacations',
        'destinations'              => 'Travel Destinations',
    ];

    /**
     * Generic filler / low-information words excluded from candidate tags.
     */
    private static array $FILLER_WORDS = [
        'guide', 'tutorial', 'article', 'post', 'tip', 'tips', 'way', 'ways',
        'thing', 'things', 'how', 'best', 'simple', 'easy', 'new', 'overview',
        'introduction', 'intro', 'building', 'using', 'create', 'creating', 'learn',
        'understanding', 'exploring', 'explore', 'modern', 'guide to', 'how to',
        'random', 'notes', 'daily', 'miscellaneous', 'thoughts', 'routine', 'routines',
        'errands', 'paperwork', 'evening', 'activity', 'activities', 'walked',
        'neighborhood', 'coffee', 'discussion', 'observations', 'quick',
        'regular', 'around', 'about', 'general', 'wrapping', 'read', 'more',
        'click', 'here', 'this', 'that', 'page', 'content', 'author', 'written',
        'published', 'comment', 'comments', 'share', 'like', 'follow', 'subscribe',
        'welcome', 'summary', 'conclusion', 'section', 'chapter', 'part', 'list',
        'top', 'great', 'fine', 'first', 'second', 'third', 'last', 'final',
        'read more', 'click here', 'this article', 'the best', 'page content',
        'intro to', 'introduction to'
    ];

    /**
     * Extracts and ranks tag candidates from post text with explainability.
     *
     * @param string $content Body content.
     * @param string $title   Post title.
     * @param string $excerpt Post excerpt.
     * @param int    $limit   Max suggestions to return (default 5).
     * @return array Array of candidates with tag_name, slug, candidate_score, score, existing_term_id, reason, source, match_label.
     */
    public static function extract_candidates(string $content, string $title = '', string $excerpt = '', int $limit = 5): array {
        // Enforce max payload size guard (50,000 characters)
        if (function_exists('mb_strlen') && mb_strlen($content, 'UTF-8') > 50000) {
            $content = mb_substr($content, 0, 50000, 'UTF-8');
        } elseif (strlen($content) > 50000) {
            $content = substr($content, 0, 50000);
        }

        // Clean HTML tags safely
        $clean_title   = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($title) : wp_strip_all_tags($title);
        $clean_excerpt = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($excerpt) : wp_strip_all_tags($excerpt);
        $clean_content = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($content) : wp_strip_all_tags($content);

        if (empty(trim($clean_title . ' ' . $clean_content))) {
            return [];
        }

        // Weighted token accumulation and section origin tracking
        $weighted_candidates = [];
        $title_candidates    = [];

        self::process_section_text($clean_title, 3.0, $weighted_candidates, $title_candidates, true);
        self::process_section_text($clean_excerpt, 2.0, $weighted_candidates, $title_candidates, false);
        self::process_section_text($clean_content, 1.0, $weighted_candidates, $title_candidates, false);

        if (empty($weighted_candidates)) {
            return [];
        }

        // Normalization, Filler Phrase Trimming & Quality Gate
        $canonical_scores = [];
        $canonical_sources = [];
        $filler_lookup = array_fill_keys(self::$FILLER_WORDS, true);

        foreach ($weighted_candidates as $raw_phrase => $score) {
            $trimmed_phrase = self::trim_filler_phrase($raw_phrase);
            if (empty($trimmed_phrase)) {
                continue;
            }

            $lower = strtolower($trimmed_phrase);
            if (isset($filler_lookup[$lower]) || strlen($lower) < 3) {
                continue;
            }

            // Skip phrases consisting entirely of filler words
            $phrase_words = explode(' ', $lower);
            $all_filler = true;
            foreach ($phrase_words as $pw) {
                if (!isset($filler_lookup[$pw])) {
                    $all_filler = false;
                    break;
                }
            }
            if ($all_filler) {
                continue;
            }

            // Map to canonical tag name or preserve original Title Case
            if (isset(self::$SYNONYM_MAP[$lower])) {
                $canonical_name = self::$SYNONYM_MAP[$lower];
                $src_type = 'semantic';
            } elseif (isset($title_candidates[$trimmed_phrase]) || isset($title_candidates[$raw_phrase])) {
                $canonical_name = self::format_title_case($trimmed_phrase);
                $src_type = 'title';
            } else {
                $canonical_name = self::format_title_case($trimmed_phrase);
                $src_type = 'content';
            }

            $canonical_lower = strtolower($canonical_name);
            if (isset($filler_lookup[$canonical_lower])) {
                continue;
            }

            if (!isset($canonical_scores[$canonical_name])) {
                $canonical_scores[$canonical_name] = 0.0;
                $canonical_sources[$canonical_name] = $src_type;
            }
            $canonical_scores[$canonical_name] += $score;
        }

        if (empty($canonical_scores)) {
            return [];
        }

        // Sort descending by candidate score
        arsort($canonical_scores);

        // Quality Gate: Filter out low-confidence generic noise
        $max_raw = max($canonical_scores);
        if ($max_raw < 2.5) {
            return [];
        }

        // Candidate Score calculation
        $ranked_candidates = [];
        foreach ($canonical_scores as $name => $raw_score) {
            $candidate_score = round($raw_score / ($raw_score + 3.5), 2);
            if ($candidate_score < 0.40) {
                continue; // Quality Gate
            }
            $ranked_candidates[$name] = $candidate_score;
        }

        if (empty($ranked_candidates)) {
            return [];
        }

        // Redundancy Suppression (e.g. suppress "Heart" if "Heart Health" is present, unless "Heart" is an existing WP tag)
        $final_candidates = [];
        $candidate_names = array_keys($ranked_candidates);

        foreach ($ranked_candidates as $name => $score) {
            $words = explode(' ', $name);
            if (count($words) === 1) {
                $lower_name = strtolower($name);
                $is_canonical = isset(self::$SYNONYM_MAP[$lower_name]) || array_search($name, self::$SYNONYM_MAP, true) !== false;

                // Check if candidate resolves to an existing post_tag term in WP DB
                $is_existing_term = false;
                if (function_exists('get_term_by')) {
                    $slug_check = function_exists('sanitize_title') ? sanitize_title($name) : strtolower(str_replace(' ', '-', $name));
                    $term_obj = get_term_by('name', $name, 'post_tag')
                        ?: get_term_by('slug', $slug_check, 'post_tag')
                        ?: get_term_by('name', $lower_name, 'post_tag');
                    if ($term_obj && !is_wp_error($term_obj)) {
                        $is_existing_term = true;
                    }
                }

                if (!$is_canonical && !$is_existing_term) {
                    $is_redundant_subcomponent = false;
                    foreach ($candidate_names as $other_name) {
                        if ($other_name === $name) continue;
                        $other_words = explode(' ', $other_name);
                        if (count($other_words) > 1 && in_array($lower_name, array_map('strtolower', $other_words), true)) {
                            $is_redundant_subcomponent = true;
                            break;
                        }
                    }
                    if ($is_redundant_subcomponent) {
                        continue;
                    }
                }
            }
            $final_candidates[$name] = $score;
        }

        // Limit results & build explainability payload
        $results = [];
        foreach ($final_candidates as $name => $candidate_score) {
            if (count($results) >= $limit) {
                break;
            }

            $slug = function_exists('sanitize_title') ? sanitize_title($name) : strtolower(str_replace(' ', '-', $name));

            // Check existing term in WordPress
            $existing_term_id = null;
            if (function_exists('get_term_by')) {
                $existing_term = get_term_by('name', $name, 'post_tag')
                    ?: get_term_by('slug', $slug, 'post_tag')
                    ?: get_term_by('name', strtolower($name), 'post_tag');

                if ($existing_term && !is_wp_error($existing_term)) {
                    $existing_term_id = (int) $existing_term->term_id;
                    $name = $existing_term->name; // Preserve exact WP tag casing
                    $slug = $existing_term->slug;
                }
            }

            // Derive plain-language match label
            if ($candidate_score >= 0.80) {
                $match_label = __('Strong match', 'zerisetaxo');
            } elseif ($candidate_score >= 0.60) {
                $match_label = __('Good match', 'zerisetaxo');
            } else {
                $match_label = __('Possible match', 'zerisetaxo');
            }

            // Derive per-tag source & reasoning
            $src_type = $canonical_sources[$name] ?? 'content';
            if ($existing_term_id) {
                $source = 'taxonomy';
                $reason = __('Matches an existing site tag.', 'zerisetaxo');
            } elseif ($src_type === 'title') {
                $source = 'content';
                $reason = __('Prominent topic featured in the post title.', 'zerisetaxo');
            } elseif ($src_type === 'semantic') {
                $source = 'semantic';
                $reason = __('Key domain concept strongly related to the article.', 'zerisetaxo');
            } else {
                $source = 'content';
                $reason = __('Frequently mentioned topic throughout the post content.', 'zerisetaxo');
            }

            $results[] = [
                'tag_name'         => $name,
                'slug'             => $slug,
                'candidate_score'  => $candidate_score,
                'score'            => $candidate_score, // Alias for backward compatibility
                'existing_term_id' => $existing_term_id,
                'reason'           => $reason,
                'source'           => $source,
                'match_label'      => $match_label,
            ];
        }

        return $results;
    }

    /**
     * Strips leading and trailing filler words from candidate phrases (e.g. "Intro Rachana" -> "Rachana").
     *
     * @param string $phrase
     * @return string
     */
    private static function trim_filler_phrase(string $phrase): string {
        $clean = trim($phrase);
        if (empty($clean)) {
            return '';
        }

        $filler_lookup = array_fill_keys(self::$FILLER_WORDS, true);
        $words = explode(' ', $clean);

        // Strip leading filler words (e.g. "Intro", "Introduction", "About")
        while (count($words) > 0 && isset($filler_lookup[strtolower($words[0])])) {
            array_shift($words);
        }

        // Strip trailing filler words (e.g. "Guide", "Tutorial", "Article")
        while (count($words) > 0 && isset($filler_lookup[strtolower($words[count($words) - 1])])) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    /**
     * Tokenizes section text into candidates, applying section weight.
     */
    private static function process_section_text(string $text, float $weight, array &$weighted_candidates, array &$title_candidates, bool $is_title = false): void {
        if (empty(trim($text))) {
            return;
        }

        // Extract TitleCase Proper Noun Phrases (2-3 words, e.g., "Rachana Paudel", "Machine Learning")
        preg_match_all('/\b([A-Z][a-z0-9]+(?:\s+[A-Z][a-z0-9]+){1,2})\b/u', $text, $m_prop);
        if (!empty($m_prop[1])) {
            foreach ($m_prop[1] as $prop_phrase) {
                $trimmed = self::trim_filler_phrase($prop_phrase);
                if (!empty($trimmed) && strlen($trimmed) > 3) {
                    $lower_p = strtolower($trimmed);
                    $weighted_candidates[$trimmed] = ($weighted_candidates[$trimmed] ?? 0.0) + ($weight * 2.2);
                    if ($is_title) {
                        $title_candidates[$trimmed] = true;
                    }
                }
            }
        }

        $tokens = SiteTokenizer::tokenize($text, true);
        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            $token_lower = strtolower($token);

            if (isset(self::$SYNONYM_MAP[$token_lower])) {
                $weighted_candidates[$token_lower] = ($weighted_candidates[$token_lower] ?? 0.0) + ($weight * 2.5);
                if ($is_title) {
                    $title_candidates[$token_lower] = true;
                }
                continue;
            }

            if (strlen($token) < 3 || is_numeric($token)) {
                continue;
            }

            $weighted_candidates[$token] = ($weighted_candidates[$token] ?? 0.0) + $weight;
            if ($is_title) {
                $title_candidates[$token] = true;
            }
        }
    }

    /**
     * Formats string to clean Title Case.
     */
    private static function format_title_case(string $str): string {
        $clean = trim($str);
        if (empty($clean)) {
            return '';
        }
        // Preserve acronyms / uppercase words (like "AI", "API", "CSS")
        if (strtoupper($clean) === $clean && strlen($clean) <= 5) {
            return strtoupper($clean);
        }
        return ucwords(strtolower($clean));
    }
}
