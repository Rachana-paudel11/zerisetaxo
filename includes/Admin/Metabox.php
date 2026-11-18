<?php
namespace ZeriseTaxo\Admin;

defined('ABSPATH') || exit;

/**
 * Metabox on the post edit screen providing the ML Category Predictor interface.
 */
class Metabox {

    /**
     * Metabox ID.
     */
    const METABOX_ID = 'zerisetaxo_metabox';

    /**
     * Register hooks.
     */
    public function register() {
        add_action('add_meta_boxes_post', [$this, 'add_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add metabox to post editing screen.
     *
     * @param \WP_Post $post
     */
    public function add_metabox($post) {
        if (!get_option('zerisetaxo_enable_suggestions', true)) {
            return;
        }

        add_meta_box(
            self::METABOX_ID,
            __('ZeriseTaxo Suggestions', 'zerisetaxo'),
            [$this, 'render_metabox'],
            'post',
            'side',
            'high'
        );
    }

    /**
     * Enqueue styles and scripts for post edit screens.
     *
     * @param string $hook_suffix
     */
    public function enqueue_assets($hook_suffix) {
        if (!get_option('zerisetaxo_enable_suggestions', true)) {
            return;
        }

        if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'post') {
            return;
        }

        global $post;
        $post_id = $post ? $post->ID : 0;

        $css_file = ZERISETAXO_PATH . 'admin/css/predict.css';
        $js_file  = ZERISETAXO_PATH . 'admin/js/predict.js';
        $css_ver  = file_exists($css_file) ? filemtime($css_file) : ZERISETAXO_VERSION;
        $js_ver   = file_exists($js_file) ? filemtime($js_file) : ZERISETAXO_VERSION;

        $fa_file = ZERISETAXO_PATH . 'admin/css/font-awesome.min.css';
        $fa_ver  = file_exists($fa_file) ? filemtime($fa_file) : '6.5.1';

        // Enqueue bundled Font Awesome 6 locally (no external CDN per WP.org offloading rule)
        wp_enqueue_style(
            'zerisetaxo-font-awesome',
            ZERISETAXO_URL . 'admin/css/font-awesome.min.css',
            [],
            $fa_ver
        );

        wp_enqueue_style(
            'zerisetaxo-admin',
            ZERISETAXO_URL . 'admin/css/predict.css',
            ['zerisetaxo-font-awesome'],
            $css_ver
        );
        wp_enqueue_script(
            'zerisetaxo-admin',
            ZERISETAXO_URL . 'admin/js/predict.js',
            ['jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-dom-ready'],
            $js_ver,
            true
        );

        $saved_category    = $post_id ? (get_post_meta($post_id, '_zerisetaxo_predicted_category', true) ?: get_post_meta($post_id, '_ml_predicted_category', true)) : '';
        $saved_suggested_category = $post_id ? (get_post_meta($post_id, '_zerisetaxo_suggested_category', true) ?: get_post_meta($post_id, '_ml_suggested_category', true)) : '';
        $saved_result_type        = $post_id ? (get_post_meta($post_id, '_zerisetaxo_result_type', true) ?: get_post_meta($post_id, '_ml_result_type', true)) : '';
        $saved_confidence         = $post_id ? (get_post_meta($post_id, '_zerisetaxo_prediction_confidence', true) ?: get_post_meta($post_id, '_ml_prediction_confidence', true)) : '';
        $saved_tier               = $post_id ? (get_post_meta($post_id, '_zerisetaxo_prediction_tier', true) ?: get_post_meta($post_id, '_ml_prediction_tier', true)) : '';
        $saved_tier_name          = $post_id ? (get_post_meta($post_id, '_zerisetaxo_prediction_tier_name', true) ?: get_post_meta($post_id, '_ml_prediction_tier_name', true)) : '';
        $saved_breakdown          = $post_id ? (get_post_meta($post_id, '_zerisetaxo_prediction_tier_breakdown', true) ?: get_post_meta($post_id, '_ml_prediction_tier_breakdown', true)) : [];
        $saved_state              = $post_id ? (get_post_meta($post_id, '_zerisetaxo_prediction_state', true) ?: get_post_meta($post_id, '_ml_prediction_state', true)) : '';
        $decision_source          = $post_id ? (get_post_meta($post_id, '_zerisetaxo_decision_source', true) ?: get_post_meta($post_id, '_ml_decision_source', true)) : '';
        $saved_ru_category        = $post_id ? (get_post_meta($post_id, '_zerisetaxo_runner_up_category', true) ?: get_post_meta($post_id, '_ml_runner_up_category', true)) : '';
        $saved_ru_term_id         = $post_id ? (int) (get_post_meta($post_id, '_zerisetaxo_runner_up_category_id', true) ?: get_post_meta($post_id, '_ml_runner_up_category_id', true)) : 0;

        // Term ID & parent info for saved category if one exists
        $saved_term_id     = 0;
        $saved_parent_id   = 0;
        $saved_parent_name = '';
        if (!empty($saved_category)) {
            $term_obj = get_term_by('name', $saved_category, 'category');
            if ($term_obj && !is_wp_error($term_obj)) {
                $saved_term_id = (int) $term_obj->term_id;
                if ($term_obj->parent > 0) {
                    $saved_parent_id   = (int) $term_obj->parent;
                    $saved_parent_name = get_cat_name($saved_parent_id);
                }
            }
        }

        // Runner-up term ID fallback lookup if not explicitly in meta
        $saved_ru_parent_id   = 0;
        $saved_ru_parent_name = '';
        if (0 === $saved_ru_term_id && !empty($saved_ru_category)) {
            $ru_term_obj = get_term_by('name', $saved_ru_category, 'category');
            if ($ru_term_obj && !is_wp_error($ru_term_obj)) {
                $saved_ru_term_id = (int) $ru_term_obj->term_id;
            }
        }
        if ($saved_ru_term_id > 0) {
            $ru_term_obj = get_term($saved_ru_term_id, 'category');
            if ($ru_term_obj && !is_wp_error($ru_term_obj) && $ru_term_obj->parent > 0) {
                $saved_ru_parent_id   = (int) $ru_term_obj->parent;
                $saved_ru_parent_name = get_cat_name($saved_ru_parent_id);
            }
        }

        $is_valid_ambiguous = ('ambiguous' === $saved_state
            && !empty($saved_category)
            && !empty($saved_ru_category)
            && $saved_term_id > 0
            && $saved_ru_term_id > 0
            && $saved_term_id !== $saved_ru_term_id);

        if ('ambiguous' === $saved_state && !$is_valid_ambiguous && $saved_term_id > 0) {
            $saved_state = 'good_match';
        }

        $post_enable_local= $post_id ? (get_post_meta($post_id, '_zerisetaxo_enable_local_model', true) ?: get_post_meta($post_id, '_ml_enable_local_model', true)) : '';
        $post_enable_zs   = $post_id ? (get_post_meta($post_id, '_zerisetaxo_enable_zero_shot', true) ?: get_post_meta($post_id, '_ml_enable_zero_shot', true)) : '';
        $post_enable_llm  = $post_id ? (get_post_meta($post_id, '_zerisetaxo_enable_llm', true) ?: get_post_meta($post_id, '_ml_enable_llm', true)) : '';
        $enable_local     = ($post_enable_local !== '') ? (bool) $post_enable_local : (bool) get_option('zerisetaxo_enable_local_model', true);
        $enable_zero_shot = ($post_enable_zs !== '') ? (bool) $post_enable_zs : (bool) get_option('zerisetaxo_enable_zero_shot', true);
        $enable_llm       = ($post_enable_llm !== '') ? (bool) $post_enable_llm : (bool) get_option('zerisetaxo_enable_llm', true);

        wp_localize_script('zerisetaxo-admin', 'zeriseTaxoData', [
            'restUrl'                 => esc_url_raw(rest_url('zerisetaxo/v1/predict')),
            'predictTagsUrl'          => esc_url_raw(rest_url('zerisetaxo/v1/predict-tags')),
            'tagsRestUrl'             => esc_url_raw(rest_url('wp/v2/tags')),
            'applyUrl'                => esc_url_raw(rest_url('zerisetaxo/v1/apply')),
            'createApplyUrl'          => esc_url_raw(rest_url('zerisetaxo/v1/create-and-apply')),
            'queueUrl'                => esc_url_raw(rest_url('zerisetaxo/v1/queue-suggestion')),
            'nonce'                   => wp_create_nonce('wp_rest'),
            'postId'                  => (int) $post_id,
            'defaultCategoryId'       => (int) get_option('default_category', 1),
            'canManageCategories'     => current_user_can('manage_categories'),
            'canAssignTags'           => current_user_can(get_taxonomy('post_tag')->cap->assign_terms ?? 'edit_posts'),
            'canManageOptions'        => current_user_can('manage_options'),
            'enableSuggestions'       => (bool) get_option('zerisetaxo_enable_suggestions', true),
            'enableTagSuggestions'    => (bool) get_option('zerisetaxo_enable_tag_suggestions', true),
            'tagSuggestionLimit'      => (int) get_option('zerisetaxo_tag_suggestion_limit', 5),
            'suggestionBehavior'      => get_option('zerisetaxo_suggestion_behavior', 'automatic'),
            'showAlternative'         => (bool) get_option('zerisetaxo_show_alternative', true),
            'allowNewCategories'      => (bool) get_option('zerisetaxo_allow_new_categories', true),
            'savedCategory'           => sanitize_text_field($saved_category),
            'savedCategoryId'         => $saved_term_id,
            'savedCategoryParentId'   => $saved_parent_id,
            'savedCategoryParentName' => sanitize_text_field($saved_parent_name),
            'savedSuggestedCategory'  => sanitize_text_field($saved_suggested_category),
            'savedResultType'         => sanitize_text_field($saved_result_type),
            'savedRunnerUpCategory'   => sanitize_text_field($saved_ru_category),
            'savedRunnerUpCategoryId' => $saved_ru_term_id,
            'savedRunnerUpParentId'   => $saved_ru_parent_id,
            'savedRunnerUpParentName' => sanitize_text_field($saved_ru_parent_name),
            'isValidAmbiguous'        => $is_valid_ambiguous,
            'savedConfidence'         => $saved_confidence !== '' ? (float) $saved_confidence : null,
            'savedState'              => sanitize_text_field($saved_state),
            'savedDecisionSource'     => sanitize_text_field($decision_source),
            'enableLocalModel'        => $enable_local,
            'enableZeroShot'          => $enable_zero_shot,
            'enableLlm'               => $enable_llm,
            'i18n'                    => [
                'suggestCategory'     => __('Suggest Category', 'zerisetaxo'),
                'findingBest'         => __('Finding the best category...', 'zerisetaxo'),
                'checkAgain'          => __('Check again', 'zerisetaxo'),
                'useInstead'          => __('Use instead', 'zerisetaxo'),
                'createAndUse'        => __('Create & use', 'zerisetaxo'),
                'chooseMyself'        => __('Choose category myself', 'zerisetaxo'),
                'serviceUnavailable'  => __('Category suggestions are temporarily unavailable.', 'zerisetaxo'),
                'ambiguousInvalid'    => __('Multiple categories were suggested, but one or more could not be verified. Please check again.', 'zerisetaxo'),
                'chooseNormally'      => __('You can still choose a category normally.', 'zerisetaxo'),
                'tryAgain'            => __('Try again', 'zerisetaxo'),
                'appliedSuccess'      => __('Category applied to post!', 'zerisetaxo'),
                'createdSuccess'      => __('New category created and applied!', 'zerisetaxo'),
                'queuedSuccess'       => __('Category suggestion queued for review!', 'zerisetaxo'),
                'noPermissionCreate'  => __("You don't have permission to create categories.", 'zerisetaxo'),
                'strongMatch'         => __('Strong match', 'zerisetaxo'),
                'goodMatch'           => __('Good match', 'zerisetaxo'),
                'reviewSuggested'     => __('Review suggested', 'zerisetaxo'),
                'noStrongMatch'       => __('No strong match', 'zerisetaxo'),
                'whyThisCategory'     => __('Why this category?', 'zerisetaxo'),
                'whySuggestNew'       => __('Why suggest a new category?', 'zerisetaxo'),
                'whyNoMatch'          => __('Why no match?', 'zerisetaxo'),
                'newCategorySuggested'=> __('New Category Suggested', 'zerisetaxo'),
                'insufficientContent' => __('Insufficient Content', 'zerisetaxo'),
                'refreshTags'         => __('Refresh Tags', 'zerisetaxo'),
                'suggestTags'         => __('Suggest Tags', 'zerisetaxo'),
            ],
        ]);
    }

    /**
     * Render the metabox template.
     *
     * @param \WP_Post $post
     */
    public function render_metabox($post) {
        $saved_category           = (get_post_meta($post->ID, '_zerisetaxo_predicted_category', true) ?: get_post_meta($post->ID, '_ml_predicted_category', true));
        $saved_suggested_category = (get_post_meta($post->ID, '_zerisetaxo_suggested_category', true) ?: get_post_meta($post->ID, '_ml_suggested_category', true));
        $saved_result_type        = (get_post_meta($post->ID, '_zerisetaxo_result_type', true) ?: get_post_meta($post->ID, '_ml_result_type', true));
        $saved_confidence         = (get_post_meta($post->ID, '_zerisetaxo_prediction_confidence', true) ?: get_post_meta($post->ID, '_ml_prediction_confidence', true));
        $saved_reasoning          = (get_post_meta($post->ID, '_zerisetaxo_prediction_reasoning', true) ?: get_post_meta($post->ID, '_ml_prediction_reasoning', true));
        $saved_tier        = (get_post_meta($post->ID, '_zerisetaxo_prediction_tier', true) ?: get_post_meta($post->ID, '_ml_prediction_tier', true));
        $saved_tier_name   = (get_post_meta($post->ID, '_zerisetaxo_prediction_tier_name', true) ?: get_post_meta($post->ID, '_ml_prediction_tier_name', true));
        $saved_state       = (get_post_meta($post->ID, '_zerisetaxo_prediction_state', true) ?: get_post_meta($post->ID, '_ml_prediction_state', true));
        $decision_source   = (get_post_meta($post->ID, '_zerisetaxo_decision_source', true) ?: get_post_meta($post->ID, '_ml_decision_source', true));
        $saved_ru_category = (get_post_meta($post->ID, '_zerisetaxo_runner_up_category', true) ?: get_post_meta($post->ID, '_ml_runner_up_category', true));
        $saved_ru_term_id  = (int) (get_post_meta($post->ID, '_zerisetaxo_runner_up_category_id', true) ?: get_post_meta($post->ID, '_ml_runner_up_category_id', true));

        $saved_term_id = 0;
        if (!empty($saved_category)) {
            $term_obj = get_term_by('name', $saved_category, 'category');
            if ($term_obj && !is_wp_error($term_obj)) {
                $saved_term_id = (int) $term_obj->term_id;
            }
        }

        if (0 === $saved_ru_term_id && !empty($saved_ru_category)) {
            $ru_term_obj = get_term_by('name', $saved_ru_category, 'category');
            if ($ru_term_obj && !is_wp_error($ru_term_obj)) {
                $saved_ru_term_id = (int) $ru_term_obj->term_id;
            }
        }

        $is_valid_ambiguous = ('ambiguous' === $saved_state
            && !empty($saved_category)
            && !empty($saved_ru_category)
            && $saved_term_id > 0
            && $saved_ru_term_id > 0
            && $saved_term_id !== $saved_ru_term_id);

        if ('ambiguous' === $saved_state && !$is_valid_ambiguous && $saved_term_id > 0) {
            $saved_state = 'good_match';
        }

        $can_create_categories = current_user_can('manage_categories');
        $is_admin              = current_user_can('manage_options');

        include ZERISETAXO_PATH . 'admin/views/metabox.php';
    }
}
