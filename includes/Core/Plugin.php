<?php
namespace ZeriseTaxo\Core;

use ZeriseTaxo\Services\InferenceClient;
use ZeriseTaxo\REST\PredictController;
use ZeriseTaxo\REST\SuggestionsController;
use ZeriseTaxo\REST\TagPredictController;
use ZeriseTaxo\Admin\Metabox;
use ZeriseTaxo\Admin\SettingsPage;
use ZeriseTaxo\Admin\SuggestionsPage;
use ZeriseTaxo\ML\PhpPredictionEngine;
use ZeriseTaxo\ML\SiteAdaptivePredictionEngine;
use ZeriseTaxo\ML\Site\SiteModelService;

defined('ABSPATH') || exit;

/**
 * Main Plugin Orchestrator.
 */
class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    protected static $instance = null;

    /**
     * Inference client.
     *
     * @var InferenceClient
     */
    protected $client;

    /**
     * REST controller for predictions.
     *
     * @var PredictController
     */
    protected $rest_controller;

    /**
     * REST controller for category suggestions.
     *
     * @var SuggestionsController
     */
    protected $suggestions_controller;

    /**
     * REST controller for tag candidate predictions.
     *
     * @var TagPredictController
     */
    protected $tag_predict_controller;

    /**
     * Admin metabox.
     *
     * @var Metabox
     */
    protected $metabox;

    /**
     * Admin settings page.
     *
     * @var SettingsPage
     */
    protected $settings_page;

    /**
     * Admin category suggestions page.
     *
     * @var SuggestionsPage
     */
    protected $suggestions_page;

    /**
     * Feedback replay service (Phase C).
     *
     * @var FeedbackReplayService
     */
    // Feedback replay service removed in pure PHP-native mode

    /**
     * Get singleton instance.
     *
     * @return Plugin
     */
    public static function get_instance(): Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    protected function __construct() {
        $this->init_services();
        $this->init_hooks();
    }

    /**
     * Prediction engine instance.
     *
     * @var \ZeriseTaxo\ML\PredictionEngineInterface
     */
    protected $prediction_engine;

    /**
     * Initialize services.
     *
     * Engine selection priority (PHP-native is default):
     *   1. If 'zerisetaxo_prediction_engine' option = 'fastapi' → FastApiPredictionEngine (dev/legacy mode)
     *   2. Otherwise → PhpPredictionEngine with lazy loading:
     *      a. models/php/runtime/  (binary float64 — optimized)
     *      b. models/php/global-model.json (legacy JSON — fallback)
     *      c. null engine (graceful fail — editor still works, suggestions unavailable)
     */
    protected function init_services() {
        $this->client = new InferenceClient();

        $plugin_root = defined('ZERISETAXO_PATH')
            ? ZERISETAXO_PATH
            : dirname(__DIR__, 2) . '/';

        // PHP-native runtime: prefer optimized binary float64 runtime, fall back to JSON
        $runtime_dir  = $plugin_root . 'models/php/runtime/';
        $json_path    = $plugin_root . 'models/php/global-model.json';

        if (is_dir($runtime_dir) && (file_exists($runtime_dir . 'metadata_float32.json') || file_exists($runtime_dir . 'metadata_float64.json'))) {
            // Format 2.0.0: binary float64 packed weights (optimized — preferred)
            $global_engine = PhpPredictionEngine::from_file($runtime_dir, true);
        } elseif (file_exists($json_path)) {
            // Format 1.0.0: legacy JSON (compatibility fallback)
            $global_engine = PhpPredictionEngine::from_file($json_path, true);
        } else {
            // No model found: engine is null — predictions will return graceful error
            $global_engine = null;
        }

        // Wrap in SiteAdaptivePredictionEngine for controlled site-specific learning
        $this->prediction_engine = new SiteAdaptivePredictionEngine($global_engine, new SiteModelService());

        $this->rest_controller        = new PredictController($this->client, $this->prediction_engine);
        $this->suggestions_controller = new SuggestionsController($this->client);
        $this->tag_predict_controller = new TagPredictController();
        $this->settings_page          = new SettingsPage($this->client);
        $this->suggestions_page       = new SuggestionsPage();
        $this->metabox                = new Metabox();
    }

    /**
     * Returns the active prediction engine instance.
     *
     * @return \ZeriseTaxo\ML\PredictionEngineInterface
     */
    public function get_prediction_engine(): \ZeriseTaxo\ML\PredictionEngineInterface {
        return $this->prediction_engine;
    }

    /**
     * Hook into WordPress actions.
     */
    protected function init_hooks() {
        // Register REST API routes
        add_action('rest_api_init', [$this->rest_controller, 'register_routes']);
        add_action('rest_api_init', [$this->suggestions_controller, 'register_routes']);
        add_action('rest_api_init', [$this->tag_predict_controller, 'register_routes']);

        // Register Admin Metabox, Settings Page, Suggestions Page, and Posts List Column
        if (is_admin()) {
            $this->metabox->register();
            $this->settings_page->register();
            $this->suggestions_page->register();
            (new \ZeriseTaxo\Admin\PostsListColumn())->register();
        }

        // Register site model hooks for incremental updates and rebuilds
        SiteModelService::register_hooks();

        // Clear legacy FastAPI feedback retry cron if still scheduled in WP
        if (wp_next_scheduled('zerisetaxo_feedback_retry')) {
            wp_clear_scheduled_hook('zerisetaxo_feedback_retry');
        }

        // Site-wide category ordering strategy (Category Order & Taxonomy Terms Order compatibility)
        add_filter('terms_clauses', [$this, 'filter_terms_clauses'], 10, 3);
    }

    /**
     * Filter term query clauses to apply custom category order across WordPress site queries.
     * Compatible with Category Order and Taxonomy Terms Order.
     *
     * @param array $clauses
     * @param array $taxonomies
     * @param array $args
     * @return array
     */
    public function filter_terms_clauses($clauses, $taxonomies, $args) {
        if (!is_array($taxonomies) || !in_array('category', $taxonomies, true)) {
            return $clauses;
        }

        // Only alter clauses when orderby is empty or exactly 'name'
        if (!empty($args['orderby']) && 'name' !== $args['orderby']) {
            return $clauses;
        }

        $order_mode = get_option('zerisetaxo_category_order_mode', 'custom_order');

        if ('custom_order' === $order_mode) {
            $custom_order_str = get_option('zerisetaxo_custom_category_order', '');
            if (!empty($custom_order_str)) {
                $ids = array_filter(array_map('intval', explode(',', $custom_order_str)));
                if (!empty($ids)) {
                    $id_list = implode(',', $ids);
                    $clauses['orderby'] = "ORDER BY CASE WHEN FIELD(t.term_id, {$id_list}) = 0 THEN 999999 ELSE FIELD(t.term_id, {$id_list}) END";
                    $clauses['order']   = 'ASC';
                }
            }
        } elseif ('post_count' === $order_mode) {
            $clauses['orderby'] = 'ORDER BY tt.count';
            $clauses['order']   = 'DESC';
        }

        return $clauses;
    }



    /**
     * Get the inference client.
     *
     * @return InferenceClient
     */
    public function get_client(): InferenceClient {
        return $this->client;
    }
}
