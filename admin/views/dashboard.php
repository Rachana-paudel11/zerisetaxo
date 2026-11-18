<?php
/**
 * Unified Admin Dashboard for ZeriseTaxo with Clean, Authentic SaaS Styling.
 *
 * @var string $active_tab
 * @var int $pending_count
 * @var \ZeriseTaxo\Admin\SuggestionsListTable|null $list_table
 * @var string $service_url
 * @var array|null $health
 * @var bool $is_healthy
 * @var array|null $site_intel
 * @var array|null $service_state
 */
defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET tab navigation
$raw_tab    = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET tab navigation
$raw_subtab = isset($_GET['subtab']) ? sanitize_key(wp_unslash($_GET['subtab'])) : '';

$zerisetaxo_parent_page       = (isset($pagenow) && 'edit.php' === $pagenow) ? 'edit.php' : 'admin.php';
$zerisetaxo_tab_overview_url  = add_query_arg(['page' => \ZeriseTaxo\Admin\SettingsPage::OVERVIEW_SLUG], admin_url($zerisetaxo_parent_page));
$zerisetaxo_tab_settings_url  = add_query_arg(['page' => \ZeriseTaxo\Admin\SettingsPage::MENU_SLUG, 'subtab' => 'general'], admin_url($zerisetaxo_parent_page));
$zerisetaxo_tab_advanced_url  = add_query_arg(['page' => \ZeriseTaxo\Admin\SettingsPage::MENU_SLUG, 'subtab' => 'advanced'], admin_url($zerisetaxo_parent_page));
$zerisetaxo_tab_order_url     = add_query_arg(['page' => \ZeriseTaxo\Admin\SettingsPage::ORDER_SLUG], admin_url($zerisetaxo_parent_page));
$zerisetaxo_tab_guide_url     = add_query_arg(['page' => \ZeriseTaxo\Admin\SettingsPage::GUIDE_SLUG], admin_url($zerisetaxo_parent_page));

$is_guide    = ('guide' === $active_tab);
$is_order    = ('category-order' === $active_tab);
$is_overview = ('dashboard' === $active_tab);
$is_advanced = ('advanced' === $raw_subtab || 'diagnostics' === $raw_subtab);
$is_general  = (!$is_guide && !$is_order && !$is_overview && !$is_advanced);

$enable_llm_saved = isset($enable_llm_saved) ? (bool) $enable_llm_saved : (bool) get_option('zerisetaxo_enable_llm', false);
$has_gemini = $enable_llm_saved && (!empty($has_llm_key) || !empty($is_llm_key_const));
?>
<div class="wrap zerisetaxo-taxonomy-dashboard-wrap" style="max-width: 100%; margin-top: 10px; margin-right: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">

    <!-- 1. CLEAN NATIVE WORDPRESS HEADING -->
    <div style="margin-bottom: 16px; padding: 4px 0;">
        <h1 class="wp-heading-inline" style="font-size: 22px; font-weight: 700; color: #0F172A; margin: 0; padding: 0; display: inline-flex; align-items: center; gap: 8px;">
            <?php esc_html_e('ZeriseTaxo', 'zerisetaxo'); ?>
            <span style="font-size: 11px; font-weight: 600; color: #64748B; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 4px; padding: 1px 6px;">v1.1.0</span>
        </h1>
        <a href="<?php echo esc_url(admin_url('post-new.php')); ?>" class="button button-primary" style="margin-left: 12px; font-weight: 600; font-size: 12.5px; border-radius: 6px; height: 32px; line-height: 30px; display: inline-flex; align-items: center; gap: 6px; padding: 0 14px; background: #4F46E5; border: 1px solid #4338CA; color: #FFFFFF; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25); text-decoration: none; transition: all 0.15s ease;">
            <i class="fa-solid fa-pen-to-square" style="color: #C7D2FE; font-size: 12px;"></i>
            <span><?php esc_html_e('Test in Post Editor', 'zerisetaxo'); ?></span>
        </a>
    </div>

    <!-- 2. UNIFIED TELEMETRY STRIP (COHESIVE 4-CELL SUMMARY, ZERO AI-MOCKUP FEEL) -->
    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02); display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <!-- Metric 1 -->
        <div style="padding: 14px 20px; border-right: 1px solid #F1F5F9;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-newspaper" style="color: #4F46E5; font-size: 12px;"></i>
                <?php esc_html_e('Published Posts', 'zerisetaxo'); ?>
            </div>
            <div style="font-size: 18px; font-weight: 800; color: #0F172A; margin-top: 3px;">
                <?php echo esc_html(number_format_i18n((int) ($total_posts ?? 0))); ?>
                <span style="font-size: 11px; font-weight: 500; color: #94A3B8; margin-left: 4px;"><?php esc_html_e('indexed', 'zerisetaxo'); ?></span>
            </div>
        </div>

        <!-- Metric 2 -->
        <div style="padding: 14px 20px; border-right: 1px solid #F1F5F9;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-folder-tree" style="color: #4F46E5; font-size: 12px;"></i>
                <?php esc_html_e('Categories', 'zerisetaxo'); ?>
            </div>
            <div style="font-size: 18px; font-weight: 800; color: #0F172A; margin-top: 3px;">
                <?php echo esc_html(number_format_i18n((int) ($total_categories ?? 0))); ?>
                <span style="font-size: 11px; font-weight: 500; color: #94A3B8; margin-left: 4px;"><?php esc_html_e('terms', 'zerisetaxo'); ?></span>
            </div>
        </div>

        <!-- Metric 3 -->
        <div style="padding: 14px 20px; border-right: 1px solid #F1F5F9;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-tags" style="color: #4F46E5; font-size: 12px;"></i>
                <?php esc_html_e('Post Tags', 'zerisetaxo'); ?>
            </div>
            <div style="font-size: 18px; font-weight: 800; color: #0F172A; margin-top: 3px;">
                <?php 
                $zerisetaxo_total_tags = (int) wp_count_terms(['taxonomy' => 'post_tag', 'hide_empty' => false]);
                echo esc_html(number_format_i18n($zerisetaxo_total_tags));
                ?>
                <span style="font-size: 11px; font-weight: 500; color: #94A3B8; margin-left: 4px;"><?php esc_html_e('tags', 'zerisetaxo'); ?></span>
            </div>
        </div>

        <!-- Metric 4 -->
        <div style="padding: 14px 20px;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-microchip" style="color: #4F46E5; font-size: 12px;"></i>
                <?php esc_html_e('Engine Mode', 'zerisetaxo'); ?>
            </div>
            <div style="font-size: 13.5px; font-weight: 700; color: #0F172A; margin-top: 4px;">
                <?php if ($has_gemini) : ?>
                    <span style="color: #059669; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fa-solid fa-wand-magic-sparkles" style="font-size: 12px;"></i> <?php esc_html_e('PHP Local + Gemini AI', 'zerisetaxo'); ?>
                    </span>
                <?php else : ?>
                    <span style="color: #4F46E5; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fa-solid fa-bolt" style="font-size: 12px;"></i> <?php esc_html_e('100% PHP-Native Offline', 'zerisetaxo'); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 3. SINGLE CLEAN NAVIGATION TAB BAR -->
    <div style="margin-bottom: 22px; border-bottom: 1px solid #E2E8F0; padding-bottom: 12px;">
        <nav style="display: inline-flex; background: #F1F5F9; border-radius: 8px; padding: 4px; gap: 4px;">
            <a href="<?php echo esc_url($zerisetaxo_tab_overview_url); ?>" 
               style="padding: 8px 18px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s ease; <?php echo $is_overview ? 'background: #FFFFFF; color: #0F172A; box-shadow: 0 1px 3px rgba(0,0,0,0.08);' : 'color: #64748B;'; ?>">
                <i class="fa-solid fa-chart-line" style="font-size: 13px; color: <?php echo $is_overview ? '#4F46E5' : '#64748B'; ?>;"></i>
                <span><?php esc_html_e('Overview', 'zerisetaxo'); ?></span>
            </a>
            <a href="<?php echo esc_url($zerisetaxo_tab_settings_url); ?>" 
               style="padding: 8px 18px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s ease; <?php echo $is_general ? 'background: #FFFFFF; color: #0F172A; box-shadow: 0 1px 3px rgba(0,0,0,0.08);' : 'color: #64748B;'; ?>">
                <i class="fa-solid fa-sliders" style="font-size: 13px; color: <?php echo $is_general ? '#4F46E5' : '#64748B'; ?>;"></i>
                <span><?php esc_html_e('General Settings', 'zerisetaxo'); ?></span>
            </a>

            <a href="<?php echo esc_url($zerisetaxo_tab_advanced_url); ?>"
               style="padding: 8px 18px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s ease; <?php echo $is_advanced ? 'background: #FFFFFF; color: #0F172A; box-shadow: 0 1px 3px rgba(0,0,0,0.08);' : 'color: #64748B;'; ?>">
                <i class="fa-solid fa-gears" style="font-size: 13px; color: <?php echo $is_advanced ? '#4F46E5' : '#64748B'; ?>;"></i>
                <span><?php esc_html_e('Advanced Engine Options', 'zerisetaxo'); ?></span>
            </a>

            <a href="<?php echo esc_url($zerisetaxo_tab_order_url); ?>"
               style="padding: 8px 18px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s ease; <?php echo $is_order ? 'background: #FFFFFF; color: #0F172A; box-shadow: 0 1px 3px rgba(0,0,0,0.08);' : 'color: #64748B;'; ?>">
                <i class="fa-solid fa-arrow-down-up-across-line" style="font-size: 13px; color: <?php echo $is_order ? '#4F46E5' : '#64748B'; ?>;"></i>
                <span><?php esc_html_e('Category Order', 'zerisetaxo'); ?></span>
            </a>

            <a href="<?php echo esc_url($zerisetaxo_tab_guide_url); ?>" 
               style="padding: 8px 18px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s ease; <?php echo $is_guide ? 'background: #FFFFFF; color: #0F172A; box-shadow: 0 1px 3px rgba(0,0,0,0.08);' : 'color: #64748B;'; ?>">
                <i class="fa-solid fa-book-open" style="font-size: 13px; color: <?php echo $is_guide ? '#4F46E5' : '#64748B'; ?>;"></i>
                <span><?php esc_html_e('User Guide', 'zerisetaxo'); ?></span>
            </a>
        </nav>
    </div>

    <?php
    if ($is_guide) {
        include ZERISETAXO_PATH . 'admin/views/user-guide.php';
    } elseif ($is_order) {
        include ZERISETAXO_PATH . 'admin/views/category-order.php';
    } elseif ('suggestions' === $active_tab) {
        include ZERISETAXO_PATH . 'admin/views/suggestions.php';
    } elseif ($is_overview) {
        include ZERISETAXO_PATH . 'admin/views/status-dashboard.php';
    } else {
        // Pass the resolved subtab cleanly into settings.php
        $subtab = $is_advanced ? 'advanced' : 'general';
        include ZERISETAXO_PATH . 'admin/views/settings.php';
    }
    ?>
</div>
