<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * Site Learning State Detector.
 *
 * Evaluates empirical WordPress content, taxonomy structure, and model readiness
 * to assign the site to a conceptual learning state:
 * - EMPTY:       No useful published posts and no useful published pages.
 * - PAGES_ONLY:  No useful published posts, but published pages exist.
 * - LOW_DATA:    Posts exist, but insufficient trustworthy labelled data for a site model.
 * - TRAINABLE:   Enough categorized posts exist to build/use a site model.
 * - MATURE:      Operational site model exists with mature labelled dataset.
 */
class SiteStateDetector {

    const STATE_EMPTY      = 'EMPTY';
    const STATE_PAGES_ONLY = 'PAGES_ONLY';
    const STATE_LOW_DATA   = 'LOW_DATA';
    const STATE_TRAINABLE  = 'TRAINABLE';
    const STATE_MATURE     = 'MATURE';

    private SiteLearningConfig $config;

    public function __construct(?SiteLearningConfig $config = null) {
        $this->config = $config ?? new SiteLearningConfig();
    }

    /**
     * Static helper for quick state detection.
     */
    public static function detect_state(?array $stats = null): array {
        return (new self())->detect($stats);
    }

    /**
     * Evaluate site data and return structured state description.
     */
    public function detect(?array $stats = null): array {
        if ($stats !== null) {
            $published_posts     = (int) ($stats['published_posts'] ?? 0);
            $uncategorized_posts = (int) ($stats['uncategorized'] ?? 0);
            $labelled_posts      = (int) ($stats['labelled_posts'] ?? max(0, $published_posts - $uncategorized_posts));
            $pages               = (int) ($stats['pages'] ?? 0);
            $categories          = (int) ($stats['categories'] ?? 0);
            $site_model_available = (bool) ($stats['site_model_ready'] ?? $stats['site_model_exists'] ?? false);
            $site_model_state     = $site_model_available ? 'ready' : 'unbuilt';
        } else {
            $published_posts     = self::count_published_posts();
            $uncategorized_posts = self::count_uncategorized_posts();
            $labelled_posts      = max(0, $published_posts - $uncategorized_posts);
            $pages               = self::count_published_pages();
            $categories          = self::count_usable_categories();

            $site_model_service   = new SiteModelService();
            $site_model_available = $site_model_service->is_operational();
            $site_model_state     = $site_model_service->get_readiness_state();
        }

        $min_labelled_training = $this->config->get_min_labelled_posts_for_training();
        $min_categories        = $this->config->get_min_categories_for_training();

        $min_labelled_mature   = $this->config->get_min_labelled_posts_for_mature();

        $state = self::STATE_EMPTY;
        $reason = 'No published posts and no published pages exist on this site.';
        $recommendation = 'Provide site description and topics to generate an initial taxonomy bootstrap.';

        if ($published_posts === 0 && $pages === 0) {
            $state = self::STATE_EMPTY;
            $reason = 'No published posts and no published pages exist on this site.';
            $recommendation = 'Use Cold-Start Bootstrap to generate an initial taxonomy from your site description and main topics.';
        } elseif ($published_posts === 0 && $pages > 0) {
            $state = self::STATE_PAGES_ONLY;
            $reason = sprintf('No published posts exist, but %d published page(s) provide domain context.', $pages);
            $recommendation = 'ZeriseTaxo uses page context to suggest initial taxonomy categories. Review and approve suggestions.';
        } elseif ($labelled_posts < $min_labelled_training || $categories < $min_categories) {
            $state = self::STATE_LOW_DATA;
            $needed_posts = max(1, $min_labelled_training - $labelled_posts);
            $reason = sprintf('Insufficient labelled posts (%d/%d) or categories (%d/%d) for site-model training.', $labelled_posts, $min_labelled_training, $categories, $min_categories);
            $recommendation = sprintf('Add or assign categories to %d more post(s) to enable site-specific ML training.', $needed_posts);
        } elseif ($site_model_available && $labelled_posts >= $min_labelled_mature && $categories >= $min_categories) {
            $state = self::STATE_MATURE;
            $reason = sprintf('Operational site model active with %d labelled posts across %d categories.', $labelled_posts, $categories);
            $recommendation = 'Site-specific model is acting as the primary category predictor.';
        } else {
            $state = self::STATE_TRAINABLE;
            if ($site_model_available) {
                $reason = sprintf('Site model active with %d labelled posts. Training dataset is growing towards maturity.', $labelled_posts);
                $recommendation = 'Site model is active. Continue publishing categorized posts to reach full maturity.';
            } else {
                $reason = sprintf('Enough labelled posts (%d) and categories (%d) exist to train a site model.', $labelled_posts, $categories);
                $recommendation = 'Run site model training/rebuild in Settings to activate your local site classifier.';
            }
        }

        return [
            'state'                => $state,
            'published_posts'      => $published_posts,
            'labelled_posts'       => $labelled_posts,
            'uncategorized_posts'  => $uncategorized_posts,
            'pages'                => $pages,
            'categories'           => $categories,
            'site_model_available' => $site_model_available,
            'site_model_state'     => $site_model_state,
            'reason'               => $reason,
            'recommendation'       => $recommendation,
            'config'               => $this->config->to_array(),
        ];
    }

    public static function count_published_posts(): int {
        $counts = wp_count_posts('post');
        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    public static function count_published_pages(): int {
        $counts = wp_count_posts('page');
        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    public static function count_uncategorized_posts(): int {
        $uncategorized = get_category_by_slug('uncategorized');
        if (!$uncategorized) {
            return 0;
        }

        $query_args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'cat'            => (int) $uncategorized->term_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];

        $query = new \WP_Query($query_args);
        return (int) $query->found_posts;
    }

    public static function count_usable_categories(): int {
        $uncategorized    = get_category_by_slug('uncategorized');
        $uncategorized_id = $uncategorized ? (int) $uncategorized->term_id : 0;

        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return 0;
        }

        if ($uncategorized_id > 0) {
            $terms = array_filter($terms, function ($term) use ($uncategorized_id) {
                $term_id = is_object($term) ? (int) $term->term_id : (int) $term;
                return $term_id !== $uncategorized_id;
            });
        }

        return count($terms);
    }
}
