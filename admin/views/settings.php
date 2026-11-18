<?php
/**
 * Admin Settings View for ZeriseTaxo (Professional Edition).
 *
 * @var string $service_url
 * @var bool $is_url_from_const
 * @var array|\WP_Error $health
 * @var bool $is_healthy
 * @var array|null $site_intel
 * @var int $total_posts
 * @var int $total_categories
 * @var string $site_id
 * @var bool $is_recommended
 * @var string $subtab
 */
defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$current_subtab = !empty($subtab) ? $subtab : 'general';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only GET navigation
$settings_page_slug = (isset($_GET['page']) && 'zerisetaxo-settings' === sanitize_key(wp_unslash($_GET['page']))) ? 'zerisetaxo-settings' : 'zerisetaxo';
$settings_parent = (isset($pagenow) && 'edit.php' === $pagenow) ? 'edit.php' : 'admin.php';
$base_settings_url = add_query_arg(['page' => $settings_page_slug, 'tab' => 'settings'], admin_url($settings_parent));
$subtab_general_url = add_query_arg(['subtab' => 'general'], $base_settings_url);
$subtab_advanced_url = add_query_arg(['subtab' => 'advanced'], $base_settings_url);

$enable_suggestions  = (bool) get_option('zerisetaxo_enable_suggestions', true);
$suggestion_behavior = get_option('zerisetaxo_suggestion_behavior', 'automatic');
$show_alternative    = (bool) get_option('zerisetaxo_show_alternative', true);
$allow_new_cat       = (bool) get_option('zerisetaxo_allow_new_categories', true);
$enable_local        = (bool) get_option('zerisetaxo_enable_local_model', true);
$enable_zero_shot    = (bool) get_option('zerisetaxo_enable_zero_shot', true);
$enable_llm          = (bool) get_option('zerisetaxo_enable_llm', true);
$delete_data         = (bool) get_option('zerisetaxo_delete_data_on_uninstall', false);
$enable_tag_suggestions  = isset($enable_tag_suggestions) ? (bool) $enable_tag_suggestions : (bool) get_option('zerisetaxo_enable_tag_suggestions', true);
$tag_suggestion_limit    = isset($tag_suggestion_limit) ? (int) $tag_suggestion_limit : (int) get_option('zerisetaxo_tag_suggestion_limit', 5);
$confidence_threshold    = isset($confidence_threshold) ? (int) $confidence_threshold : (int) get_option('zerisetaxo_confidence_threshold', 75);
$ambiguity_margin        = isset($ambiguity_margin) ? (int) $ambiguity_margin : (int) get_option('zerisetaxo_ambiguity_margin', 12);
$min_posts_training      = isset($min_posts_training) ? (int) $min_posts_training : (int) get_option('zerisetaxo_min_posts_training', 10);
$min_categories_training = isset($min_categories_training) ? (int) $min_categories_training : (int) get_option('zerisetaxo_min_categories_training', 2);
$engine_mode         = !empty($engine_mode) ? $engine_mode : get_option('zerisetaxo_prediction_engine', 'php');
$site_learning_enabled = isset($site_learning_enabled) ? (bool) $site_learning_enabled : (bool) get_option(\ZeriseTaxo\ML\Site\SiteModelService::OPTION_ENABLED, false);
$rebuild_result        = isset($rebuild_result) ? $rebuild_result : get_option(\ZeriseTaxo\ML\Site\SiteModelService::OPTION_REBUILD_RESULT, null);

$is_local_endpoint = isset($is_local_endpoint) ? (bool) $is_local_endpoint : true;
$service_status = !empty($service_status) ? $service_status : ($is_healthy ? 'connected' : 'disconnected');
$service_label  = !empty($service_label) ? $service_label : ($is_healthy ? __('Connected / Ready', 'zerisetaxo') : __('Disconnected', 'zerisetaxo'));
$last_health_check = isset($last_health_check) ? (int) $last_health_check : (int) get_option(\ZeriseTaxo\Services\InferenceClient::OPTION_LAST_HEALTH_CHECK, 0);

$enable_llm_saved = isset($enable_llm_saved) ? (bool) $enable_llm_saved : (bool) get_option('zerisetaxo_enable_llm', false);
$has_gemini = $enable_llm_saved && (!empty($has_llm_key) || !empty($is_llm_key_const));



$format_ts = function (int $ts): string {
    if (empty($ts)) return '';
    if (function_exists('wp_date')) return wp_date('Y-m-d H:i:s', $ts);
    if (function_exists('date_i18n')) return date_i18n('Y-m-d H:i:s', $ts);
    return gmdate('Y-m-d H:i:s', $ts);
};
?>
<div class="zerisetaxo-settings-content" style="max-width: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <?php settings_errors(); ?>

    <?php if ('general' === $current_subtab) : ?>
        <!-- ========================================== -->
        <!-- 1. GENERAL SETTINGS (2-COLUMN PRO LAYOUT)  -->
        <!-- ========================================== -->
        <form method="post" action="options.php">
            <?php settings_fields('zerisetaxo_settings'); ?>
            <input type="hidden" name="zerisetaxo_settings_tab" value="general" />

            <div class="zerisetaxo-two-col">

                <!-- MAIN COLUMN (LEFT 72%) -->
                <div class="zerisetaxo-col-main">

                    <!-- TAXONOMY SUGGESTIONS (WITH TOGGLE SWITCHES) -->
                    <div class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 22px; margin-bottom: 20px;">
                        <div style="margin-bottom: 18px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                            <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-tags" style="color: #4F46E5; font-size: 14px;"></i>
                                <span><?php esc_html_e('Taxonomy Suggestions Preferences', 'zerisetaxo'); ?></span>
                            </h3>
                            <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                                <?php esc_html_e('Configure automation behavior, alternative category candidates, and post editor timing.', 'zerisetaxo'); ?>
                            </p>
                        </div>

                        <!-- Setting 1: Enable Suggestions Toggle -->
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 16px; border-bottom: 1px solid #F8FAFC; margin-bottom: 16px;">
                            <div>
                                <span class="zerisetaxo-toggle-title"><?php esc_html_e('Enable Suggestions in Post Editor', 'zerisetaxo'); ?></span>
                                <span class="zerisetaxo-toggle-desc"><?php esc_html_e('Display the ZeriseTaxo taxonomy recommendation card in the Gutenberg editor sidebar.', 'zerisetaxo'); ?></span>
                            </div>
                            <label class="zerisetaxo-toggle-wrapper">
                                <input type="checkbox" name="zerisetaxo_enable_suggestions" value="1" class="zerisetaxo-toggle-input" <?php checked($enable_suggestions); ?> />
                                <span class="zerisetaxo-toggle-track"></span>
                            </label>
                        </div>

                        <!-- Setting 2: Suggestion Timing (Segmented Control) -->
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px; padding-bottom: 16px; border-bottom: 1px solid #F8FAFC; margin-bottom: 16px;">
                            <div>
                                <span class="zerisetaxo-toggle-title"><?php esc_html_e('Recommendation Timing', 'zerisetaxo'); ?></span>
                                <span class="zerisetaxo-toggle-desc"><?php esc_html_e('Choose whether recommendations populate automatically or only on manual trigger.', 'zerisetaxo'); ?></span>
                            </div>
                            <div class="zerisetaxo-segmented-control">
                                <label class="zerisetaxo-segmented-option">
                                    <input type="radio" name="zerisetaxo_suggestion_behavior" value="automatic" <?php checked('automatic', $suggestion_behavior); ?> />
                                    <span class="zerisetaxo-segmented-pill">
                                        <i class="fa-solid fa-bolt" style="font-size: 11px;"></i>
                                        <?php esc_html_e('Automatic (Recommended)', 'zerisetaxo'); ?>
                                    </span>
                                </label>
                                <label class="zerisetaxo-segmented-option">
                                    <input type="radio" name="zerisetaxo_suggestion_behavior" value="manual" <?php checked('manual', $suggestion_behavior); ?> />
                                    <span class="zerisetaxo-segmented-pill">
                                        <i class="fa-solid fa-hand-pointer" style="font-size: 11px;"></i>
                                        <?php esc_html_e('Manual Click', 'zerisetaxo'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Setting 3: Alternative Suggestions Toggle -->
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 16px; border-bottom: 1px solid #F8FAFC; margin-bottom: 16px;">
                            <div>
                                <span class="zerisetaxo-toggle-title"><?php esc_html_e('Show Alternative Suggestions', 'zerisetaxo'); ?></span>
                                <span class="zerisetaxo-toggle-desc"><?php esc_html_e('Display a runner-up alternative category when content spans multiple relevant topics.', 'zerisetaxo'); ?></span>
                            </div>
                            <label class="zerisetaxo-toggle-wrapper">
                                <input type="checkbox" name="zerisetaxo_show_alternative" value="1" class="zerisetaxo-toggle-input" <?php checked($show_alternative); ?> />
                                <span class="zerisetaxo-toggle-track"></span>
                            </label>
                        </div>

                        <!-- Setting 4: Propose New Categories Toggle -->
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;">
                            <div>
                                <span class="zerisetaxo-toggle-title"><?php esc_html_e('Propose New Categories', 'zerisetaxo'); ?></span>
                                <span class="zerisetaxo-toggle-desc"><?php esc_html_e('Allow suggesting new category creation when a post does not match any existing taxonomies.', 'zerisetaxo'); ?></span>
                            </div>
                            <label class="zerisetaxo-toggle-wrapper">
                                <input type="checkbox" name="zerisetaxo_allow_new_categories" value="1" class="zerisetaxo-toggle-input" <?php checked($allow_new_cat); ?> />
                                <span class="zerisetaxo-toggle-track"></span>
                            </label>
                        </div>

                        <!-- Shortcut to Dedicated Category Order Submenu -->
                        <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #F1F5F9; font-size: 12.5px; color: #64748B; display: flex; align-items: center; justify-content: space-between;">
                            <span style="display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-arrow-down-up-across-line" style="color: #4F46E5;"></i>
                                <?php esc_html_e('Looking to prioritize or drag-and-drop sort your site categories?', 'zerisetaxo'); ?>
                            </span>
                            <a href="<?php echo esc_url(add_query_arg(['page' => \ZeriseTaxo\Admin\SettingsPage::ORDER_SLUG], admin_url('admin.php'))); ?>" style="font-weight: 600; color: #4F46E5; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                <span><?php esc_html_e('Open Category Order', 'zerisetaxo'); ?></span>
                                <i class="fa-solid fa-arrow-right" style="font-size: 10px;"></i>
                            </a>
                        </div>
                    </div>

                    <!-- GOOGLE GEMINI AI CARD -->
                    <div class="zerisetaxo-saas-card" id="zerisetaxo-ai-assistance-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 22px; margin-bottom: 20px;">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; border-bottom: 1px solid #F1F5F9; padding-bottom: 12px;">
                            <div>
                                <h3 style="margin: 0 0 6px; font-size: 16px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-wand-magic-sparkles" style="color: #4F46E5;"></i>
                                    <span><?php esc_html_e('Google Gemini AI Supercharger (Optional)', 'zerisetaxo'); ?></span>
                                </h3>
                                <p style="margin: 0; font-size: 13px; color: #64748B;">
                                    <?php esc_html_e('High-accuracy semantic fallback for tricky or niche articles. 100% Free via Google AI Studio.', 'zerisetaxo'); ?>
                                </p>
                            </div>
                            <div>
                                <?php if ($has_gemini) : ?>
                                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 14px; font-size: 12px; font-weight: 600; color: #065F46;">
                                        <i class="fa-solid fa-circle-check" style="color: #10B981;"></i> <?php esc_html_e('AI Supercharger Active', 'zerisetaxo'); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 14px; font-size: 12px; font-weight: 600; color: #475569;">
                                        <i class="fa-solid fa-bolt" style="color: #64748B;"></i> <?php esc_html_e('Offline Local Mode (Standard)', 'zerisetaxo'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 12.5px; color: #166534; line-height: 1.5; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <i class="fa-solid fa-circle-info" style="margin-right: 6px;"></i>
                                <strong><?php esc_html_e('How this works:', 'zerisetaxo'); ?></strong>
                                <?php esc_html_e('Your fast local engine resolves 95%+ of posts offline on your server. Gemini only steps in as a smart safety net when an article is unusual or ambiguous.', 'zerisetaxo'); ?>
                            </div>
                            <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="font-weight: 600; font-size: 12px; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; border-radius: 6px;">
                                <i class="fa-solid fa-key" style="color: #059669;"></i>
                                <span><?php esc_html_e('Get Free API Key (No Credit Card) ↗', 'zerisetaxo'); ?></span>
                            </a>
                        </div>

                        <!-- Gemini Enable Toggle -->
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 14px; border-bottom: 1px solid #F8FAFC; margin-bottom: 14px;">
                            <div>
                                <span class="zerisetaxo-toggle-title"><?php esc_html_e('Enable Cloud AI Assistance', 'zerisetaxo'); ?></span>
                                <span class="zerisetaxo-toggle-desc"><?php esc_html_e('Call Google Gemini (gemini-3-flash-preview) as a safety net when local confidence is below threshold.', 'zerisetaxo'); ?></span>
                            </div>
                            <label class="zerisetaxo-toggle-wrapper">
                                <input type="checkbox" name="zerisetaxo_enable_llm" id="zerisetaxo_enable_llm" value="1" class="zerisetaxo-toggle-input" <?php checked(!empty($enable_llm_saved)); ?> />
                                <span class="zerisetaxo-toggle-track"></span>
                            </label>
                        </div>

                        <!-- Gemini API Key Input -->
                        <div style="margin-bottom: 14px;">
                            <label for="zerisetaxo_llm_api_key" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                <?php esc_html_e('Gemini API Key', 'zerisetaxo'); ?>
                            </label>
                            <?php if (!empty($is_llm_key_const)) : ?>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 6px; font-size: 12px; color: #065F46; font-weight: 600;">
                                        <i class="fa-solid fa-lock"></i> <?php esc_html_e('Configured via wp-config.php', 'zerisetaxo'); ?>
                                    </span>
                                    <button type="button" id="zerisetaxo-btn-test-llm-connection" class="button button-secondary button-small" style="font-weight: 600;">
                                        <i class="fa-solid fa-rotate-right" style="margin-right: 4px;"></i> <?php esc_html_e('Test Connection', 'zerisetaxo'); ?>
                                    </button>
                                </div>
                            <?php else : ?>
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <input type="password" id="zerisetaxo_llm_api_key" name="zerisetaxo_llm_api_key"
                                           value="<?php echo !empty($has_llm_key) ? '••••••••••••••••••••••••••••••••' : ''; ?>"
                                           placeholder="<?php esc_attr_e('Paste your Gemini API key here', 'zerisetaxo'); ?>"
                                           class="regular-text" style="font-family: monospace; font-size: 13px; border-radius: 6px;" autocomplete="new-password" />
                                    <button type="button" id="zerisetaxo-btn-test-llm-connection" class="button button-secondary" style="font-weight: 600; border-radius: 6px;">
                                        <i class="fa-solid fa-rotate-right" style="margin-right: 4px;"></i> <?php esc_html_e('Test Connection', 'zerisetaxo'); ?>
                                    </button>
                                    <?php if (!empty($has_llm_key)) : ?>
                                        <label style="font-size: 12px; color: #DC2626; margin-left: 8px;">
                                            <input type="checkbox" name="zerisetaxo_clear_llm_api_key" value="1" />
                                            <?php esc_html_e('Remove saved key', 'zerisetaxo'); ?>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="zerisetaxo-test-llm-result" style="display: none; margin-top: 8px; max-width: 500px; padding: 8px 12px; border-radius: 6px; font-size: 12.5px;"></div>
                        </div>

                        <!-- Privacy Consent -->
                        <div>
                            <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: #334155; cursor: pointer;">
                                <input type="checkbox" name="zerisetaxo_llm_privacy_consent" value="1" <?php checked(!empty($llm_privacy_consent)); ?> />
                                <span><?php esc_html_e('I consent to sending post titles and cleaned excerpts to Google Gemini AI for taxonomy fallback assistance.', 'zerisetaxo'); ?></span>
                            </label>
                        </div>

                        <!-- Daily Call Limit -->
                        <div style="margin-top: 14px;">
                            <label for="zerisetaxo_llm_daily_limit" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                <?php esc_html_e('Daily API Call Limit', 'zerisetaxo'); ?>
                            </label>
                            <?php
                            $saved_daily_limit = isset($llm_daily_limit) ? (int) $llm_daily_limit : (int) get_option('zerisetaxo_llm_daily_limit', 100);
                            if ($saved_daily_limit <= 0) {
                                $saved_daily_limit = 100;
                            }
                            ?>
                            <input type="number" id="zerisetaxo_llm_daily_limit" name="zerisetaxo_llm_daily_limit"
                                   min="1" max="5000"
                                   value="<?php echo esc_attr((string) $saved_daily_limit); ?>"
                                   style="width: 120px; border-radius: 6px; padding: 6px 10px; border: 1px solid #CBD5E1;" />
                            <p class="description" style="margin: 4px 0 0; font-size: 12px; color: #64748B;">
                                <?php esc_html_e('Maximum Gemini calls per day for the whole site. Each editor is also limited to 30 per day.', 'zerisetaxo'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- SITE-SPECIFIC VOCABULARY LEARNING -->
                                        <div class="zerisetaxo-saas-card" id="zerisetaxo-site-learning-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 22px; margin-bottom: 20px;">
                        <div style="margin-bottom: 18px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                            <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-graduation-cap" style="color: #4F46E5; font-size: 14px;"></i>
                                <span><?php esc_html_e('Site-Specific Vocabulary Learning', 'zerisetaxo'); ?></span>
                            </h3>
                            <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                                <?php esc_html_e('Train a custom local model tailored specifically to your site\'s existing published content.', 'zerisetaxo'); ?>
                            </p>
                        </div>

                        <div style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                            <div>
                                <div style="margin-bottom: 12px;">
                                    <label class="zerisetaxo-toggle-wrapper">
                                        <input type="checkbox" name="zerisetaxo_site_learning_enabled" value="1" class="zerisetaxo-toggle-input" <?php checked($site_learning_enabled); ?> />
                                        <span class="zerisetaxo-toggle-track"></span>
                                        <span class="zerisetaxo-toggle-title" style="font-weight: 500;"><?php esc_html_e('Enable site-specific learning model', 'zerisetaxo'); ?></span>
                                    </label>
                                </div>

                                <div id="zerisetaxo-sync-status-display">
                                    <div style="font-size: 12.5px; color: #475569; line-height: 1.6;">
                                        <div><?php printf(/* translators: %d: Number of training posts. */
                        esc_html__('Training posts available: %d', 'zerisetaxo'), (int)($site_training_docs ?? 0)); ?></div>
                                        <div><?php printf(/* translators: %d: Number of categories learned. */
                        esc_html__('Categories learned: %d', 'zerisetaxo'), (int)($site_class_count ?? 0)); ?></div>
                                    </div>
                                </div>

                                <div id="zerisetaxo-rebuild-progress-box" style="display: none; margin-top: 10px;">
                                    <div id="zerisetaxo-rebuild-progress-label" style="font-size: 13px; font-weight: 600; color: #1E293B;">
                                        <i class="fa-solid fa-spinner fa-spin" style="margin-right: 4px; color: #2563EB;"></i>
                                        <?php esc_html_e('Building local model from content...', 'zerisetaxo'); ?>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <button type="button" id="zerisetaxo-btn-rebuild-model" class="zerisetaxo-rebuild-model-btn button button-secondary" style="font-weight: 600; border-radius: 6px;">
                                    <i class="fa-solid fa-brain" style="margin-right: 4px;"></i>
                                    <?php esc_html_e('Rebuild Site Model', 'zerisetaxo'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- INTERACTIVE PLAYGROUND -->
                    <div class="zerisetaxo-saas-card" id="zerisetaxo-playground-card" style="background: #FFFFFF; border: 1px solid #C7D2FE; border-radius: 10px; padding: 22px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.08);">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                            <div>
                                <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-flask" style="color: #4F46E5; font-size: 14px;"></i>
                                    <span><?php esc_html_e('Instant Test Playground', 'zerisetaxo'); ?></span>
                                </h3>
                                <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                                    <?php esc_html_e('Simulate how ZeriseTaxo analyzes content and recommends categories in real time.', 'zerisetaxo'); ?>
                                </p>
                            </div>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                                <span style="font-size: 11.5px; color: #64748B; font-weight: 600;"><?php esc_html_e('Try sample:', 'zerisetaxo'); ?></span>
                                <button type="button" class="button button-small zerisetaxo-pg-sample-btn" data-title="The Future of Artificial Intelligence in Web Development" data-content="Machine learning models and neural networks are transforming how websites optimize content and categorize user posts automatically." style="font-size: 11px;">
                                    Tech &amp; AI
                                </button>
                                <button type="button" class="button button-small zerisetaxo-pg-sample-btn" data-title="10 Best Mountain Hiking Trails for Summer Vacation" data-content="Planning an outdoor adventure requires the right backpack, durable boots, and reliable camping gear for high-altitude trekking." style="font-size: 11px;">
                                    Travel
                                </button>
                                <button type="button" class="button button-small zerisetaxo-pg-sample-btn" data-title="Quick &amp; Healthy Mediterranean Salad Recipe" data-content="Toss crisp cucumbers, ripe tomatoes, kalamata olives, and fresh feta cheese with extra virgin olive oil for a delicious summer lunch." style="font-size: 11px;">
                                    Food &amp; Recipe
                                </button>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                            <div>
                                <label for="zerisetaxo-pg-title" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                    <?php esc_html_e('Post Title', 'zerisetaxo'); ?>
                                </label>
                                <input type="text" id="zerisetaxo-pg-title" class="regular-text" style="width: 100%; font-size: 13px; border-radius: 6px;" placeholder="<?php esc_attr_e('e.g. 10 Tips for Healthy Morning Routine', 'zerisetaxo'); ?>" />
                            </div>
                            <div>
                                <label for="zerisetaxo-pg-content" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                    <?php esc_html_e('Post Content Excerpt', 'zerisetaxo'); ?>
                                </label>
                                <textarea id="zerisetaxo-pg-content" rows="2" style="width: 100%; font-size: 12.5px; border-radius: 6px; resize: vertical;" placeholder="<?php esc_attr_e('e.g. Waking up early, drinking water, and exercising sets a productive tone for the rest of the day...', 'zerisetaxo'); ?>"></textarea>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px;">
                            <button type="button" id="zerisetaxo-pg-run-btn" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 6px 20px; font-size: 14px; border-radius: 6px; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25); cursor: pointer;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                                <span><?php esc_html_e('Test Suggestions', 'zerisetaxo'); ?></span>
                            </button>
                            <span id="zerisetaxo-pg-loading" style="display: none; font-size: 12.5px; color: #4F46E5;">
                                <i class="fa-solid fa-spinner fa-spin"></i> <?php esc_html_e('Analyzing content in real time...', 'zerisetaxo'); ?>
                            </span>
                            <a href="<?php echo esc_url(admin_url('post-new.php')); ?>" style="font-size: 12.5px; font-weight: 600; color: #4F46E5; text-decoration: none; margin-left: auto; display: inline-flex; align-items: center; gap: 5px;">
                                <span><?php esc_html_e('Or test live in Post Editor', 'zerisetaxo'); ?></span>
                                <i class="fa-solid fa-arrow-right" style="font-size: 10px;"></i>
                            </a>
                        </div>

                        <!-- PLAYGROUND RESULTS BOX -->
                        <div id="zerisetaxo-pg-results" style="display: none; margin-top: 16px; padding: 16px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                                <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #475569;">
                                    <?php esc_html_e('Analysis Results', 'zerisetaxo'); ?>
                                </div>
                                <div id="zerisetaxo-pg-engine-pill" style="font-size: 11.5px; font-weight: 600;"></div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px;">
                                    <div style="font-size: 11px; font-weight: 600; color: #64748B; text-transform: uppercase; margin-bottom: 4px;">
                                        <?php esc_html_e('Suggested Category', 'zerisetaxo'); ?>
                                    </div>
                                    <div id="zerisetaxo-pg-cat-name" style="font-size: 17px; font-weight: 700; color: #0F172A;"></div>
                                    <div id="zerisetaxo-pg-cat-conf" style="font-size: 12px; color: #059669; font-weight: 600; margin-top: 4px;"></div>
                                    <div class="zerisetaxo-conf-bar-bg">
                                        <div id="zerisetaxo-pg-conf-bar" class="zerisetaxo-conf-bar-fill" style="width: 0%;"></div>
                                    </div>
                                </div>

                                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px;">
                                    <div style="font-size: 11px; font-weight: 600; color: #64748B; text-transform: uppercase; margin-bottom: 8px;">
                                        <?php esc_html_e('Suggested Post Tags', 'zerisetaxo'); ?>
                                    </div>
                                    <div id="zerisetaxo-pg-tags-container" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SUBMIT BUTTON -->
                    <p class="submit" style="margin-top: 10px;">
                        <button type="submit" name="submit" id="submit" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 6px 20px; font-size: 14px; border-radius: 6px; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25);">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span><?php esc_html_e('Save Settings', 'zerisetaxo'); ?></span>
                        </button>
                    </p>
                </div>

                <!-- RIGHT PRO SIDEBAR (28%) -->
                <div class="zerisetaxo-col-sidebar" style="position: sticky; top: 48px; align-self: start;">

                    <!-- STICKY ACTION CARD: CLEAN PROFESSIONAL SAVE -->
                    <div class="zerisetaxo-sidebar-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03); margin-bottom: 16px;">
                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748B; margin-bottom: 10px;">
                            <i class="fa-solid fa-sliders" style="color: #64748B; font-size: 11px; margin-right: 4px;"></i>
                            <?php esc_html_e('Actions', 'zerisetaxo'); ?>
                        </div>
                        <button type="submit" name="submit" class="button button-primary button-large" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 7px; font-weight: 600; padding: 8px 14px; font-size: 13px; border-radius: 6px; background: #4F46E5; border: 1px solid #4338CA; color: #FFFFFF; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25); cursor: pointer; transition: all 0.15s ease;">
                            <i class="fa-solid fa-floppy-disk" style="font-size: 12px; color: #C7D2FE;"></i>
                            <span><?php esc_html_e('Save Settings', 'zerisetaxo'); ?></span>
                        </button>
                        <div style="margin-top: 8px; font-size: 11.5px; color: #64748B; line-height: 1.4; text-align: center; display: flex; align-items: center; justify-content: center; gap: 4px;">
                            <i class="fa-solid fa-shield-halved" style="color: #10B981; font-size: 11px;"></i>
                            <span><?php esc_html_e('Auto-synced with post editor', 'zerisetaxo'); ?></span>
                        </div>
                    </div>


                    <!-- CARD 1: ENGINE TELEMETRY -->
                    <div class="zerisetaxo-sidebar-card">
                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                            <span><?php esc_html_e('Engine Telemetry', 'zerisetaxo'); ?></span>
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10B981;"></span>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #F1F5F9; padding-bottom: 8px;">
                                <span style="color: #64748B;"><?php esc_html_e('Runtime', 'zerisetaxo'); ?></span>
                                <strong style="color: #0F172A;">PHP Native ML</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #F1F5F9; padding-bottom: 8px;">
                                <span style="color: #64748B;"><?php esc_html_e('Average Speed', 'zerisetaxo'); ?></span>
                                <strong style="color: #059669;">&lt; 0.2 ms</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #F1F5F9; padding-bottom: 8px;">
                                <span style="color: #64748B;"><?php esc_html_e('Model Weights', 'zerisetaxo'); ?></span>
                                <strong style="color: #0F172A;">v2.0.0 (Float64)</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #F1F5F9; padding-bottom: 8px;">
                                <span style="color: #64748B;"><?php esc_html_e('Cloud Dependence', 'zerisetaxo'); ?></span>
                                <strong style="color: #059669;">Zero (100% Offline)</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="color: #64748B;"><?php esc_html_e('AI Fallback', 'zerisetaxo'); ?></span>
                                <strong style="color: <?php echo $has_gemini ? '#059669' : '#64748B'; ?>;">
                                    <?php echo $has_gemini ? __('Gemini Connected', 'zerisetaxo') : __('Offline Only', 'zerisetaxo'); ?>
                                </strong>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 2: DOCUMENTATION & TIPS -->
                    <div class="zerisetaxo-sidebar-card" style="background: linear-gradient(180deg, #FFFFFF 0%, #F8FAFC 100%);">
                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; margin-bottom: 10px;">
                            <i class="fa-solid fa-lightbulb" style="color: #F59E0B; margin-right: 4px;"></i>
                            <?php esc_html_e('Quick Guide', 'zerisetaxo'); ?>
                        </div>
                        <p style="font-size: 12.5px; color: #475569; line-height: 1.5; margin: 0 0 12px;">
                            <?php esc_html_e('When writing a post in Gutenberg, ZeriseTaxo automatically analyzes your title & excerpt and recommends appropriate categories and tags.', 'zerisetaxo'); ?>
                        </p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=zerisetaxo-guide')); ?>" class="button button-secondary button-small" style="width: 100%; text-align: center; font-weight: 600; border-radius: 6px;">
                            <i class="fa-solid fa-book-open" style="margin-right: 4px;"></i>
                            <?php esc_html_e('Read Full User Guide', 'zerisetaxo'); ?>
                        </a>
                    </div>

                    <!-- CARD 3: COMMUNITY & SUPPORT -->
                    <div class="zerisetaxo-sidebar-card" style="text-align: center;">
                        <div style="font-size: 20px; color: #F59E0B; margin-bottom: 6px;">
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                        </div>
                        <h4 style="margin: 0 0 4px; font-size: 13.5px; font-weight: 700; color: #0F172A;">
                            <?php esc_html_e('Loving ZeriseTaxo?', 'zerisetaxo'); ?>
                        </h4>
                        <p style="font-size: 12px; color: #64748B; margin: 0 0 12px;">
                            <?php esc_html_e('Support continuous updates and ML improvements by starring the project or giving feedback.', 'zerisetaxo'); ?>
                        </p>
                        <a href="https://wordpress.org/support/plugin/zerisetaxo/reviews/" target="_blank" rel="noopener noreferrer" class="button button-small" style="font-weight: 600; border-radius: 6px;">
                            <?php esc_html_e('Leave a Review ↗', 'zerisetaxo'); ?>
                        </a>
                    </div>

                </div>
            </div>
        </form>

            <?php elseif ('advanced' === $current_subtab || 'diagnostics' === $current_subtab) : ?>
        <!-- ==================================================== -->
        <!-- 2. ADVANCED SETTINGS (CLEAN & AUTHENTIC PRO SAAS)    -->
        <!-- ==================================================== -->

        

        <!-- 2. RUNTIME COMPONENT CONTROLS (TOGGLES FORM) -->
        <form method="post" action="options.php">
            <?php settings_fields('zerisetaxo_settings'); ?>
            <input type="hidden" name="zerisetaxo_settings_tab" value="advanced" />
            

            <div class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);">
                <div style="margin-bottom: 18px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                    <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-sliders" style="color: #4F46E5; font-size: 14px;"></i>
                        <span><?php esc_html_e('Active Engine Components', 'zerisetaxo'); ?></span>
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                        <?php esc_html_e('Enable or disable specific machine learning signals, zero-shot centroids, and cloud AI fallback layers.', 'zerisetaxo'); ?>
                    </p>
                </div>

                <!-- Component 1: Site-Specific Model -->
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 18px; border-bottom: 1px solid #F8FAFC; margin-bottom: 18px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Site-Specific Supervised Model', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('Enable custom supervised model trained on your published posts and categories.', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <label class="zerisetaxo-toggle-wrapper">
                        <input type="checkbox" name="zerisetaxo_enable_local_model" value="1" class="zerisetaxo-toggle-input" <?php checked($enable_local); ?> />
                        <span class="zerisetaxo-toggle-track"></span>
                    </label>
                </div>

                <!-- Component 2: Semantic Category Matching -->
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 18px; border-bottom: 1px solid #F8FAFC; margin-bottom: 18px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Semantic Category Matching', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('Use zero-shot category centroid embeddings and cosine similarity for deep semantic resolution.', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <label class="zerisetaxo-toggle-wrapper">
                        <input type="checkbox" name="zerisetaxo_enable_zero_shot" value="1" class="zerisetaxo-toggle-input" <?php checked($enable_zero_shot); ?> />
                        <span class="zerisetaxo-toggle-track"></span>
                    </label>
                </div>

                <!-- Component 3: Cloud Fallback -->
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Cloud AI Fallback (Google Gemini)', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('Consults Google Gemini AI when local classification confidence falls below the 12% ambiguity margin.', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <label class="zerisetaxo-toggle-wrapper">
                        <input type="checkbox" name="zerisetaxo_enable_llm" value="1" class="zerisetaxo-toggle-input" <?php checked($enable_llm); ?> />
                        <span class="zerisetaxo-toggle-track"></span>
                    </label>
                </div>
            </div>

            <!-- PREDICTION SENSITIVITY & THRESHOLDS CARD -->
            <div class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);">
                <div style="margin-bottom: 18px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                    <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-gauge-high" style="color: #4F46E5; font-size: 14px;"></i>
                        <span><?php esc_html_e('Prediction Confidence & Ambiguity Thresholds', 'zerisetaxo'); ?></span>
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                        <?php esc_html_e('Fine-tune statistical confidence criteria for automatic category assignment and ambiguous alternative detection.', 'zerisetaxo'); ?>
                    </p>
                </div>

                <!-- Threshold 1: Confidence Threshold -->
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 18px; border-bottom: 1px solid #F8FAFC; margin-bottom: 18px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Confidence Threshold for Recommendation', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('Minimum probability required to present a category as a confident recommendation (Default: 75%). Lower values match more aggressively; higher values prioritize accuracy.', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <input type="number" name="zerisetaxo_confidence_threshold" value="<?php echo esc_attr($confidence_threshold); ?>" min="30" max="95" step="1" style="width: 75px; text-align: center; font-weight: 600; border-radius: 6px; padding: 5px 8px; border: 1px solid #CBD5E1;" />
                        <span style="font-weight: 600; color: #64748B;">%</span>
                    </div>
                </div>

                <!-- Threshold 2: Ambiguity Margin -->
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Ambiguity Detection Margin', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('When the probability margin between the top recommendation and runner-up is below this percentage, ZeriseTaxo surfaces both options for editorial choice (Default: 12%).', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <input type="number" name="zerisetaxo_ambiguity_margin" value="<?php echo esc_attr($ambiguity_margin); ?>" min="5" max="30" step="1" style="width: 75px; text-align: center; font-weight: 600; border-radius: 6px; padding: 5px 8px; border: 1px solid #CBD5E1;" />
                        <span style="font-weight: 600; color: #64748B;">%</span>
                    </div>
                </div>
            </div>

            <!-- SITE VOCABULARY LEARNING THRESHOLDS CARD -->
            <div class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);">
                <div style="margin-bottom: 18px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                    <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-graduation-cap" style="color: #4F46E5; font-size: 14px;"></i>
                        <span><?php esc_html_e('Site Vocabulary Learning Requirements', 'zerisetaxo'); ?></span>
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                        <?php esc_html_e('Requirements before ZeriseTaxo trains custom site-specific Naive Bayes models from your published posts.', 'zerisetaxo'); ?>
                    </p>
                </div>

                <!-- Req 1: Minimum Posts -->
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 18px; border-bottom: 1px solid #F8FAFC; margin-bottom: 18px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Minimum Published Posts for Training', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('Minimum number of published posts with assigned categories needed before site model training can occur (Default: 10 posts).', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <div>
                        <input type="number" name="zerisetaxo_min_posts_training" value="<?php echo esc_attr($min_posts_training); ?>" min="2" max="500" step="1" style="width: 75px; text-align: center; font-weight: 600; border-radius: 6px; padding: 5px 8px; border: 1px solid #CBD5E1;" />
                    </div>
                </div>

                <!-- Req 2: Minimum Categories -->
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Minimum Distinct Categories for Training', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('Classification requires discriminating between at least 2 categories (Default: 2 categories).', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <div>
                        <input type="number" name="zerisetaxo_min_categories_training" value="<?php echo esc_attr($min_categories_training); ?>" min="2" max="100" step="1" style="width: 75px; text-align: center; font-weight: 600; border-radius: 6px; padding: 5px 8px; border: 1px solid #CBD5E1;" />
                    </div>
                </div>
            </div>

            <!-- 3. DATA RETENTION & FACTORY RESET CARD -->
            <div class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);">
                <div style="margin-bottom: 18px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                    <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-shield-halved" style="color: #4F46E5; font-size: 14px;"></i>
                        <span><?php esc_html_e('Data Retention & Maintenance', 'zerisetaxo'); ?></span>
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                        <?php esc_html_e('Manage cached taxonomy embeddings, uninstall cleanup rules, and reset configuration to factory defaults.', 'zerisetaxo'); ?>
                    </p>
                </div>

                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 18px; border-bottom: 1px solid #F8FAFC; margin-bottom: 18px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 3px;">
                            <?php esc_html_e('Purge Plugin Data on Uninstall', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5; display: block;">
                            <?php esc_html_e('Removes cached taxonomy embeddings and feedback data if plugin is deleted. Does not delete posts, categories, or WordPress content.', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <label class="zerisetaxo-toggle-wrapper">
                        <input type="checkbox" name="zerisetaxo_delete_data_on_uninstall" value="1" class="zerisetaxo-toggle-input" <?php checked($delete_data); ?> />
                        <span class="zerisetaxo-toggle-track"></span>
                    </label>
                </div>

                <!-- Restore Defaults Row -->
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <strong style="display: block; font-size: 14px; color: #0F172A; margin-bottom: 2px;">
                            <?php esc_html_e('Restore Factory Defaults', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 12.5px; color: #64748B;">
                            <?php esc_html_e('Reset all parameters back to tested production defaults.', 'zerisetaxo'); ?>
                        </span>
                    </div>
                    <div>
                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['zerisetaxo_action' => 'restore_defaults'], $subtab_advanced_url), 'zerisetaxo_restore_defaults_nonce', 'zerisetaxo_restore_nonce')); ?>" 
                           class="button button-secondary" 
                           style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; border-radius: 6px; font-size: 12.5px;" 
                           onclick="return confirm('<?php echo esc_js(__('Restore configuration to recommended defaults?', 'zerisetaxo')); ?>');">
                            <i class="fa-solid fa-rotate-left"></i>
                            <span><?php esc_html_e('Reset Defaults', 'zerisetaxo'); ?></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- SAVE BUTTON -->
            <p class="submit" style="margin-top: 10px;">
                <button type="submit" name="submit" id="submit" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 7px 22px; font-size: 13.5px; border-radius: 6px; background: #4F46E5; border-color: #4F46E5; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25);">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span><?php esc_html_e('Save Advanced Settings', 'zerisetaxo'); ?></span>
                </button>
            </p>
        </form>

    <?php endif; ?>
</div>
