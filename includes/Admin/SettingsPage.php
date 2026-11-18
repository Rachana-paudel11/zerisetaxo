<?php
namespace ZeriseTaxo\Admin;

use ZeriseTaxo\Services\InferenceClient;
use ZeriseTaxo\Database\Schema;
use ZeriseTaxo\Database\FeedbackRepository;

defined('ABSPATH') || exit;

/**
 * Admin Menu and Unified Dashboard controller for ML & AI Taxonomy.
 */
class SettingsPage {

    /**
     * Menu slug.
     */
    const MENU_SLUG = 'zerisetaxo';

    /**
     * Suggestions submenu slug.
     */
    const SUGGESTIONS_SLUG = 'zerisetaxo-suggestions';

    /**
     * Settings submenu slug.
     */
    const SETTINGS_SLUG = 'zerisetaxo-settings';

    /**
     * Overview submenu slug.
     */
    const OVERVIEW_SLUG = 'zerisetaxo-overview';

    /**
     * User Guide submenu slug.
     */
    const GUIDE_SLUG = 'zerisetaxo-guide';

    /**
     * Category Order submenu slug.
     */
    const ORDER_SLUG = 'zerisetaxo-category-order';


    /**
     * Registered admin page hook suffixes.
     *
     * @var array
     */
    protected $page_hooks = [];

    /**
     * Settings tab ownership mapping.
     * Options belong to specific tabs ('general', 'advanced') or are shared ('shared').
     *
     * Rule: ABSENT FROM REQUEST != EMPTY VALUE.
     * When saving one tab, settings belonging exclusively to another tab are preserved.
     */
    const SETTINGS_TAB_MAP = [
        'zerisetaxo_enable_suggestions'       => 'general',
        'zerisetaxo_suggestion_behavior'      => 'general',
        'zerisetaxo_show_alternative'         => 'general',
        'zerisetaxo_allow_new_categories'     => 'general',
        'zerisetaxo_site_learning_enabled'    => 'general',
        'zerisetaxo_llm_privacy_consent'      => 'general',
        'zerisetaxo_llm_daily_limit'          => 'general',
        'zerisetaxo_enable_llm'               => 'shared',
        'zerisetaxo_enable_local_model'       => 'advanced',
        'zerisetaxo_enable_zero_shot'         => 'advanced',
        'zerisetaxo_delete_data_on_uninstall' => 'advanced',
        'zerisetaxo_category_order_mode'      => 'order',
        'zerisetaxo_custom_category_order'    => 'order',
    ];

    /**
     * Inference client.
     *
     * @var InferenceClient
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param InferenceClient $client
     */
    public function __construct(InferenceClient $client) {
        $this->client = $client;
    }

    /**
     * Register hooks.
     */
    public function register() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'handle_legacy_redirect']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('admin_title', [$this, 'filter_admin_title'], 10, 2);
        add_action('wp_ajax_zerisetaxo_rebuild_site_model', [$this, 'ajax_rebuild_site_model']);
        add_action('wp_ajax_zerisetaxo_test_llm_connection', [$this, 'ajax_test_llm_connection']);
        add_action('wp_ajax_zerisetaxo_playground_predict', [$this, 'ajax_playground_predict']);

        // Cross-tab settings persistence guard: intercept options.php updates for absent tabs
        foreach (self::SETTINGS_TAB_MAP as $option => $owning_tab) {
            add_filter("pre_update_option_{$option}", function ($value, $old_value) use ($option) {
                if ($this->should_preserve_absent_option($option)) {
                    return $old_value;
                }
                return $value;
            }, 10, 2);
        }
    }

    /**
     * Get the count of pending category suggestions.
     *
     * @return int
     */
    public function get_pending_count(): int {
        global $wpdb;
        if (empty($wpdb) || !is_object($wpdb)) {
            return 0;
        }
        $cache_key = 'zerisetaxo_pending_suggestions_count';
        $count     = wp_cache_get($cache_key, 'zerisetaxo');
        if (false === $count) {
            $table = Schema::get_table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
            $count = (int) $count;
            wp_cache_set($cache_key, $count, 'zerisetaxo', 60);
        }
        return (int) $count;
    }

    /**
     * Get the count of feedback events not yet synced to FastAPI (Phase C).
     *
     * @return int
     */
    public function get_unsynced_feedback_count(): int {
        return class_exists(FeedbackRepository::class) ? FeedbackRepository::count_unsynced() : 0;
    }

    /**
     * Add dedicated top-level admin menu and submenus.
     */
    public function add_menu_page() {
        $pending_count = $this->get_pending_count();
        $badge = '';

        if ($pending_count > 0) {
            $badge = sprintf(
                ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
                $pending_count,
                number_format_i18n($pending_count)
            );
        }

        // 1. Dedicated Top-Level Menu (Position 5.05: Cleanly placed directly below Posts)
        $top_hook = add_menu_page(
            __('ZeriseTaxo', 'zerisetaxo'),
            __('ZeriseTaxo', 'zerisetaxo'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-category',
            5.05
        );

        // 2. Submenu 1: Settings (First in dropdown)
        $settings_hook = add_submenu_page(
            self::MENU_SLUG,
            __('ZeriseTaxo Settings', 'zerisetaxo'),
            __('Settings', 'zerisetaxo'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_settings_page']
        );

        // 3. Submenu 2: Suggestions (with pending count badge)
        $suggestions_title = __('Suggestions', 'zerisetaxo') . $badge;
        $suggestions_hook = add_submenu_page(
            self::MENU_SLUG,
            __('ZeriseTaxo Suggestions', 'zerisetaxo'),
            $suggestions_title,
            'manage_categories',
            self::SUGGESTIONS_SLUG,
            [$this, 'render_suggestions_page']
        );

        // 4. Submenu 3: Overview (Third in dropdown)
        $dashboard_hook = add_submenu_page(
            self::MENU_SLUG,
            __('ZeriseTaxo Overview', 'zerisetaxo'),
            __('Overview', 'zerisetaxo'),
            'manage_options',
            self::OVERVIEW_SLUG,
            [$this, 'render_dashboard_page']
        );

        // 5. Submenu 4: Category Order (Fourth in dropdown)
        $order_hook = add_submenu_page(
            self::MENU_SLUG,
            __('ZeriseTaxo Category Order', 'zerisetaxo'),
            __('Category Order', 'zerisetaxo'),
            'manage_options',
            self::ORDER_SLUG,
            [$this, 'render_order_page']
        );

        // 6. Submenu 5: User Guide (Fifth in dropdown)
        $guide_hook = add_submenu_page(
            self::MENU_SLUG,
            __('ZeriseTaxo User Guide', 'zerisetaxo'),
            __('User Guide', 'zerisetaxo'),
            'manage_options',
            self::GUIDE_SLUG,
            [$this, 'render_guide_page']
        );

        // Hidden hook for page=zerisetaxo-settings
        $settings_alias_hook = add_submenu_page(
            null,
            __('ZeriseTaxo Settings', 'zerisetaxo'),
            __('Settings', 'zerisetaxo'),
            'manage_options',
            self::SETTINGS_SLUG,
            [$this, 'render_settings_page']
        );

        $this->page_hooks = array_filter([$top_hook, $settings_hook, $suggestions_hook, $dashboard_hook, $order_hook, $guide_hook, $settings_alias_hook]);

        foreach ($this->page_hooks as $hook) {
            add_action("load-{$hook}", [$this, 'init_screen']);
        }
    }

    /**
     * Filter admin document title to remove em-dashes and provide clean browser tab titles.
     *
     * @param string $admin_title
     * @param string $title
     * @return string
     */
    public function filter_admin_title($admin_title = '', $title = '') {
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, $this->page_hooks, true)) {
            $page_name = 'ZeriseTaxo';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only title filter
            $get_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            if (strpos($get_page, 'category-order') !== false) {
                $page_name = __('Category Order | ZeriseTaxo', 'zerisetaxo');
            } elseif (strpos($get_page, 'overview') !== false) {
                $page_name = __('Overview | ZeriseTaxo', 'zerisetaxo');
            } elseif (strpos($get_page, 'guide') !== false) {
                $page_name = __('User Guide | ZeriseTaxo', 'zerisetaxo');
            } else {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin subtab filtering
                $subtab = isset($_GET['subtab']) ? sanitize_key(wp_unslash($_GET['subtab'])) : 'general';
                if ('advanced' === $subtab) {
                    $page_name = __('Advanced Engine Settings | ZeriseTaxo', 'zerisetaxo');
                } elseif ('diagnostics' === $subtab) {
                    $page_name = __('Developer Diagnostics | ZeriseTaxo', 'zerisetaxo');
                } else {
                    $page_name = __('General Settings | ZeriseTaxo', 'zerisetaxo');
                }
            }
            return sprintf('%s &lsaquo; %s', $page_name, get_bloginfo('name'));
        }
        return $admin_title;
    }

    /**
     * Render Dashboard submenu page.
     */
    public function render_dashboard_page() {
        $this->render_page('dashboard');
    }

    /**
     * Render User Guide submenu page.
     */
    public function render_guide_page() {
        $this->render_page('guide');
    }

    /**
     * Render Category Order submenu page.
     */
    public function render_order_page() {
        $this->render_page('category-order');
    }

    /**
     * Render Suggestions submenu page.
     */
    public function render_suggestions_page() {
        $this->render_page('suggestions');
    }

    /**
     * Render Settings submenu page.
     */
    public function render_settings_page() {
        $this->render_page('settings');
    }

    /**
     * Handle legacy redirects for bookmarks pointing to edit.php?page=zerisetaxo.
     */
    public function handle_legacy_redirect() {
        global $pagenow;
        $current_page = !empty($pagenow) ? $pagenow : ($GLOBALS['pagenow'] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for legacy bookmarks.
        if ('edit.php' === $current_page && isset($_GET['page']) && self::MENU_SLUG === sanitize_key(wp_unslash($_GET['page']))) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for legacy bookmarks.
            $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
            $query_args = [];
            $allowed_params = ['paged', 'status', 'taxonomy', 'orderby', 'order', 'subtab'];
            foreach ($allowed_params as $param) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for legacy bookmarks.
                if (isset($_GET[$param])) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for legacy bookmarks.
                    $query_args[$param] = sanitize_text_field(wp_unslash($_GET[$param]));
                }
            }
            if ('suggestions' === $tab) {
                $query_args['page'] = self::SUGGESTIONS_SLUG;
            } elseif ('settings' === $tab) {
                $query_args['page'] = self::SETTINGS_SLUG;
            } else {
                $query_args['page'] = self::MENU_SLUG;
            }

            $redirect_url = add_query_arg($query_args, admin_url('admin.php'));
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Initialize screen options and register list table column filter.
     */
    public function init_screen() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab check
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        // Only show Screen Options if we are actively displaying the Suggestions list table
        if ('suggestions' === $tab) {
            add_filter("manage_{$screen->id}_columns", function () {
                $table = new SuggestionsListTable();
                return $table->get_columns();
            });
        } else {
            add_filter('screen_options_show_screen', '__return_false');
        }
    }

    /**
     * Enqueue JavaScript and styles on this unified admin page.
     *
     * @param string $hook_suffix
     */
    public function enqueue_assets($hook_suffix) {
        $is_plugin_page = in_array($hook_suffix, $this->page_hooks, true)
            || strpos((string) $hook_suffix, self::MENU_SLUG) !== false
            || 'posts_page_' . self::MENU_SLUG === $hook_suffix;

        if (!$is_plugin_page) {
            return;
        }

        wp_enqueue_style(
            'zerisetaxo-admin-views',
            ZERISETAXO_URL . 'admin/css/admin-views.css',
            [],
            ZERISETAXO_VERSION
        );

        wp_enqueue_style(
            'font-awesome-6',
            ZERISETAXO_URL . 'admin/css/font-awesome.min.css',
            [],
            '6.5.1'
        );

        wp_enqueue_script(
            'zerisetaxo-suggestions-js',
            ZERISETAXO_URL . 'admin/js/suggestions.js',
            ['jquery'],
            ZERISETAXO_VERSION,
            true
        );

        wp_localize_script('zerisetaxo-suggestions-js', 'zeriseTaxoSuggestionsSettings', [
            'restUrl' => esc_url_raw(rest_url('zerisetaxo/v1/suggestions/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'i18n'    => [
                'approving'     => __('Approving...', 'zerisetaxo'),
                'rejecting'     => __('Rejecting...', 'zerisetaxo'),
                'merging'       => __('Merging...', 'zerisetaxo'),
                'confirmReject' => __('Are you sure you want to reject this category suggestion?', 'zerisetaxo'),
                'selectTarget'  => __('Please select an existing category to merge into.', 'zerisetaxo'),
                'actionError'   => __('An error occurred while processing the request.', 'zerisetaxo'),
            ],
        ]);

        wp_enqueue_script(
            'zerisetaxo-settings-js',
            ZERISETAXO_URL . 'admin/js/settings.js',
            ['jquery', 'jquery-ui-sortable', 'jquery-touch-punch'],
            ZERISETAXO_VERSION,
            true
        );

        wp_localize_script('zerisetaxo-settings-js', 'zeriseTaxoSettingsData', [
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'bootstrapRestUrl'    => esc_url_raw(rest_url('zerisetaxo/v1/bootstrap/suggest')),
            'bootstrapApproveUrl' => esc_url_raw(rest_url('zerisetaxo/v1/bootstrap/approve-suggestion')),
            'siteStateRestUrl'    => esc_url_raw(rest_url('zerisetaxo/v1/site-learning-state')),
            'restNonce'           => wp_create_nonce('wp_rest'),
            'nonce'               => wp_create_nonce('zerisetaxo_test_connection_nonce'),
            'testLlmNonce'        => wp_create_nonce('zerisetaxo_test_llm_connection_nonce'),
            'playgroundNonce'     => wp_create_nonce('zerisetaxo_playground_nonce'),
            'syncNonce'    => wp_create_nonce('zerisetaxo_sync_site_data_nonce'),
            'rebuildNonce' => wp_create_nonce('zerisetaxo_rebuild_site_model_nonce'),
            'i18n'         => [
                'testing'         => __('Testing...', 'zerisetaxo'),
                'checking'        => __('Checking...', 'zerisetaxo'),
                'connected'       => __('Connected', 'zerisetaxo'),
                'disconnected'    => __('Disconnected', 'zerisetaxo'),
                'setupRequired'   => __('Setup Required', 'zerisetaxo'),
                'testSuccess'     => __('Connection successful!', 'zerisetaxo'),
                'testFailed'      => __('Connection failed', 'zerisetaxo'),
                'syncing'         => __('Synchronizing site data...', 'zerisetaxo'),
                'postsProgress'   => /* translators: 1: Synced post count, 2: Total post count. */
            __('%1$d / %2$d posts', 'zerisetaxo'),
                'syncSuccess'     => __('Site data synchronized', 'zerisetaxo'),
                'lastSyncDone'    => __('Last site sync completed: Just now', 'zerisetaxo'),
                'modelNotBuilt'   => __('Personalized model not built yet', 'zerisetaxo'),
                'syncFailed'      => __('Synchronization failed', 'zerisetaxo'),
                'retry'           => __('Retry', 'zerisetaxo'),
                'syncButton'      => __('Sync Site Data', 'zerisetaxo'),
                'notSynchronized' => __('Not synchronized', 'zerisetaxo'),
                'rebuilding'      => __('Rebuilding model...', 'zerisetaxo'),
                'rebuildSuccess'  => __('Model rebuilt successfully', 'zerisetaxo'),
                'rebuildFailed'   => __('Model rebuild failed', 'zerisetaxo'),
                'rebuildButton'   => __('Rebuild Model', 'zerisetaxo'),
                'needsMoreData'   => __('Site needs more category training data', 'zerisetaxo'),
            ],
        ]);
    }

    /**
     * Register plugin settings and handle custom administrative actions.
     */
    public function register_settings() {
        // Handle "Restore Recommended Defaults"
        if (
            isset($_POST['zerisetaxo_action']) &&
            'restore_defaults' === $_POST['zerisetaxo_action'] &&
            check_admin_referer('zerisetaxo_restore_defaults_nonce', 'zerisetaxo_restore_nonce')
        ) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Unauthorized action.', 'zerisetaxo'));
            }

            update_option('zerisetaxo_enable_suggestions', 1);
            update_option('zerisetaxo_suggestion_behavior', 'automatic');
            update_option('zerisetaxo_show_alternative', 1);
            update_option('zerisetaxo_allow_new_categories', 1);
            update_option('zerisetaxo_enable_local_model', 1);
            update_option('zerisetaxo_enable_zero_shot', 1);
            update_option('zerisetaxo_enable_llm', 1); // Recommended default ON for novel category discovery
            update_option('zerisetaxo_category_order_mode', 'custom_order');
            update_option(\ZeriseTaxo\ML\Site\SiteModelService::OPTION_ENABLED, 0);

            add_settings_error(
                'zerisetaxo_settings',
                'defaults_restored',
                __('Recommended defaults have been restored.', 'zerisetaxo'),
                'updated'
            );
        }

        // Configuration validation before saving options
        if (isset($_POST['option_page']) && 'zerisetaxo_settings' === $_POST['option_page']) {
            $tab = $this->get_submitted_settings_tab();

            $suggestions_on = ('advanced' === $tab)
                ? (bool) get_option('zerisetaxo_enable_suggestions', true)
                : !empty($_POST['zerisetaxo_enable_suggestions']);

            $local_on = ('general' === $tab)
                ? (bool) get_option('zerisetaxo_enable_local_model', true)
                : !empty($_POST['zerisetaxo_enable_local_model']);

            $zs_on = ('general' === $tab)
                ? (bool) get_option('zerisetaxo_enable_zero_shot', true)
                : !empty($_POST['zerisetaxo_enable_zero_shot']);

            $llm_on = !empty($_POST['zerisetaxo_enable_llm']);

            if ($suggestions_on && !$local_on && !$zs_on && !$llm_on) {
                add_settings_error(
                    'zerisetaxo_settings',
                    'all_prediction_sources_disabled',
                    __('At least one prediction method must remain enabled.', 'zerisetaxo'),
                    'error'
                );
                // Keep local model enabled to prevent completely breaking prediction
                $_POST['zerisetaxo_enable_local_model'] = 1;
            }

            // Secure LLM API key handling via LlmCredentialStore
            if (current_user_can('manage_options')) {
                if (!empty($_POST['zerisetaxo_clear_llm_api_key'])) {
                    \ZeriseTaxo\ML\LLM\LlmCredentialStore::delete_api_key('gemini');
                } elseif (isset($_POST['zerisetaxo_llm_api_key'])) {
                    $raw_key = trim(sanitize_text_field(wp_unslash($_POST['zerisetaxo_llm_api_key'])));
                    if (!empty($raw_key) && strpos($raw_key, '••••') === false) {
                        \ZeriseTaxo\ML\LLM\LlmCredentialStore::save_api_key($raw_key, 'gemini');
                    }
                }
            }
        }

        register_setting('zerisetaxo_settings', 'zerisetaxo_enable_tag_suggestions', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_enable_tag_suggestions'],
            'default'           => true,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_tag_suggestion_limit', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_tag_suggestion_limit'],
            'default'           => 5,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_min_posts_training', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_min_posts_training'],
            'default'           => 10,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_min_categories_training', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_min_categories_training'],
            'default'           => 2,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_confidence_threshold', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_confidence_threshold'],
            'default'           => 75,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_ambiguity_margin', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_ambiguity_margin'],
            'default'           => 12,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_enable_suggestions', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_enable_suggestions'],
            'default'           => true,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_suggestion_behavior', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_suggestion_behavior'],
            'default'           => 'manual',
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_show_alternative', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_show_alternative'],
            'default'           => true,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_allow_new_categories', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_allow_new_categories'],
            'default'           => true,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_category_order_mode', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_category_order_mode'],
            'default'           => 'custom_order',
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_custom_category_order', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_custom_category_order'],
            'default'           => '',
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_enable_local_model', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_enable_local_model'],
            'default'           => true,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_enable_zero_shot', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_enable_zero_shot'],
            'default'           => true,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_enable_llm', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_enable_llm'],
            'default'           => false, // Stable V1 privacy default OFF
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_delete_data_on_uninstall', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_delete_data_on_uninstall'],
            'default'           => false,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_site_learning_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_site_learning_enabled'],
            'default'           => false,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_llm_privacy_consent', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_llm_privacy_consent'],
            'default'           => false,
        ]);

        register_setting('zerisetaxo_settings', 'zerisetaxo_llm_daily_limit', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_llm_daily_limit'],
            'default'           => 100,
        ]);
    }

    /**
     * Determine which settings subtab was submitted in the current request, if any.
     *
     * @return string|null 'general', 'advanced', or null if not an admin settings page submission.
     */
    public function get_submitted_settings_tab(): ?string {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Form submission tab detector during settings processing where nonce is verified by options.php
        if (!isset($_POST['option_page']) || 'zerisetaxo_settings' !== wp_unslash($_POST['option_page'])) {
            return null;
        }

        if (isset($_POST['zerisetaxo_settings_tab'])) {
            $tab = sanitize_key((string) wp_unslash($_POST['zerisetaxo_settings_tab']));
            if (in_array($tab, ['general', 'advanced', 'order'], true)) {
                return $tab;
            }
        }

        // Fallback inference from fields present in POST
        $has_general_fields = isset($_POST['zerisetaxo_suggestion_behavior'])
            || array_key_exists('zerisetaxo_enable_suggestions', $_POST)
            || array_key_exists('zerisetaxo_show_alternative', $_POST)
            || array_key_exists('zerisetaxo_allow_new_categories', $_POST)
            || array_key_exists('zerisetaxo_site_learning_enabled', $_POST);

        $has_advanced_fields = array_key_exists('zerisetaxo_service_url', $_POST)
            || array_key_exists('zerisetaxo_api_token', $_POST)
            || array_key_exists('zerisetaxo_clear_api_token', $_POST)
            || array_key_exists('zerisetaxo_enable_local_model', $_POST)
            || array_key_exists('zerisetaxo_enable_zero_shot', $_POST)
            || array_key_exists('zerisetaxo_delete_data_on_uninstall', $_POST);

        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

        if ($has_general_fields && !$has_advanced_fields) {
            return 'general';
        }
        if ($has_advanced_fields && !$has_general_fields) {
            return 'advanced';
        }

        return null;
    }

    /**
     * Check if an option should preserve its existing value because its owning tab was not submitted.
     * Rule: ABSENT FROM REQUEST != EMPTY VALUE.
     *
     * @param string $option_name
     * @return bool True if the option was absent because another tab was submitted.
     */
    public function should_preserve_absent_option(string $option_name): bool {
        $submitted_tab = $this->get_submitted_settings_tab();
        if ($submitted_tab === null) {
            // Not an admin settings page form submission (e.g. programmatic update_option)
            return false;
        }

        $owning_tab = self::SETTINGS_TAB_MAP[$option_name] ?? 'shared';

        // If the setting belongs to a specific tab that does not match the submitted tab,
        // it was not rendered or submitted.
        if ('shared' !== $owning_tab && $owning_tab !== $submitted_tab) {
            return true;
        }

        // Shared settings (such as enable_llm) are rendered on General and Advanced, but NOT on Order tab
        if ('shared' === $owning_tab && 'order' === $submitted_tab) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize Service Endpoint URL.
     *
     * ABSENT FROM REQUEST != EMPTY VALUE:
     * - If saving from General or another tab where service_url was not rendered,
     *   preserve the existing saved value.
     * - If defined via constant, do not allow database override.
     * - If submitted on Advanced tab as empty string, clear it (explicit clear).
     *
     * @param mixed $input
     * @return string
     */
    public function sanitize_service_url($input): string {
        if (!current_user_can('manage_options')) {
            return (string) get_option(InferenceClient::OPTION_SERVICE_URL, '');
        }

        // If defined via constant, do not allow database override
        if ($this->client->is_url_from_constant()) {
            return (string) get_option(InferenceClient::OPTION_SERVICE_URL, '');
        }

        // If absent because another tab was submitted, preserve existing value
        if ($this->should_preserve_absent_option('zerisetaxo_service_url')) {
            return (string) get_option(InferenceClient::OPTION_SERVICE_URL, '');
        }

        $tab = $this->get_submitted_settings_tab();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
        if ($tab !== null && !array_key_exists('zerisetaxo_service_url', $_POST)) {
            return (string) get_option(InferenceClient::OPTION_SERVICE_URL, '');
        }

        $trimmed = is_string($input) ? trim($input) : '';
        if ($trimmed === '') {
            return '';
        }

        return esc_url_raw($trimmed);
    }

    /**
     * Sanitize the API token setting on save (Phase D).
     *
     * - Administrator-only capability check.
     * - Preserves existing token if saving General tab.
     * - Preserves existing token if input is empty and no clear request was made.
     * - Clears token if explicit clear checkbox is submitted.
     * - Ignores submission if constant ML_WP_TAXONOMY_API_TOKEN is defined.
     * - Disables autoload for the option.
     *
     * @param mixed $input
     * @return string
     */
    public function sanitize_api_token($input): string {
        if (!current_user_can('manage_options')) {
            return (string) get_option(InferenceClient::OPTION_API_TOKEN, '');
        }

        // If defined via constant, do not allow database override
        $const_token = defined('ZERISETAXO_API_TOKEN') ? constant('ZERISETAXO_API_TOKEN') : (defined('ML_WP_TAXONOMY_API_TOKEN') ? constant('ML_WP_TAXONOMY_API_TOKEN') : null);
        if (!empty($const_token)) {
            return (string) get_option(InferenceClient::OPTION_API_TOKEN, '');
        }

        // If absent because another tab was submitted, preserve existing token
        if ($this->should_preserve_absent_option('zerisetaxo_api_token')) {
            return (string) get_option(InferenceClient::OPTION_API_TOKEN, '');
        }

        // Explicit removal requested
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
        if (!empty($_POST['zerisetaxo_clear_api_token'])) {
            return '';
        }

        $trimmed = is_string($input) ? trim($input) : '';

        // If field was left blank, keep the currently stored token
        if ($trimmed === '') {
            return (string) get_option(InferenceClient::OPTION_API_TOKEN, '');
        }

        // Sanitize token characters (base64 URL-safe, pipes, and alphanumeric: ^[a-zA-Z0-9_\-\|=]+$)
        $clean_token = preg_replace('/[^a-zA-Z0-9_\-\|\=]/', '', $trimmed);

        return $clean_token;
    }

    /**
     * Sanitize enable_suggestions boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_enable_suggestions($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_enable_suggestions')) {
            return (bool) get_option('zerisetaxo_enable_suggestions', true);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('general' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_enable_suggestions']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize suggestion_behavior string.
     *
     * @param mixed $input
     * @return string
     */
    public function sanitize_suggestion_behavior($input): string {
        if ($this->should_preserve_absent_option('zerisetaxo_suggestion_behavior')) {
            return (string) get_option('zerisetaxo_suggestion_behavior', 'automatic');
        }

        $val = is_string($input) ? $input : 'automatic';
        return in_array($val, ['manual', 'automatic'], true) ? $val : 'automatic';
    }

    /**
     * Sanitize show_alternative boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_show_alternative($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_show_alternative')) {
            return (bool) get_option('zerisetaxo_show_alternative', true);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('general' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_show_alternative']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize allow_new_categories boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_allow_new_categories($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_allow_new_categories')) {
            return (bool) get_option('zerisetaxo_allow_new_categories', true);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('general' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_allow_new_categories']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize category_order_mode string.
     *
     * @param mixed $input
     * @return string
     */
    public function sanitize_category_order_mode($input): string {
        if ($this->should_preserve_absent_option('zerisetaxo_category_order_mode')) {
            return (string) get_option('zerisetaxo_category_order_mode', 'custom_order');
        }

        $valid_modes = ['confidence', 'post_count', 'custom_order'];
        $clean = sanitize_key($input);
        return in_array($clean, $valid_modes, true) ? $clean : 'custom_order';
    }

    /**
     * Sanitize custom_category_order string (comma-separated term IDs).
     *
     * @param mixed $input
     * @return string
     */
    public function sanitize_custom_category_order($input): string {
        if ($this->should_preserve_absent_option('zerisetaxo_custom_category_order')) {
            return (string) get_option('zerisetaxo_custom_category_order', '');
        }

        if (empty($input)) {
            return '';
        }

        $ids = array_filter(array_map('intval', explode(',', sanitize_text_field($input))));
        return implode(',', $ids);
    }


    /**
     * Sanitize enable_local_model boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_enable_local_model($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_enable_local_model')) {
            return (bool) get_option('zerisetaxo_enable_local_model', true);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('advanced' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_enable_local_model']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize enable_zero_shot boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_enable_zero_shot($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_enable_zero_shot')) {
            return (bool) get_option('zerisetaxo_enable_zero_shot', true);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('advanced' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_enable_zero_shot']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize enable_llm boolean (Shared on both General and Advanced).
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_enable_llm($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_enable_llm')) {
            return (bool) get_option('zerisetaxo_enable_llm', false);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('general' === $tab || 'advanced' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_enable_llm']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize site_learning_enabled boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_site_learning_enabled($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_site_learning_enabled')) {
            return (bool) get_option(\ZeriseTaxo\ML\Site\SiteModelService::OPTION_ENABLED, false);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('general' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_site_learning_enabled']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize llm_privacy_consent boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_llm_privacy_consent($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_llm_privacy_consent')) {
            return (bool) get_option('zerisetaxo_llm_privacy_consent', false);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('general' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_llm_privacy_consent']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize delete_data_on_uninstall boolean.
     *
     * @param mixed $input
     * @return bool
     */
    public function sanitize_delete_data_on_uninstall($input): bool {
        if ($this->should_preserve_absent_option('zerisetaxo_delete_data_on_uninstall')) {
            return (bool) get_option('zerisetaxo_delete_data_on_uninstall', false);
        }

        $tab = $this->get_submitted_settings_tab();
        if ('advanced' === $tab) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by options.php prior to invoking sanitize callback.
            return !empty($_POST['zerisetaxo_delete_data_on_uninstall']);
        }

        return function_exists('rest_sanitize_boolean') ? rest_sanitize_boolean($input) : !empty($input);
    }

    /**
     * Sanitize prediction_engine string ('php' or 'fastapi').
     *
     * @param mixed $input
     * @return string
     */
    public function sanitize_prediction_engine($input): string {
        if ($this->should_preserve_absent_option('zerisetaxo_prediction_engine')) {
            return (string) get_option('zerisetaxo_prediction_engine', 'php');
        }

        $tab = $this->get_submitted_settings_tab();
        if ('advanced' === $tab) {
            if (empty($input)) {
                return (string) get_option('zerisetaxo_prediction_engine', 'php');
            }
            return in_array($input, ['php', 'fastapi'], true) ? $input : 'php';
        }

        return (string) get_option('zerisetaxo_prediction_engine', 'php');
    }

    /**
     * Sanitize LLM daily limit integer.
     *
     * Clamps to 1-5000, and turns 0 or negative into 100.
     *
     * @param mixed $input
     * @return int
     */
    public function sanitize_llm_daily_limit($input): int {
        if ($this->should_preserve_absent_option('zerisetaxo_llm_daily_limit')) {
            return (int) get_option('zerisetaxo_llm_daily_limit', 100);
        }

        $val = (int) $input;
        if ($val <= 0) {
            return 100;
        }

        return max(1, min(5000, $val));
    }

    /**
     * Determine if current settings match the recommended configuration defaults.
     *
     * @return bool
     */
    public static function is_recommended_config(): bool {
        $local = (bool) get_option('zerisetaxo_enable_local_model', true);
        $zs    = (bool) get_option('zerisetaxo_enable_zero_shot', true);
        $llm   = (bool) get_option('zerisetaxo_enable_llm', true);
        $beh   = get_option('zerisetaxo_suggestion_behavior', 'automatic');
        $alt   = (bool) get_option('zerisetaxo_show_alternative', true);
        $new   = (bool) get_option('zerisetaxo_allow_new_categories', true);

        return ($local === true && $zs === true && $llm === true && $beh === 'automatic' && $alt === true && $new === true);
    }

    /**
     * AJAX handler to test connection to the inference microservice.
     */
    public function ajax_test_connection() {
        check_ajax_referer('zerisetaxo_test_connection_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'connected'    => false,
                'is_connected' => false,
                'status'       => 'error',
                'message'      => __('Unauthorized action.', 'zerisetaxo'),
                'classes'      => [],
            ], 403);
        }

        // If service_url is omitted from $_POST (e.g. Test Connection clicked from General tab),
        // pass null so the client tests the canonical service URL.
        $service_url = null;
        if (isset($_POST['service_url'])) {
            $raw = sanitize_text_field(wp_unslash($_POST['service_url']));
            $service_url = ($raw !== '') ? esc_url_raw($raw) : '';
        }

        $result = $this->client->test_connection($service_url);

        if (!empty($result['is_connected'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler to test LLM provider connection (Google Gemini).
     */

    /**
     * AJAX handler for Instant Test Playground prediction.
     */
    public function ajax_playground_predict() {
        check_ajax_referer('zerisetaxo_playground_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error([
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $title   = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $content = isset($_POST['content']) ? wp_strip_all_tags(wp_unslash($_POST['content'])) : '';

        if (empty($title) && empty($content)) {
            wp_send_json_error([
                'message' => __('Please provide a title or content excerpt to analyze.', 'zerisetaxo'),
            ]);
        }

        $combined_text = trim($title . "

" . $content);
        $engine        = \ZeriseTaxo\Core\Plugin::get_instance()->get_prediction_engine();
        $cat_res       = $engine->predict_category($combined_text, ['title' => $title]);

        $candidates = \ZeriseTaxo\ML\Tags\TagCandidateExtractor::extract_candidates($content, $title, '', 5);
        $tag_names  = [];
        if (!empty($candidates) && is_array($candidates)) {
            foreach ($candidates as $cand) {
                if (is_array($cand) && !empty($cand['name'])) {
                    $tag_names[] = $cand['name'];
                } elseif (is_string($cand) && !empty($cand)) {
                    $tag_names[] = $cand;
                }
            }
        }

        $source = $cat_res['decision_source'] ?? 'local_engine';
        $is_ai  = ('llm_gemini' === $source || 'llm' === $source);

        wp_send_json_success([
            'category'        => !empty($cat_res['category']) ? $cat_res['category'] : (!empty($cat_res['suggested_category']) ? $cat_res['suggested_category'] : __('General', 'zerisetaxo')),
            'confidence'      => isset($cat_res['confidence']) ? round(((float) $cat_res['confidence']) * 100, 1) : 85.0,
            'state'           => $cat_res['state'] ?? 'confident',
            'decision_source' => $source,
            'is_ai'           => $is_ai,
            'tier_name'       => $is_ai ? __('Google Gemini AI', 'zerisetaxo') : __('Local Machine Learning', 'zerisetaxo'),
            'reasoning'       => !empty($cat_res['reasoning']) ? $cat_res['reasoning'] : __('Identified strong semantic keywords from title and content.', 'zerisetaxo'),
            'tags'            => array_values(array_unique($tag_names)),
        ]);
    }

    public function ajax_test_llm_connection() {
        check_ajax_referer('zerisetaxo_test_llm_connection_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'success' => false,
                'status'  => 'Unauthorized',
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $provider = new \ZeriseTaxo\ML\LLM\GeminiProvider();
        $result   = $provider->test_connection();

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler to start snapshot synchronization.
     */
    public function ajax_sync_start() {
        check_ajax_referer('zerisetaxo_sync_site_data_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $result = $this->sync_service->start_sync(get_current_user_id());
        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler to process and upload a batch of posts.
     */
    public function ajax_sync_batch() {
        check_ajax_referer('zerisetaxo_sync_site_data_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $batch_index = isset($_POST['batch_index']) ? (int) $_POST['batch_index'] : 0;
        $result      = $this->sync_service->process_batch(get_current_user_id(), $batch_index);
        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler to commit the synchronization snapshot.
     */
    public function ajax_sync_commit() {
        check_ajax_referer('zerisetaxo_sync_site_data_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $result = $this->sync_service->commit_sync(get_current_user_id());
        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler to cancel an active synchronization session.
     */
    public function ajax_sync_cancel() {
        check_ajax_referer('zerisetaxo_sync_site_data_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $this->sync_service->cancel_sync(get_current_user_id());
        wp_send_json_success(['status' => 'cancelled']);
    }

    /**
     * AJAX handler to rebuild the site-specific ML model.
     */
    public function ajax_rebuild_site_model() {
        check_ajax_referer('zerisetaxo_rebuild_site_model_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $engine_mode = get_option('zerisetaxo_prediction_engine', 'php');
        if ('fastapi' !== $engine_mode) {
            $site_service = new \ZeriseTaxo\ML\Site\SiteModelService();
            $result = $site_service->train_from_site();
            if (empty($result['success'])) {
                wp_send_json_error([
                    'message' => $result['message'] ?? __('Failed to build site model.', 'zerisetaxo'),
                    'state'   => $result['state'] ?? 'not_ready',
                ], 400);
            }
            // Auto-enable site learning when administrator explicitly builds the model successfully
            update_option(\ZeriseTaxo\ML\Site\SiteModelService::OPTION_ENABLED, true);

            wp_send_json_success([
                'status'        => $result['state'],
                'version'       => sprintf(/* translators: 1: Number of categories, 2: Number of posts. */
            __('%1$d categories, %2$d posts', 'zerisetaxo'), $result['class_count'], $result['training_docs']),
                'training_docs' => $result['training_docs'],
                'class_count'   => $result['class_count'],
                'message'       => $result['message'],
            ]);
        }

        $result = $this->sync_service->rebuild_model();
        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler: retry unsynced feedback events (Phase C).
     *
     * Processes a bounded batch of unsynced feedback rows using the original
     * event_id for FastAPI-side idempotency. Requires administrator capability.
     * API credentials remain server-side.
     */
    public function ajax_retry_feedback() {
        check_ajax_referer('zerisetaxo_retry_feedback_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized action.', 'zerisetaxo'),
            ], 403);
        }

        $service = $this->get_replay_service();
        if (!$service) {
            wp_send_json_error([
                'message' => __('Feedback replay service is unavailable.', 'zerisetaxo'),
            ], 500);
        }

        $result  = $service->replay_all();
        $pending = class_exists(FeedbackRepository::class) ? FeedbackRepository::count_unsynced() : 0;

        wp_send_json_success([
            'processed'        => $result['processed'],
            'synced'           => $result['synced'],
            'failed'           => $result['failed'],
            'rate_limited'     => $result['skipped_429'],
            'remaining_unsynced' => $pending,
            'message'          => sprintf(
                /* translators: %1$d: synced, %2$d: failed, %3$d: remaining */
                __('Retry complete: %1$d synced, %2$d failed, %3$d still pending.', 'zerisetaxo'),
                $result['synced'],
                $result['failed'],
                $pending
            ),
        ]);
    }

    /**
     * Render the unified admin dashboard page with tabs.
     *
     * @param string|null $explicit_view Optional explicit view ('dashboard', 'suggestions', 'settings')
     */
    public function render_page(?string $explicit_view = null) {
        if ($explicit_view !== null) {
            $active_tab = $explicit_view;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab navigation.
        } elseif (isset($_GET['tab']) && in_array(sanitize_key(wp_unslash($_GET['tab'])), ['dashboard', 'suggestions', 'settings', 'guide'], true)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab navigation.
            $active_tab = sanitize_key(wp_unslash($_GET['tab']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab navigation.
        } elseif (isset($_GET['page'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab navigation.
            $raw_page = sanitize_key(wp_unslash($_GET['page']));
            if (self::SUGGESTIONS_SLUG === $raw_page) {
                $active_tab = 'suggestions';
            } elseif (self::OVERVIEW_SLUG === $raw_page) {
                $active_tab = 'dashboard';
            } elseif (self::GUIDE_SLUG === $raw_page) {
                $active_tab = 'guide';
            } elseif (self::ORDER_SLUG === $raw_page) {
                $active_tab = 'category-order';
            } else {
                $active_tab = 'settings';
            }
        } else {
            $active_tab = 'settings';
        }

        // Capability security check
        if ('suggestions' === $active_tab) {
            if (function_exists('current_user_can') && !current_user_can('manage_categories') && !current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'zerisetaxo'));
            }
        } else {
            if (function_exists('current_user_can') && !current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'zerisetaxo'));
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin subtab navigation.
        $raw_subtab    = isset($_GET['subtab']) ? sanitize_key(wp_unslash($_GET['subtab'])) : 'general';
        $subtab        = in_array($raw_subtab, ['general', 'advanced', 'diagnostics'], true) ? $raw_subtab : 'general';
        $pending_count = $this->get_pending_count();

        $list_table        = null;
        $service_url       = '';
        $health            = null;
        $is_healthy        = false;
        $site_intel        = null;
        $service_state     = null;
        $total_posts       = 0;
        $total_categories  = 0;
        $site_id           = '';
        $is_recommended    = true;

        if ('suggestions' === $active_tab) {
            $list_table = new SuggestionsListTable();
            $list_table->prepare_items();
        } else {
            // Prepared for both 'settings' and 'dashboard'
            $engine_mode         = get_option('zerisetaxo_prediction_engine', 'php');
            $service_url         = $this->client->get_base_url();
            $is_url_from_const   = $this->client->is_url_from_constant();
            $has_api_token       = $this->client->has_api_token();
            $is_token_from_const = $this->client->is_token_from_constant();
            $site_id             = $this->client->get_site_id();

            if ('fastapi' === $engine_mode) {
                $service_state       = $this->client->get_inference_service_state($site_id);

                $is_healthy       = (bool) ($service_state['is_connected'] ?? false);
                $service_status   = sanitize_key($service_state['status'] ?? 'disconnected');
                $service_label    = sanitize_text_field($service_state['status_label'] ?? __('Unavailable', 'zerisetaxo'));
                $service_message  = sanitize_text_field($service_state['message'] ?? '');
                $last_health_check = (int) ($service_state['last_checked'] ?? 0);
                $last_success_conn = (int) ($service_state['last_success'] ?? 0);
                $last_error_reason = sanitize_text_field($service_state['last_error'] ?? '');
                $site_model       = is_array($service_state['site_model'] ?? null) ? $service_state['site_model'] : [];

                // Normalized view-models guaranteed to never be WP_Error objects
                $site_intel = [
                    'site_model_available' => !empty($site_model['available']),
                    'status'               => sanitize_text_field($site_model['status'] ?? __('Unavailable', 'zerisetaxo')),
                    'active_version'       => sanitize_text_field($site_model['active_version'] ?? 'None'),
                    'accuracy'             => $site_model['accuracy'] ?? null,
                    'macro_f1'             => $site_model['macro_f1'] ?? null,
                    'reason'               => sanitize_text_field($site_model['reason'] ?? ''),
                ];

                $health = [
                    'status'  => $is_healthy ? 'healthy' : sanitize_text_field($service_state['status'] ?? 'disconnected'),
                    'classes' => is_array($service_state['classes'] ?? null) ? $service_state['classes'] : [],
                ];
            } else {
                // PHP-Native production engine (default)
                $plugin_root = defined('ZERISETAXO_PATH') ? ZERISETAXO_PATH : dirname(__DIR__, 2) . '/';
                $runtime_dir = $plugin_root . 'models/php/runtime/';
                $json_path   = $plugin_root . 'models/php/global-model.json';
                $has_binary  = is_dir($runtime_dir) && file_exists($runtime_dir . 'metadata_float64.json');
                $has_json    = file_exists($json_path);
                $model_found = $has_binary || $has_json;

                $is_healthy        = $model_found;
                $service_status    = $model_found ? 'connected' : 'error';
                $service_label     = $model_found ? __('Active (PHP Native)', 'zerisetaxo') : __('Model Missing', 'zerisetaxo');
                $service_message   = $model_found
                    ? __('PHP-native inference engine is active and running locally in WordPress.', 'zerisetaxo')
                    : __('Bundled model weights were not found in models/php/runtime/.', 'zerisetaxo');
                $last_health_check = time();
                $last_success_conn = $model_found ? time() : 0;
                $last_error_reason = $model_found ? '' : __('Model weights missing', 'zerisetaxo');
                $site_intel = [
                    'site_model_available' => $model_found,
                    'status'               => $model_found ? __('Active (Local Bundled Model)', 'zerisetaxo') : __('Model Missing', 'zerisetaxo'),
                    'active_version'       => $has_binary ? '2.0.0 (float64)' : ($has_json ? '1.0.0 (json)' : 'None'),
                    'accuracy'             => null,
                    'macro_f1'             => null,
                    'reason'               => '',
                ];
                $health = [
                    'status'  => $model_found ? 'healthy' : 'disconnected',
                    'classes' => [],
                ];
                $service_state = [
                    'status'       => $service_status,
                    'status_label' => $service_label,
                    'message'      => $service_message,
                    'is_connected' => $model_found,
                    'last_checked' => $last_health_check,
                    'last_success' => $last_success_conn,
                    'last_error'   => $last_error_reason,
                    'site_model'   => $site_intel,
                    'classes'      => [],
                ];
            }

            $enable_llm_saved   = (bool) get_option('zerisetaxo_enable_llm', false);
            $is_local_endpoint  = InferenceClient::is_local_url($service_url);
            $privacy_notice     = InferenceClient::get_privacy_notice($service_url, $enable_llm_saved);

            $total_posts      = (int) (wp_count_posts('post')->publish ?? 0);
            $all_categories   = get_categories(['hide_empty' => false]);
            $total_categories = count($all_categories);
            $is_recommended   = self::is_recommended_config();

            $site_meta = \ZeriseTaxo\ML\Site\SiteModelStore::load_meta();
            $site_service = new \ZeriseTaxo\ML\Site\SiteModelService();
            $site_model_status = $site_service->get_readiness_state();
            $last_rebuilt_at   = (int) ($site_meta['trained_at'] ?? 0);
            $site_training_docs = (int) ($site_meta['training_docs'] ?? 0);
            $site_class_count   = (int) ($site_meta['class_count'] ?? 0);
            $site_model_ver    = !empty($site_meta['algorithm'])
                ? sprintf(__('%1$d categories, %2$d posts', 'zerisetaxo'), $site_class_count, $site_training_docs)
                : 'None';

            $enable_tag_suggestions  = (bool) get_option('zerisetaxo_enable_tag_suggestions', true);
            $tag_suggestion_limit    = (int) get_option('zerisetaxo_tag_suggestion_limit', 5);
            $confidence_threshold    = (int) get_option('zerisetaxo_confidence_threshold', 75);
            $ambiguity_margin        = (int) get_option('zerisetaxo_ambiguity_margin', 12);
            $min_posts_training      = (int) get_option('zerisetaxo_min_posts_training', 10);
            $min_categories_training = (int) get_option('zerisetaxo_min_categories_training', 2);

            if (!class_exists(\ZeriseTaxo\ML\LLM\LlmCredentialStore::class)) {
                $cred_file = (defined('ZERISETAXO_PATH') ? ZERISETAXO_PATH : dirname(__DIR__, 2) . '/') . 'includes/ML/LLM/LlmCredentialStore.php';
                if (file_exists($cred_file)) {
                    require_once $cred_file;
                }
            }

            $llm_key_status        = class_exists(\ZeriseTaxo\ML\LLM\LlmCredentialStore::class) ? \ZeriseTaxo\ML\LLM\LlmCredentialStore::get_masked_status('gemini') : 'not_configured';
            $is_llm_key_const      = class_exists(\ZeriseTaxo\ML\LLM\LlmCredentialStore::class) ? \ZeriseTaxo\ML\LLM\LlmCredentialStore::is_key_from_constant() : false;
            $has_llm_key           = class_exists(\ZeriseTaxo\ML\LLM\LlmCredentialStore::class) ? \ZeriseTaxo\ML\LLM\LlmCredentialStore::has_api_key('gemini') : false;
            $llm_privacy_consent   = (bool) get_option('zerisetaxo_llm_privacy_consent', false);
            $llm_provider          = (string) get_option('zerisetaxo_llm_provider', 'gemini');
            $llm_model             = (string) get_option('zerisetaxo_llm_model', 'gemini-3-flash-preview');
            $llm_daily_limit       = (int) get_option('zerisetaxo_llm_daily_limit', 100);
        }

        include ZERISETAXO_PATH . 'admin/views/dashboard.php';
    }
}
