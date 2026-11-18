<?php
namespace ZeriseTaxo\Admin;

defined('ABSPATH') || exit;

/**
 * Handles the compact optional ZeriseTaxo column and row actions on the native WordPress Posts list table.
 */
class PostsListColumn {

    /**
     * Register hooks.
     */
    public function register() {
        if (!is_admin()) {
            return;
        }

        add_filter('manage_posts_columns', [$this, 'add_column']);
        add_action('manage_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('post_row_actions', [$this, 'add_row_actions'], 10, 2);
    }

    /**
     * Add ZeriseTaxo column to the Posts list table.
     *
     * @param array $columns
     * @return array
     */
    public function add_column(array $columns): array {
        $new_columns = [];

        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            // Place ZeriseTaxo column directly after the Categories column
            if ('categories' === $key) {
                $new_columns['zerisetaxo_status'] = __('ZeriseTaxo', 'zerisetaxo');
            }
        }

        // Fallback if 'categories' column is absent
        if (!isset($new_columns['zerisetaxo_status'])) {
            $new_columns['zerisetaxo_status'] = __('ZeriseTaxo', 'zerisetaxo');
        }

        return $new_columns;
    }

    /**
     * Render post cell contents using existing saved metadata.
     * ZERO ML INFERENCE executed during table rendering.
     *
     * @param string $column_name
     * @param int $post_id
     */
    public function render_column(string $column_name, int $post_id) {
        if ('zerisetaxo_status' !== $column_name) {
            return;
        }

        $saved_category   = get_post_meta($post_id, '_zerisetaxo_predicted_category', true);
        if (empty($saved_category)) {
            $saved_category = get_post_meta($post_id, '_ml_predicted_category', true);
        }

        $saved_confidence = get_post_meta($post_id, '_zerisetaxo_prediction_confidence', true);
        if ('' === $saved_confidence || null === $saved_confidence) {
            $saved_confidence = get_post_meta($post_id, '_ml_prediction_confidence', true);
        }

        $saved_state = get_post_meta($post_id, '_zerisetaxo_prediction_state', true);
        if (empty($saved_state)) {
            $saved_state = get_post_meta($post_id, '_ml_prediction_state', true);
        }

        // Check if category has been applied to post
        $post_categories = wp_get_post_categories($post_id, ['fields' => 'names']);
        $has_applied = !empty($saved_category) && 'out_of_distribution' !== $saved_state && in_array($saved_category, $post_categories, true);

        if (empty($saved_category) && empty($saved_state)) {
            echo '<span class="zerisetaxo-status-badge idle" style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1;">' . esc_html__('Not checked', 'zerisetaxo') . '</span>';
        } elseif ($has_applied) {
            echo '<span class="zerisetaxo-status-badge applied" style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#dcfce7; color:#15803d; border:1px solid #86efac;">✓ ' . esc_html($saved_category) . '</span>';
        } elseif ('ambiguous' === $saved_state) {
            echo '<span class="zerisetaxo-status-badge review" style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#fef3c7; color:#b45309; border:1px solid #fde68a;">' . esc_html__('Needs review', 'zerisetaxo') . '</span>';
        } elseif ('out_of_distribution' === $saved_state) {
            echo '<span class="zerisetaxo-status-badge nomatch" style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#f3f4f6; color:#4b5563; border:1px solid #d1d5db;">' . esc_html__('No strong match', 'zerisetaxo') . '</span>';
        } else {
            echo '<span class="zerisetaxo-status-badge suggested" style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;">' . sprintf(/* translators: %s: Suggested category name. */
esc_html__('Suggested: %s', 'zerisetaxo'), esc_html($saved_category)) . '</span>';
        }
    }

    /**
     * Add compact row action links for ZeriseTaxo review / suggestion.
     *
     * @param array $actions
     * @param \WP_Post $post
     * @return array
     */
    public function add_row_actions(array $actions, \WP_Post $post): array {
        if ('post' !== $post->post_type || !current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $edit_url = get_edit_post_link($post->ID);
        if (!$edit_url) {
            return $actions;
        }

        $saved_category = get_post_meta($post->ID, '_zerisetaxo_predicted_category', true);
        if (empty($saved_category)) {
            $saved_category = get_post_meta($post->ID, '_ml_predicted_category', true);
        }

        $label = !empty($saved_category) ? __('Review Category', 'zerisetaxo') : __('Suggest Category', 'zerisetaxo');
        $actions['zerisetaxo_action'] = sprintf(
            '<a href="%s#zerisetaxo_metabox" title="%s" style="color: #1a56db; font-weight: 600;">%s</a>',
            esc_url($edit_url),
            esc_attr($label),
            esc_html($label)
        );

        return $actions;
    }
}
