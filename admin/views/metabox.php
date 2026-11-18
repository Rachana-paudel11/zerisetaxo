<?php
/**
 * Editor Metabox View for ML Category Predictor.
 * Clean, distraction-free workflow for WordPress post editors:
 * Suggest -> Review -> Apply -> Ignore.
 * All ML complexity is moved to Global Settings -> Developer Diagnostics.
 *
 * @var string $saved_category
 * @var string $saved_confidence
 * @var int|string $saved_tier
 * @var string $saved_tier_name
 * @var string $saved_state
 * @var string $decision_source
 * @var int $saved_term_id
 * @var bool $can_create_categories
 * @var bool $is_admin
 * @var \WP_Post $post
 */
defined('ABSPATH') || exit;

$zerisetaxo_has_saved = !empty($saved_category) && $saved_category !== __('No prediction yet', 'zerisetaxo');
$zerisetaxo_conf_val = ($saved_confidence !== '' && $saved_confidence !== null) ? (float) $saved_confidence : null;

// Determine initial user-facing confidence label
if ('out_of_distribution' === $saved_state) {
    $zerisetaxo_initial_conf_label = __('No strong match', 'zerisetaxo');
} elseif ('ambiguous' === $saved_state) {
    $zerisetaxo_initial_conf_label = __('Review suggested', 'zerisetaxo');
} elseif ($zerisetaxo_conf_val !== null && $zerisetaxo_conf_val >= 0.80) {
    $zerisetaxo_initial_conf_label = __('Strong match', 'zerisetaxo');
} elseif ($zerisetaxo_conf_val !== null) {
    $zerisetaxo_initial_conf_label = __('Good match', 'zerisetaxo');
} else {
    $zerisetaxo_initial_conf_label = '';
}
?>
<div class="zerisetaxo-smart-category-container" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 13px; color: #1E293B;">

    <!-- STATUS NOTICE (Alerts/Messages) -->
    <div id="zerisetaxo-status-notice" class="notice inline hidden" style="margin: 0 0 12px; padding: 8px 12px; border-radius: 4px; font-size: 12.5px;">
        <span id="zerisetaxo-notice-text"></span>
    </div>

    <!-- PERMANENT CATEGORY SECTION HEADER -->
    <div id="zerisetaxo-category-section-header" style="margin-bottom: 10px;">
        <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-folder-open" style="color: #4F46E5; font-size: 13px; flex-shrink: 0;"></i>
            <span><?php esc_html_e('Category Suggestions', 'zerisetaxo'); ?></span>
        </h4>
    </div>

    <!-- 1. INITIAL STATE (Default when no prediction exists) -->
    <div id="zerisetaxo-state-initial" class="<?php echo $zerisetaxo_has_saved ? 'hidden' : ''; ?>" style="text-align: left; padding: 4px 0;">
        <p style="margin: 0 0 14px; font-size: 12.5px; color: #64748B; line-height: 1.4;">
            <?php esc_html_e('Get a category recommendation based on this post.', 'zerisetaxo'); ?>
        </p>
        <button type="button" id="zerisetaxo-suggest-btn" class="button button-primary button-large" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; font-weight: 600;">
            <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
            <span><?php esc_html_e('Suggest Category', 'zerisetaxo'); ?></span>
        </button>
    </div>

    <!-- 2. LOADING STATE -->
    <div id="zerisetaxo-state-loading" class="hidden" style="padding: 16px 0; text-align: center;">
        <div style="margin-bottom: 8px; color: #2563EB; font-size: 20px;">
            <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
        </div>
        <div style="font-size: 12.5px; color: #64748B; margin-top: 3px;">
            <?php esc_html_e('Finding the best category...', 'zerisetaxo'); ?>
        </div>
    </div>

    <?php
    $zerisetaxo_has_valid_rec = $zerisetaxo_has_saved && 'out_of_distribution' !== $saved_state && 'ambiguous' !== $saved_state && ($saved_term_id > 0) && !empty($saved_category);
    $zerisetaxo_show_chips_section = !empty($saved_category) && ($saved_term_id > 0);
    ?>

    <!-- QUICK CATEGORY SUGGESTION CHIPS (Suggested like tags) -->
    <div id="zerisetaxo-category-chips-section" class="<?php echo $zerisetaxo_show_chips_section ? '' : 'hidden'; ?>" style="margin-bottom: 12px; border-bottom: 1px solid #F1F5F9; padding-bottom: 10px;">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #4F46E5; margin-bottom: 4px;">
            <?php esc_html_e('Suggested Categories', 'zerisetaxo'); ?>
        </div>
        <div id="zerisetaxo-category-chips-hint" style="font-size: 11px; color: #64748B; font-style: italic; margin-bottom: 6px;">
            <?php esc_html_e('Click on a category to apply', 'zerisetaxo'); ?>
        </div>
        <div id="zerisetaxo-category-chips-container" style="display: flex; flex-wrap: wrap; gap: 6px;">
            <?php if (!empty($saved_category) && $saved_term_id > 0) : ?>
                <button type="button" class="zerisetaxo-cat-chip-btn" data-cat-name="<?php echo esc_attr($saved_category); ?>" data-term-id="<?php echo (int) $saved_term_id; ?>" data-is-new="false" title="<?php esc_attr_e('Click on the category to apply', 'zerisetaxo'); ?>" style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; border-radius: 14px; padding: 3px 10px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s ease;">
                    + <?php echo esc_html($saved_category); ?>
                </button>
            <?php endif; ?>
            <?php if (get_option('zerisetaxo_show_alternative', true) && !empty($saved_ru_category) && $saved_ru_term_id > 0 && $saved_ru_term_id !== $saved_term_id) : ?>
                <button type="button" class="zerisetaxo-cat-chip-btn" data-cat-name="<?php echo esc_attr($saved_ru_category); ?>" data-term-id="<?php echo (int) $saved_ru_term_id; ?>" data-is-new="false" title="<?php esc_attr_e('Click on the category to apply', 'zerisetaxo'); ?>" style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; border-radius: 14px; padding: 3px 10px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s ease;">
                    + <?php echo esc_html($saved_ru_category); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3. CONFIDENT / RECOMMENDED RESULT STATE -->
    <?php
    $zerisetaxo_has_valid_rec = $zerisetaxo_has_saved && 'out_of_distribution' !== $saved_state && 'ambiguous' !== $saved_state && ($saved_term_id > 0) && !empty($saved_category);
    ?>
    <div id="zerisetaxo-state-recommended" class="<?php echo $zerisetaxo_has_valid_rec ? '' : 'hidden'; ?>">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
            <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B;">
                <?php esc_html_e('Recommended', 'zerisetaxo'); ?>
            </div>
            <span id="zerisetaxo-engine-source-pill" class="<?php echo $zerisetaxo_has_saved ? '' : 'hidden'; ?>" style="font-size: 10.5px; font-weight: 600; flex-shrink: 0; display: inline-flex; align-items: center;">
                <?php if ('llm_gemini' === $decision_source || 'llm' === $decision_source) : ?>
                    <span class="zerisetaxo-engine-badge-gemini" title="<?php esc_attr_e('Classified via Gemini AI Fallback', 'zerisetaxo'); ?>"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php esc_html_e('Gemini AI', 'zerisetaxo'); ?></span>
                <?php elseif ($zerisetaxo_has_saved) : ?>
                    <span class="zerisetaxo-engine-badge-local" title="<?php esc_attr_e('Classified locally in < 1ms', 'zerisetaxo'); ?>"><i class="fa-solid fa-bolt"></i> <?php esc_html_e('Local ML', 'zerisetaxo'); ?></span>
                <?php endif; ?>
            </span>
        </div>

        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 12px 14px; margin-bottom: 12px;">
            <div style="display: flex; align-items: baseline; gap: 6px;">
                <span id="zerisetaxo-rec-category-name" style="font-size: 16px; font-weight: 700; color: #0F172A; word-break: break-word;">
                    <?php echo esc_html($saved_category); ?>
                </span>
            </div>
            <div id="zerisetaxo-rec-confidence-label" style="font-size: 12px; color: #64748B; margin-top: 4px;">
                <?php echo esc_html($zerisetaxo_initial_conf_label ?: __('Strong match', 'zerisetaxo')); ?>
            </div>
        </div>

        <button type="button" id="zerisetaxo-use-rec-btn" class="button button-primary button-large" style="width: 100%; margin-bottom: 12px; font-weight: 600; font-size: 13px; padding: 7px 16px; border-radius: 20px; cursor: pointer; transition: all 0.15s ease;" <?php echo ($saved_term_id > 0) ? '' : 'disabled="disabled"'; ?>>
            <i class="fa-solid fa-plus" aria-hidden="true" style="margin-right: 6px;"></i>
            <span id="zerisetaxo-use-rec-text"><?php esc_html_e('Apply Category', 'zerisetaxo'); ?></span>
        </button>

        <!-- ONE RUNNER-UP ALTERNATIVE -->
        <div id="zerisetaxo-alternative-box" class="hidden" style="border-top: 1px solid #F1F5F9; padding-top: 10px; margin-bottom: 12px;">
            <div style="font-size: 11.5px; font-weight: 600; color: #64748B; margin-bottom: 4px;">
                <?php esc_html_e('Another option', 'zerisetaxo'); ?>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <span id="zerisetaxo-alt-category-name" style="font-weight: 600; font-size: 13.5px; color: #1E293B; word-break: break-word;"></span>
                <button type="button" id="zerisetaxo-use-alt-btn" class="button button-secondary button-small">
                    <?php esc_html_e('Use instead', 'zerisetaxo'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- 4. AMBIGUOUS RESULT STATE -->
    <?php
    $zerisetaxo_is_valid_ambig   = ($zerisetaxo_has_saved && 'ambiguous' === $saved_state && !empty($is_valid_ambiguous));
    $zerisetaxo_is_invalid_ambig = ($zerisetaxo_has_saved && 'ambiguous' === $saved_state && empty($is_valid_ambiguous));
    ?>
    <div id="zerisetaxo-state-ambiguous" class="<?php echo $zerisetaxo_is_valid_ambig ? '' : 'hidden'; ?>">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #D97706; margin-bottom: 6px;">
            <?php esc_html_e('Review Suggested', 'zerisetaxo'); ?>
        </div>
        <p style="margin: 0 0 10px; font-size: 12.5px; color: #475569;">
            <?php esc_html_e('Two categories look relevant.', 'zerisetaxo'); ?>
        </p>

        <div id="zerisetaxo-ambiguous-options" style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 10px 14px; margin-bottom: 12px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600; cursor: pointer;">
                <input type="radio" name="zerisetaxo_ambiguous_choice" id="zerisetaxo-ambig-opt-1" value="<?php echo esc_attr($saved_term_id); ?>" data-cat-name="<?php echo esc_attr($saved_category); ?>" style="margin-right: 6px;" checked />
                <span id="zerisetaxo-ambig-label-1"><?php echo esc_html($saved_category); ?></span>
            </label>
            <label style="display: block; font-weight: 600; cursor: pointer;">
                <input type="radio" name="zerisetaxo_ambiguous_choice" id="zerisetaxo-ambig-opt-2" value="<?php echo esc_attr($saved_ru_term_id ?? 0); ?>" data-cat-name="<?php echo esc_attr($saved_ru_category ?? ''); ?>" style="margin-right: 6px;" />
                <span id="zerisetaxo-ambig-label-2"><?php echo esc_html($saved_ru_category ?? ''); ?></span>
            </label>
        </div>

        <button type="button" id="zerisetaxo-use-ambiguous-btn" class="button button-primary" style="width: 100%; margin-bottom: 10px; font-weight: 600;">
            <?php esc_html_e('Use selected category', 'zerisetaxo'); ?>
        </button>
    </div>

    <!-- 5. NEW CATEGORY CANDIDATE STATE -->
    <?php
    $zerisetaxo_is_new_cat_saved = ($zerisetaxo_has_saved && ('no_existing_category' === $saved_state || 'new_category_suggestion' === ($saved_result_type ?? '')) && !empty($saved_suggested_category));
    ?>
    <div id="zerisetaxo-state-newcategory" class="<?php echo $zerisetaxo_is_new_cat_saved ? '' : 'hidden'; ?>">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #4F46E5; margin-bottom: 6px;">
            <?php esc_html_e('New Category Suggested', 'zerisetaxo'); ?>
        </div>
        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 12px 14px; margin-bottom: 12px;">
            <div style="display: flex; align-items: baseline; gap: 6px;">
                <i class="fa-solid fa-sparkles" style="color: #4F46E5; font-size: 14px; flex-shrink: 0;"></i>
                <span id="zerisetaxo-newcat-name" style="font-size: 15px; font-weight: 700; color: #0F172A; word-break: break-word;">
                    <?php echo esc_html($saved_suggested_category ?? ''); ?>
                </span>
            </div>
            <div style="font-size: 12px; color: #64748B; margin-top: 4px;">
                <?php esc_html_e('Topic identified, but this category does not exist on your site yet.', 'zerisetaxo'); ?>
            </div>
        </div>

        <?php if ($can_create_categories) : ?>
            <button type="button" id="zerisetaxo-create-use-btn" class="button button-primary" style="width: 100%; margin-bottom: 8px; font-weight: 600;">
                <i class="fa-solid fa-plus-circle" aria-hidden="true"></i>
                <span id="zerisetaxo-create-use-btn-text"><?php echo esc_html(sprintf(/* translators: %s: Category name. */
                __('Create & Apply "%s"', 'zerisetaxo'), $saved_suggested_category)); ?></span>
            </button>
        <?php endif; ?>

        <button type="button" class="zerisetaxo-choose-myself-action button button-secondary" style="width: 100%; margin-bottom: 10px;">
            <i class="fa-solid fa-list-ul" aria-hidden="true" style="color: #64748B;"></i>
            <span><?php esc_html_e('Choose Category Manually', 'zerisetaxo'); ?></span>
        </button>
    </div>

    <!-- 6. NO EXISTING CATEGORY / NO STRONG MATCH STATE -->
    <?php
    $zerisetaxo_is_nomatch_saved = ($zerisetaxo_has_saved && ('out_of_distribution' === $saved_state || $saved_term_id <= 0) && !$zerisetaxo_is_new_cat_saved);
    ?>
    <div id="zerisetaxo-state-nomatch" class="<?php echo $zerisetaxo_is_nomatch_saved ? '' : 'hidden'; ?>">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; margin-bottom: 6px;">
            <?php esc_html_e('No strong match', 'zerisetaxo'); ?>
        </div>
        <p style="margin: 0 0 10px; font-size: 12.5px; color: #475569; line-height: 1.4;">
            <?php esc_html_e('No strong match found. Try checking again or choose an existing category manually.', 'zerisetaxo'); ?>
        </p>

        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 10px;">
            <button type="button" class="zerisetaxo-repredict-link button button-primary" style="width: 100%; font-weight: 600;">
                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> <?php esc_html_e('Check again', 'zerisetaxo'); ?>
            </button>
            <button type="button" class="zerisetaxo-choose-myself-action button button-secondary" style="width: 100%;">
                <?php esc_html_e('Choose category manually', 'zerisetaxo'); ?>
            </button>
        </div>
    </div>

    <!-- 7. ERROR / INFERENCE FAILURE STATE -->
    <div id="zerisetaxo-state-error" class="<?php echo $zerisetaxo_is_invalid_ambig ? '' : 'hidden'; ?>" style="padding: 4px 0;">
        <p id="zerisetaxo-error-title" style="margin: 0 0 6px; font-size: 13px; font-weight: 600; color: #0F172A;">
            <?php echo $zerisetaxo_is_invalid_ambig
                ? esc_html__('Multiple categories were suggested, but one or more could not be verified. Please check again.', 'zerisetaxo')
                : esc_html__('Category suggestions are temporarily unavailable.', 'zerisetaxo'); ?>
        </p>
        <p id="zerisetaxo-error-desc" style="margin: 0 0 12px; font-size: 12.5px; color: #64748B;">
            <?php esc_html_e('You can still choose a category normally.', 'zerisetaxo'); ?>
        </p>
        <button type="button" class="zerisetaxo-repredict-link button button-secondary" style="width: 100%;">
            <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
            <span id="zerisetaxo-error-btn-text"><?php echo $zerisetaxo_is_invalid_ambig ? esc_html__('Check again', 'zerisetaxo') : esc_html__('Try again', 'zerisetaxo'); ?></span>
        </button>
    </div>

    <!-- CATEGORY SECONDARY ACTION ROW & DIAGNOSTICS ("Check again" + "Why this category?") -->
    <?php if ($is_admin) : ?>
        <?php
        if ($zerisetaxo_has_valid_rec) {
            $zerisetaxo_initial_summary_title = __('Why this category?', 'zerisetaxo');
        } elseif ($zerisetaxo_is_new_cat_saved) {
            $zerisetaxo_initial_summary_title = __('Why suggest a new category?', 'zerisetaxo');
        } elseif (!empty($zerisetaxo_is_invalid_ambig)) {
            $zerisetaxo_initial_summary_title = __('Why review suggested?', 'zerisetaxo');
        } else {
            $zerisetaxo_initial_summary_title = __('Why no match?', 'zerisetaxo');
        }
        ?>
        <?php
        $zerisetaxo_hide_bottom_btn = ($zerisetaxo_is_nomatch_saved || !empty($zerisetaxo_is_invalid_ambig));
        ?>
        <!-- CATEGORY SECONDARY ACTION ROW & DIAGNOSTICS ("Check again" + "Why this category?") -->
        <div style="margin-top: 10px; border-top: 1px solid #F1F5F9; padding-top: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <button type="button" id="zerisetaxo-bottom-repredict-btn" class="zerisetaxo-repredict-link <?php echo $zerisetaxo_hide_bottom_btn ? 'hidden' : ''; ?>">
                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                <span class="zerisetaxo-repredict-text"><?php esc_html_e('Check again', 'zerisetaxo'); ?></span>
            </button>
            <details id="zerisetaxo-admin-diagnostics-details" style="font-size: 12px; color: #64748B;">
                <summary>
                    <i class="fa-solid fa-circle-info" style="font-size: 11px;"></i>
                    <span id="diag-summary-title"><?php echo esc_html($zerisetaxo_initial_summary_title); ?></span>
                </summary>

            <div id="zerisetaxo-admin-diag-content" style="margin-top: 8px; font-size: 12px; color: #334155; line-height: 1.4;">
                <!-- Human Reasoning Paragraph -->
                <p id="diag-reasoning" style="margin: 0 0 10px; font-size: 12px; color: #475569; font-style: italic; background: #F8FAFC; padding: 8px 10px; border-left: 3px solid #CBD5E1; border-radius: 0 4px 4px 0;">
                    <?php
                    if (!empty($zerisetaxo_is_invalid_ambig)) {
                        echo esc_html__('Multiple potential categories were identified, but one or more could not be verified on your site. Click Check again to refresh predictions.', 'zerisetaxo');
                    } elseif (!empty($saved_reasoning)) {
                        echo esc_html($saved_reasoning);
                    } elseif ('out_of_distribution' === $saved_state) {
                        echo esc_html__('Content did not reach the threshold for a strong category match.', 'zerisetaxo');
                    } elseif ($zerisetaxo_has_saved) {
                        echo esc_html__('Recommendation generated based on post content signals.', 'zerisetaxo');
                    } else {
                        echo esc_html__('Run prediction to inspect recommendation metrics.', 'zerisetaxo');
                    }
                    ?>
                </p>

                <!-- Clean Compact Metadata Table -->
                <table style="width: 100%; border-collapse: collapse; font-size: 11.5px;">
                    <tr style="border-bottom: 1px solid #F1F5F9;">
                        <td style="padding: 4px 0; color: #64748B; font-weight: 500;"><?php esc_html_e('Match Status', 'zerisetaxo'); ?></td>
                        <td id="diag-state" style="padding: 4px 0; text-align: right; font-weight: 600; color: #0F172A;">
                            <?php
                            if ('out_of_distribution' === $saved_state) {
                                echo esc_html__('No Strong Match', 'zerisetaxo');
                            } elseif ('ambiguous' === $saved_state) {
                                echo esc_html__('Needs Review', 'zerisetaxo');
                            } elseif ($zerisetaxo_has_saved) {
                                echo esc_html__('Matched', 'zerisetaxo');
                            } else {
                                echo esc_html__('Not Checked', 'zerisetaxo');
                            }
                            ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #F1F5F9;">
                        <td style="padding: 4px 0; color: #64748B; font-weight: 500;"><?php esc_html_e('Engine', 'zerisetaxo'); ?></td>
                        <td id="diag-source" style="padding: 4px 0; text-align: right; font-weight: 600; color: #2563EB;">
                            <?php
                            $zerisetaxo_source_map = [
                                'php_site'   => 'Local Site Model',
                                'php_native' => 'Local ML Engine',
                                'zero_shot'  => 'Zero-Shot Classifier',
                                'llm'        => 'AI Assistant',
                                'hybrid'     => 'Hybrid Engine',
                            ];
                            echo esc_html($zerisetaxo_source_map[$decision_source] ?? ($decision_source ? ucfirst(str_replace('_', ' ', $decision_source)) : 'Local Engine'));
                            ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #F1F5F9;">
                        <td style="padding: 4px 0; color: #64748B; font-weight: 500;"><?php esc_html_e('Category Term ID', 'zerisetaxo'); ?></td>
                        <td id="diag-term-id" style="padding: 4px 0; text-align: right; font-weight: 600; color: #0F172A;">
                            <?php echo ($saved_term_id > 0) ? '#' . (int) $saved_term_id : '—'; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #64748B; font-weight: 500;"><?php esc_html_e('Confidence', 'zerisetaxo'); ?></td>
                        <td id="diag-confidence" style="padding: 4px 0; text-align: right; font-weight: 600; color: #059669;">
                             <?php echo esc_html(($saved_confidence !== '' && $saved_confidence !== null) ? round(((float) $saved_confidence) * 100, 1) . '%' : '—'); ?>
                        </td>
                    </tr>
                </table>
            </div>
        </details>
        </div>
    <?php endif; ?>

    <!-- TAG SUGGESTIONS SECTION (Always visible) -->
    <div id="zerisetaxo-tag-suggestions-section" style="margin-top: 16px; border-top: 1px solid #E2E8F0; padding-top: 12px; clear: both;">
        <h4 style="margin: 0 0 8px; font-size: 13px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 6px; white-space: nowrap;">
            <i class="fa-solid fa-tags" style="color: #2563EB;"></i>
            <span><?php esc_html_e('Tag Suggestions', 'zerisetaxo'); ?></span>
        </h4>

        <!-- Tag Loading Indicator -->
        <div id="zerisetaxo-tags-loading" class="hidden" style="font-size: 12px; color: #2563EB; padding: 6px 0;">
            <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> <?php esc_html_e('Finding tags...', 'zerisetaxo'); ?>
        </div>

        <!-- Tag Hint & Candidates Chips Box -->
        <div id="zerisetaxo-tags-hint-row" style="display: flex; align-items: center; justify-content: space-between; margin-top: 6px; margin-bottom: 6px;">
            <div id="zerisetaxo-tags-hint" class="hidden" style="font-size: 11px; font-weight: 600; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.05em;">
                <?php esc_html_e('Suggested Tags', 'zerisetaxo'); ?>
            </div>
            <button type="button" id="zerisetaxo-apply-all-tags-btn" class="hidden">
                <span>+ <?php esc_html_e('Apply All', 'zerisetaxo'); ?></span>
            </button>
        </div>
        <div id="zerisetaxo-tags-chips-container" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;"></div>

        <!-- Subtle Empty / Error State Message -->
        <div id="zerisetaxo-tags-status-msg" class="hidden" style="font-size: 12px; color: #64748B; font-style: italic; margin-top: 6px;"></div>

        <!-- Suggest Tags Action Button (High-Visibility Primary CTA) -->
        <div id="zerisetaxo-suggest-tags-wrapper" style="margin-top: 12px; margin-bottom: 10px;">
            <button type="button" id="zerisetaxo-suggest-tags-btn" class="button button-primary button-large" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 7px; font-weight: 600; font-size: 13px; padding: 8px 14px; border-radius: 6px; background: #2563EB !important; border-color: #1D4ED8 !important; color: #FFFFFF !important; box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25); cursor: pointer; transition: all 0.15s ease;">
                <i class="fa-solid fa-wand-magic-sparkles" style="color: #BFDBFE; font-size: 12px;"></i>
                <span id="zerisetaxo-suggest-tags-btn-text"><?php esc_html_e('Suggest Tags', 'zerisetaxo'); ?></span>
            </button>
        </div>

        <!-- TAG EXPLANATION PANEL ("Why these tags?") -->
        <details id="zerisetaxo-tags-explanation-details" class="hidden" style="margin-top: 10px; border-top: 1px dashed #E2E8F0; padding-top: 8px; font-size: 12px; color: #64748B;">
            <summary style="cursor: pointer; user-select: none; font-size: 11.5px; font-weight: 600; color: #2563EB; display: inline-flex; align-items: center; gap: 4px;">
                <i class="fa-solid fa-circle-info" style="font-size: 11px;"></i>
                <span><?php esc_html_e('Why these tags?', 'zerisetaxo'); ?></span>
            </summary>

            <div id="zerisetaxo-tags-explanation-content" style="margin-top: 8px; font-size: 12px; color: #334155; line-height: 1.4;">
                <p id="zerisetaxo-tags-group-reason" style="margin: 0 0 8px; font-size: 12px; color: #475569; font-style: italic; background: #F8FAFC; padding: 6px 10px; border-left: 3px solid #3B82F6; border-radius: 0 4px 4px 0;">
                    <?php esc_html_e('ZeriseTaxo found these terms to be strongly related to the post content.', 'zerisetaxo'); ?>
                </p>

                <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748B; margin-bottom: 4px;">
                    <?php esc_html_e('Suggested signals:', 'zerisetaxo'); ?>
                </div>
                <ul id="zerisetaxo-tags-reasons-list" style="margin: 0 0 8px; padding-left: 16px; font-size: 11.5px; color: #334155; line-height: 1.5;"></ul>

                <div style="font-size: 11px; color: #94A3B8; border-top: 1px solid #F1F5F9; padding-top: 4px; display: flex; justify-content: space-between;">
                    <span><?php esc_html_e('Source: Content analysis + semantic relevance', 'zerisetaxo'); ?></span>
                </div>
            </div>
        </details>
    </div>

</div>
