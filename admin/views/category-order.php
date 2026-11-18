<?php
/**
 * Category Order & Ranking Strategy View for ZeriseTaxo.
 *
 * Provides dedicated drag-and-drop category prioritization and ranking mode selection,
 * compatible with patterns established by Category Order and Taxonomy Terms Order.
 *
 * @package ZeriseTaxo
 */

defined('ABSPATH') || exit;

$category_order_mode        = (string) get_option('zerisetaxo_category_order_mode', 'custom_order');
$custom_category_order_str  = (string) get_option('zerisetaxo_custom_category_order', '');

$zerisetaxo_all_cats = get_categories(['hide_empty' => false]);

if (!empty($zerisetaxo_all_cats) && !is_wp_error($zerisetaxo_all_cats)) {
    if ('post_count' === $category_order_mode) {
        usort($zerisetaxo_all_cats, function ($a, $b) {
            if ($a->count === $b->count) {
                return strcasecmp($a->name, $b->name);
            }
            return $b->count <=> $a->count;
        });
    } elseif ('custom_order' === $category_order_mode && !empty($custom_category_order_str)) {
        $saved_order_ids = array_map('intval', explode(',', $custom_category_order_str));
        $order_map       = array_flip($saved_order_ids);
        usort($zerisetaxo_all_cats, function ($a, $b) use ($order_map) {
            $pos_a = $order_map[$a->term_id] ?? 999999;
            $pos_b = $order_map[$b->term_id] ?? 999999;
            if ($pos_a === $pos_b) {
                return strcasecmp($a->name, $b->name);
            }
            return $pos_a <=> $pos_b;
        });
    }
}

$is_custom_initial  = ('custom_order' === $category_order_mode);
$initial_icon       = $is_custom_initial ? 'fa-solid fa-hand-pointer' : 'fa-solid fa-lock';
$initial_icon_color = $is_custom_initial ? '#4F46E5' : '#64748B';
?>
<style>
/* Tooltip styles for ranking strategy pills */
.zerisetaxo-segmented-control {
    overflow: visible !important;
}
.zerisetaxo-segmented-option {
    position: relative;
    cursor: pointer;
    margin: 0 !important;
}
.zerisetaxo-strategy-tooltip {
    position: absolute;
    bottom: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%) translateY(4px);
    background: #0F172A;
    color: #F8FAFC;
    font-size: 11.5px;
    font-weight: 400;
    line-height: 1.45;
    padding: 8px 12px;
    border-radius: 6px;
    white-space: normal;
    width: 220px;
    text-align: center;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.3), 0 8px 10px -6px rgba(15, 23, 42, 0.2);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s ease;
    z-index: 9999;
}
.zerisetaxo-strategy-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border-width: 6px;
    border-style: solid;
    border-color: #0F172A transparent transparent transparent;
}
.zerisetaxo-segmented-option:hover .zerisetaxo-strategy-tooltip,
.zerisetaxo-segmented-option:focus-within .zerisetaxo-strategy-tooltip {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(0);
}

/* Category list styles */
#zerisetaxo-sortable-categories {
    list-style: none !important;
    margin: 0 !important;
    padding: 0 !important;
}
#zerisetaxo-sortable-categories li {
    transition: box-shadow 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}
#zerisetaxo-sortable-categories.zerisetaxo-sortable-active li {
    cursor: grab !important;
}
#zerisetaxo-sortable-categories.zerisetaxo-sortable-active li:hover {
    border-color: #818CF8 !important;
    background: #FAFAFF !important;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.08) !important;
}
#zerisetaxo-sortable-categories.zerisetaxo-sortable-active li:active {
    cursor: grabbing !important;
}
#zerisetaxo-sortable-categories li.ui-sortable-helper {
    background: #FFFFFF !important;
    box-shadow: 0 16px 32px -4px rgba(15, 23, 42, 0.2), 0 8px 16px -2px rgba(79, 70, 229, 0.18) !important;
    border: 1.5px solid #4F46E5 !important;
    cursor: grabbing !important;
    transform: scale(1.015);
    z-index: 9999 !important;
}
#zerisetaxo-sortable-categories li.zerisetaxo-sortable-placeholder {
    background: #EEF2FF !important;
    border: 2px dashed #818CF8 !important;
    border-radius: 8px !important;
    height: 48px !important;
    margin-bottom: 8px !important;
    visibility: visible !important;
}
.zerisetaxo-drag-handle {
    user-select: none;
    touch-action: none;
    transition: color 0.15s ease, opacity 0.15s ease;
}
#zerisetaxo-sortable-categories.zerisetaxo-sortable-active li:hover .zerisetaxo-drag-handle {
    color: #4F46E5 !important;
}
</style>

<div class="zerisetaxo-category-order-content" style="max-width: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <?php settings_errors(); ?>

    <form method="post" action="options.php" id="zerisetaxo-category-order-form">
        <?php settings_fields('zerisetaxo_settings'); ?>
        <input type="hidden" name="zerisetaxo_settings_tab" value="order" />
        <input type="hidden" name="zerisetaxo_custom_category_order" id="zerisetaxo_custom_category_order" value="<?php echo esc_attr($custom_category_order_str); ?>" />

        <div class="zerisetaxo-two-col" style="display: flex; gap: 24px; align-items: flex-start;">

            <!-- MAIN COLUMN (LEFT 72%) -->
            <div class="zerisetaxo-col-main" style="flex: 1; min-width: 0;">

                <!-- 1. STRATEGY SELECTION CARD -->
                <div class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 22px; margin-bottom: 20px; overflow: visible;">
                    <div style="margin-bottom: 16px; border-bottom: 1px solid #F1F5F9; padding-bottom: 14px;">
                        <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-arrow-down-up-across-line" style="color: #4F46E5; font-size: 14px;"></i>
                            <span><?php esc_html_e('Category Ranking & Ordering Strategy', 'zerisetaxo'); ?></span>
                        </h3>
                        <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.45;">
                            <?php esc_html_e('Select how taxonomy recommendation rankings, editor chips, and site-wide categories are ordered.', 'zerisetaxo'); ?>
                        </p>
                    </div>

                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; overflow: visible;">
                        <div>
                            <span style="display: block; font-weight: 600; font-size: 13.5px; color: #0F172A; margin-bottom: 2px;">
                                <?php esc_html_e('Primary Ranking Strategy', 'zerisetaxo'); ?>
                            </span>
                            <span id="zerisetaxo-strategy-hint" style="font-size: 12.5px; color: #64748B;">
                                <?php
                                if ('custom_order' === $category_order_mode) {
                                    esc_html_e('Drag and drop any category below to customize order priority.', 'zerisetaxo');
                                } elseif ('post_count' === $category_order_mode) {
                                    esc_html_e('Categories are sorted by post volume (most popular first).', 'zerisetaxo');
                                } else {
                                    esc_html_e('Categories use standard WordPress ordering, with AI confidence matching for post suggestions.', 'zerisetaxo');
                                }
                                ?>
                            </span>
                        </div>

                        <div class="zerisetaxo-segmented-control" id="zerisetaxo-order-mode-selector" style="overflow: visible;">
                            <!-- Option 1: Custom Drag & Drop -->
                            <label class="zerisetaxo-segmented-option" title="<?php esc_attr_e('Custom Drag & Drop: Complete manual control over site-wide and suggestion category ordering.', 'zerisetaxo'); ?>">
                                <input type="radio" name="zerisetaxo_category_order_mode" value="custom_order" <?php checked('custom_order', $category_order_mode); ?> />
                                <span class="zerisetaxo-segmented-pill">
                                    <i class="fa-solid fa-arrow-down-up-across-line" style="font-size: 11px;"></i>
                                    <?php esc_html_e('Custom Drag & Drop', 'zerisetaxo'); ?>
                                    <i class="fa-solid fa-circle-info" style="font-size: 10px; opacity: 0.6; margin-left: 2px;"></i>
                                </span>
                                <div class="zerisetaxo-strategy-tooltip" role="tooltip">
                                    <strong style="color: #A5B4FC; display: block; margin-bottom: 2px;"><?php esc_html_e('Custom Drag & Drop', 'zerisetaxo'); ?></strong>
                                    <?php esc_html_e('Gives you complete manual control. Click and drag any category card below into your exact preferred priority order.', 'zerisetaxo'); ?>
                                </div>
                            </label>

                            <!-- Option 2: Post Count -->
                            <label class="zerisetaxo-segmented-option" title="<?php esc_attr_e('Post Count: Ranks categories by total published posts in descending order.', 'zerisetaxo'); ?>">
                                <input type="radio" name="zerisetaxo_category_order_mode" value="post_count" <?php checked('post_count', $category_order_mode); ?> />
                                <span class="zerisetaxo-segmented-pill">
                                    <i class="fa-solid fa-chart-simple" style="font-size: 11px;"></i>
                                    <?php esc_html_e('Post Count (Popular First)', 'zerisetaxo'); ?>
                                    <i class="fa-solid fa-circle-info" style="font-size: 10px; opacity: 0.6; margin-left: 2px;"></i>
                                </span>
                                <div class="zerisetaxo-strategy-tooltip" role="tooltip">
                                    <strong style="color: #A5B4FC; display: block; margin-bottom: 2px;"><?php esc_html_e('Post Count', 'zerisetaxo'); ?></strong>
                                    <?php esc_html_e('Orders categories by total assigned posts in descending order. Most popular topics always take top priority.', 'zerisetaxo'); ?>
                                </div>
                            </label>

                            <!-- Option 3: Default (WordPress / AI) -->
                            <label class="zerisetaxo-segmented-option" title="<?php esc_attr_e('Default (WordPress / AI): Preserves standard WordPress category order while utilizing AI matching for suggestions.', 'zerisetaxo'); ?>">
                                <input type="radio" name="zerisetaxo_category_order_mode" value="confidence" <?php checked('confidence', $category_order_mode); ?> />
                                <span class="zerisetaxo-segmented-pill">
                                    <i class="fa-solid fa-wand-magic-sparkles" style="font-size: 11px;"></i>
                                    <?php esc_html_e('Default (WordPress / AI)', 'zerisetaxo'); ?>
                                    <i class="fa-solid fa-circle-info" style="font-size: 10px; opacity: 0.6; margin-left: 2px;"></i>
                                </span>
                                <div class="zerisetaxo-strategy-tooltip" role="tooltip">
                                    <strong style="color: #A5B4FC; display: block; margin-bottom: 2px;"><?php esc_html_e('Default (WordPress / AI)', 'zerisetaxo'); ?></strong>
                                    <?php esc_html_e('Preserves standard WordPress category order across site queries, while dynamically utilizing AI confidence matching for post suggestions.', 'zerisetaxo'); ?>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- 2. SORTABLE CATEGORY LIST CARD -->
                <div class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 22px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid #F1F5F9; padding-bottom: 12px;">
                        <div>
                            <h3 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-folder-tree" style="color: #4F46E5; font-size: 14px;"></i>
                                <span><?php esc_html_e('Site Categories Priority Order', 'zerisetaxo'); ?></span>
                                <span style="font-size: 11px; font-weight: 600; color: #4F46E5; background: #EEF2FF; border-radius: 12px; padding: 2px 8px;">
                                    <?php echo esc_html(count($zerisetaxo_all_cats)); ?>
                                </span>
                            </h3>
                            <p style="margin: 0; font-size: 12.5px; color: #64748B;">
                                <?php esc_html_e('Order determines site display priority and suggestion rankings.', 'zerisetaxo'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- DYNAMIC DRAG STATUS & UNLOCK BANNER -->
                    <div id="zerisetaxo-drag-hint-banner" style="background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: #475569;">
                        <i id="zerisetaxo-drag-hint-icon" class="<?php echo esc_attr($initial_icon); ?>" style="color: <?php echo esc_attr($initial_icon_color); ?>; font-size: 13px;"></i>
                        <span id="zerisetaxo-drag-hint-text">
                            <?php if ($is_custom_initial) : ?>
                                <strong><?php esc_html_e('Custom Drag & Drop active:', 'zerisetaxo'); ?></strong> <?php esc_html_e('Click and drag any category card below to customize your priority order.', 'zerisetaxo'); ?>
                            <?php elseif ('post_count' === $category_order_mode) : ?>
                                <strong><?php esc_html_e('Locked (Post Count):', 'zerisetaxo'); ?></strong> <?php esc_html_e('Categories are automatically sorted by post volume.', 'zerisetaxo'); ?> <a href="#" class="zerisetaxo-switch-to-custom-link" style="color: #4F46E5; font-weight: 600; text-decoration: underline; margin-left: 4px;"><?php esc_html_e('Switch to Custom Drag & Drop', 'zerisetaxo'); ?></a> <?php esc_html_e('to reorder.', 'zerisetaxo'); ?>
                            <?php else : ?>
                                <strong><?php esc_html_e('Locked (Standard Order):', 'zerisetaxo'); ?></strong> <?php esc_html_e('Categories use default WordPress ordering.', 'zerisetaxo'); ?> <a href="#" class="zerisetaxo-switch-to-custom-link" style="color: #4F46E5; font-weight: 600; text-decoration: underline; margin-left: 4px;"><?php esc_html_e('Switch to Custom Drag & Drop', 'zerisetaxo'); ?></a> <?php esc_html_e('to reorder.', 'zerisetaxo'); ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if (empty($zerisetaxo_all_cats) || is_wp_error($zerisetaxo_all_cats)) : ?>
                        <div style="padding: 24px; text-align: center; color: #64748B; background: #F8FAFC; border-radius: 8px;">
                            <i class="fa-solid fa-circle-info" style="font-size: 20px; color: #94A3B8; margin-bottom: 8px;"></i>
                            <p style="margin: 0; font-size: 13px;"><?php esc_html_e('No categories found on this site.', 'zerisetaxo'); ?></p>
                        </div>
                    <?php else : ?>
                        <ul id="zerisetaxo-sortable-categories" class="<?php echo $is_custom_initial ? 'zerisetaxo-sortable-active' : ''; ?>">
                            <?php foreach ($zerisetaxo_all_cats as $idx => $cat_item) : ?>
                                <li data-term-id="<?php echo (int) $cat_item->term_id; ?>"
                                    data-post-count="<?php echo (int) $cat_item->count; ?>"
                                    data-name="<?php echo esc_attr($cat_item->name); ?>"
                                    title="<?php echo $is_custom_initial ? esc_attr__('Click and drag anywhere to move this category', 'zerisetaxo') : esc_attr__('Select Custom Drag & Drop above to reorder', 'zerisetaxo'); ?>"
                                    style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; gap: 12px; margin-bottom: 8px; cursor: <?php echo $is_custom_initial ? 'grab' : 'default'; ?>; user-select: none;">
                                    <span class="zerisetaxo-drag-handle" title="<?php echo $is_custom_initial ? esc_attr__('Drag anywhere on this item to reorder', 'zerisetaxo') : esc_attr__('Locked', 'zerisetaxo'); ?>" style="color: #94A3B8; font-size: 14px; cursor: <?php echo $is_custom_initial ? 'grab' : 'not-allowed'; ?>; opacity: <?php echo $is_custom_initial ? '1' : '0.4'; ?>; padding: 4px 6px; display: inline-flex; align-items: center;">
                                        <i class="fa-solid fa-grip-vertical"></i>
                                    </span>
                                    <span class="zerisetaxo-order-badge" style="background: #EEF2FF; color: #4F46E5; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; min-width: 24px; text-align: center;">
                                        #<?php echo (int) ($idx + 1); ?>
                                    </span>
                                    <span style="font-weight: 600; font-size: 13.5px; color: #0F172A;">
                                        <?php echo esc_html($cat_item->name); ?>
                                    </span>
                                    <span style="font-size: 11px; background: #F8FAFC; color: #64748B; padding: 2px 6px; border-radius: 4px; border: 1px solid #E2E8F0;">
                                        <?php echo esc_html($cat_item->slug); ?>
                                    </span>
                                    <span style="font-size: 11.5px; background: #F1F5F9; color: #475569; padding: 2px 8px; border-radius: 4px; margin-left: auto; font-weight: 500;">
                                        <?php echo esc_html(sprintf(/* translators: %d: post count */ _n('%d post', '%d posts', (int) $cat_item->count, 'zerisetaxo'), (int) $cat_item->count)); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- BOTTOM SAVE BUTTON -->
                <p class="submit" style="margin-top: 10px;">
                    <button type="submit" name="submit" id="submit" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 6px 20px; font-size: 14px; border-radius: 6px; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25); cursor: pointer;">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span><?php esc_html_e('Save Category Order', 'zerisetaxo'); ?></span>
                    </button>
                </p>
            </div>

            <!-- RIGHT PRO SIDEBAR (28% STICKY) -->
            <div class="zerisetaxo-col-sidebar" style="width: 280px; position: sticky; top: 48px; align-self: start;">

                <!-- STICKY ACTION CARD -->
                <div class="zerisetaxo-sidebar-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03); margin-bottom: 16px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748B; margin-bottom: 10px;">
                        <i class="fa-solid fa-sliders" style="color: #64748B; font-size: 11px; margin-right: 4px;"></i>
                        <?php esc_html_e('Actions', 'zerisetaxo'); ?>
                    </div>
                    <button type="submit" name="submit" class="button button-primary button-large" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 7px; font-weight: 600; padding: 8px 14px; font-size: 13px; border-radius: 6px; background: #4F46E5; border: 1px solid #4338CA; color: #FFFFFF; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25); cursor: pointer; transition: all 0.15s ease;">
                        <i class="fa-solid fa-floppy-disk" style="font-size: 12px; color: #C7D2FE;"></i>
                        <span><?php esc_html_e('Save Category Order', 'zerisetaxo'); ?></span>
                    </button>
                    <div style="margin-top: 8px; font-size: 11.5px; color: #64748B; line-height: 1.4; text-align: center; display: flex; align-items: center; justify-content: center; gap: 4px;">
                        <i class="fa-solid fa-shield-halved" style="color: #10B981; font-size: 11px;"></i>
                        <span><?php esc_html_e('Auto-synced across site & editor', 'zerisetaxo'); ?></span>
                    </div>
                </div>

                <!-- HOW IT WORKS CARD -->
                <div class="zerisetaxo-sidebar-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748B; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-lightbulb" style="color: #F59E0B; font-size: 12px;"></i>
                        <?php esc_html_e('How Order Influences Site', 'zerisetaxo'); ?>
                    </div>
                    <ul style="margin: 0; padding: 0 0 0 16px; font-size: 12px; color: #475569; line-height: 1.5; display: flex; flex-direction: column; gap: 8px;">
                        <li><strong><?php esc_html_e('Site-Wide Display:', 'zerisetaxo'); ?></strong> <?php esc_html_e('Categories across menus, widgets, and lists follow this order.', 'zerisetaxo'); ?></li>
                        <li><strong><?php esc_html_e('AI Tie-Breaker:', 'zerisetaxo'); ?></strong> <?php esc_html_e('When multiple categories match within 15% margin, the higher-placed category is selected.', 'zerisetaxo'); ?></li>
                        <li><strong><?php esc_html_e('Live Drag & Drop:', 'zerisetaxo'); ?></strong> <?php esc_html_e('Simply drag any item to re-prioritize.', 'zerisetaxo'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </form>
</div>