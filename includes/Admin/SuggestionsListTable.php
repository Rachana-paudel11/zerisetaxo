<?php
namespace ZeriseTaxo\Admin;

use ZeriseTaxo\Database\Schema;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List Table for AI Category Suggestions using standard WordPress WP_List_Table.
 */
class SuggestionsListTable extends \WP_List_Table {

    /**
     * Cached list of WordPress categories for merge dropdowns.
     *
     * @var array
     */
    protected $categories = [];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'suggestion',
            'plural'   => 'suggestions',
            'ajax'     => false,
        ]);

        $this->categories = get_categories([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
    }

    /**
     * Define the columns.
     *
     * @return array
     */
    public function get_columns() {
        return [
            'cb'                 => '<input type="checkbox" />',
            'post_title'         => __('Post', 'zerisetaxo'),
            'suggested_category' => __('Suggested Category', 'zerisetaxo'),
            'reason'             => __('Reason', 'zerisetaxo'),
            'status'             => __('Status', 'zerisetaxo'),
            'created_at'         => __('Suggested On', 'zerisetaxo'),
        ];
    }

    /**
     * Define sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return [
            'post_title'         => ['post_title', false],
            'suggested_category' => ['suggested_category', false],
            'status'             => ['status', false],
            'created_at'         => ['created_at', true],
        ];
    }

    /**
     * Define the primary column for responsive controls and row actions.
     *
     * @return string
     */
    protected function get_primary_column_name() {
        return 'post_title';
    }

    /**
     * Column checkbox for bulk operations.
     *
     * @param array|object $item
     * @return string
     */
    public function column_cb($item) {
        $item = (array) $item;
        return sprintf(
            '<input type="checkbox" name="suggestion_ids[]" value="%d" />',
            absint($item['id'] ?? 0)
        );
    }

    /**
     * Default column handler.
     *
     * @param array|object $item
     * @param string $column_name
     * @return string
     */
    public function column_default($item, $column_name) {
        $item = (array) $item;
        switch ($column_name) {
            case 'created_at':
                $created_at = $item['created_at'] ?? '';
                if (empty($created_at)) {
                    return '<span style="color:#8c8f94;">—</span>';
                }
                $timestamp = strtotime($created_at);
                $date_fmt  = get_option('date_format') . ' ' . get_option('time_format');
                return sprintf(
                    '<abbr title="%s">%s</abbr><br><small style="color:#646970;">%s</small>',
                    esc_attr($created_at),
                    esc_html(date_i18n($date_fmt, $timestamp)),
                    /* translators: %s: Human time diff */
                    esc_html(sprintf(__('%s ago', 'zerisetaxo'), human_time_diff($timestamp, current_time('timestamp'))))
                );

            case 'reason':
                if (empty($item['reason'])) {
                    return '<span style="color:#8c8f94;">—</span>';
                }
                return sprintf(
                    '<div class="zerisetaxo-reason-text" style="max-width: 400px; font-style: italic; color: #444;">%s</div>',
                    esc_html($item['reason'])
                );

            case 'status':
                return $this->render_status_badge($item);

            default:
                return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
        }
    }

    /**
     * Render the post title column with edit link.
     *
     * @param array|object $item
     * @return string
     */
    public function column_post_title($item) {
        $item      = (array) $item;
        $post_id   = absint($item['post_id'] ?? 0);
        $post      = get_post($post_id);
        $title     = !empty($item['post_title']) ? $item['post_title'] : ($post ? $post->post_title : __('(No title)', 'zerisetaxo'));
        $edit_link = get_edit_post_link($post_id);

        $output = '<strong>';
        if ($edit_link) {
            $output .= sprintf(
                '<a class="row-title" href="%s" aria-label="%s">%s</a>',
                esc_url($edit_link),
                /* translators: %s: Post title */
                esc_attr(sprintf(__('Edit &#8220;%s&#8221;', 'zerisetaxo'), $title)),
                esc_html($title)
            );
        } else {
            $output .= esc_html($title);
        }
        $output .= '</strong>';

        if ($post) {
            $post_status = get_post_status_object($post->post_status);
            if ($post_status && 'publish' !== $post->post_status) {
                $output .= ' — <span class="post-state">' . esc_html($post_status->label) . '</span>';
            }
        } else {
            $output .= ' — <span class="post-state" style="color:#d63638;">' . esc_html__('Post Deleted', 'zerisetaxo') . '</span>';
        }

        return $output;
    }

    /**
     * Render the suggested category column with per-row action buttons.
     *
     * @param array|object $item
     * @return string
     */
    public function column_suggested_category($item) {
        $item        = (array) $item;
        $id          = absint($item['id'] ?? 0);
        $category    = esc_html($item['suggested_category'] ?? '');
        $status      = $item['status'] ?? 'pending';

        $output = sprintf(
            '<div class="zerisetaxo-suggested-name"><strong style="font-size: 14px; color: #1d2327;">%s</strong></div>',
            $category
        );

        // Actions only available for pending suggestions
        if ('pending' === $status) {
            $output .= '<div class="row-actions visible zerisetaxo-action-buttons-group" style="margin-top: 8px; display: flex; flex-direction: column; gap: 8px;">';

            $output .= '<div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">';
            // Approve action
            $output .= sprintf(
                '<button type="button" class="button button-small button-primary zerisetaxo-btn-approve" data-id="%d" data-category="%s" style="background:#137333; border-color:#137333; color:#fff; display:inline-flex; align-items:center; gap:4px; font-weight:600;"><i class="fa-solid fa-check"></i> %s</button>',
                $id,
                esc_attr($item['suggested_category'] ?? ''),
                __('Approve & Create', 'zerisetaxo')
            );

            // Reject action
            $output .= sprintf(
                '<button type="button" class="button button-small zerisetaxo-btn-reject" style="color: #b32d2e; border-color: #d63638; display:inline-flex; align-items:center; gap:4px;" data-id="%d"><i class="fa-solid fa-xmark"></i> %s</button>',
                $id,
                __('Reject', 'zerisetaxo')
            );
            $output .= '</div>';

            // Merge action dropdown
            $output .= '<div class="zerisetaxo-merge-wrapper" style="display: flex; align-items: center; gap: 6px;">';
            $output .= sprintf('<label for="merge_term_%d" class="screen-reader-text">%s</label>', $id, __('Merge into existing category', 'zerisetaxo'));
            $output .= sprintf('<select id="merge_term_%d" class="zerisetaxo-merge-select" style="max-width: 170px; height: 28px; font-size: 12px;">', $id);
            $output .= '<option value="">' . esc_html__('— Merge into existing —', 'zerisetaxo') . '</option>';

            foreach ($this->categories as $cat) {
                $output .= sprintf(
                    '<option value="%d">%s</option>',
                    absint($cat->term_id),
                    esc_html($cat->name)
                );
            }
            $output .= '</select>';

            $output .= sprintf(
                '<button type="button" class="button button-small button-secondary zerisetaxo-btn-merge" data-id="%d" style="display:inline-flex; align-items:center; gap:4px;"><i class="fa-solid fa-code-merge"></i> %s</button>',
                $id,
                __('Merge', 'zerisetaxo')
            );
            $output .= '</div>';

            $output .= '</div>';
        } elseif ('approved' === $status) {
            $reviewer = !empty($item['reviewed_by']) ? get_userdata($item['reviewed_by']) : null;
            $output .= sprintf(
                '<div style="font-size: 11px; color: #1a7f37; margin-top: 4px;">%s%s</div>',
                __('Approved', 'zerisetaxo'),
                $reviewer ? ' by ' . esc_html($reviewer->display_name) : ''
            );
        } elseif ('merged' === $status) {
            $merged_term = !empty($item['merged_into_term_id']) ? get_term_by('id', $item['merged_into_term_id'], 'category') : null;
            $output .= sprintf(
                '<div style="font-size: 11px; color: #0969da; margin-top: 4px;">%s: <strong>%s</strong></div>',
                __('Merged into', 'zerisetaxo'),
                $merged_term ? esc_html($merged_term->name) : '#' . absint($item['merged_into_term_id'] ?? 0)
            );
        } elseif ('rejected' === $status) {
            $reviewer = !empty($item['reviewed_by']) ? get_userdata($item['reviewed_by']) : null;
            $output .= sprintf(
                '<div style="font-size: 11px; color: #cf222e; margin-top: 4px;">%s%s</div>',
                __('Rejected', 'zerisetaxo'),
                $reviewer ? ' by ' . esc_html($reviewer->display_name) : ''
            );
        }

        return $output;
    }

    /**
     * Render a color-coded status badge.
     *
     * @param array|object $item
     * @return string
     */
    public function render_status_badge($item) {
        $item   = (array) $item;
        $status = $item['status'] ?? '';
        switch ($status) {
            case 'pending':
                return '<span class="badge" style="background:#fcf0db; color:#996800; border:1px solid #ffd89c; padding:3px 8px; border-radius:10px; font-weight:600; font-size:11px;">● ' . esc_html__('Pending Review', 'zerisetaxo') . '</span>';
            case 'approved':
                return '<span class="badge" style="background:#dafbe1; color:#1a7f37; border:1px solid #aceebb; padding:3px 8px; border-radius:10px; font-weight:600; font-size:11px;">✓ ' . esc_html__('Approved', 'zerisetaxo') . '</span>';
            case 'merged':
                return '<span class="badge" style="background:#ddf4ff; color:#0969da; border:1px solid #b6e3ff; padding:3px 8px; border-radius:10px; font-weight:600; font-size:11px;">⇶ ' . esc_html__('Merged', 'zerisetaxo') . '</span>';
            case 'rejected':
                return '<span class="badge" style="background:#ffebe9; color:#cf222e; border:1px solid #ffcecb; padding:3px 8px; border-radius:10px; font-weight:600; font-size:11px;">✕ ' . esc_html__('Rejected', 'zerisetaxo') . '</span>';
            default:
                return esc_html($status);
        }
    }

    /**
     * Table status filter views (All, Pending, Approved, Rejected, Merged).
     *
     * @return array
     */
    protected function get_views() {
        global $wpdb;
        $table = esc_sql(Schema::get_table_name());

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status filter for admin table
        $current_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'pending';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table escaped via esc_sql
        $counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", OBJECT_K);

        $total_pending  = isset($counts['pending']) ? (int) $counts['pending']->count : 0;
        $total_approved = isset($counts['approved']) ? (int) $counts['approved']->count : 0;
        $total_rejected = isset($counts['rejected']) ? (int) $counts['rejected']->count : 0;
        $total_merged   = isset($counts['merged']) ? (int) $counts['merged']->count : 0;
        $total_all      = $total_pending + $total_approved + $total_rejected + $total_merged;

        $base_url = add_query_arg([
            'page' => 'zerisetaxo',
            'tab'  => 'suggestions',
        ], admin_url('edit.php'));

        $views = [
            'pending' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('status', 'pending', $base_url)),
                'pending' === $current_status ? 'current' : '',
                __('Pending', 'zerisetaxo'),
                $total_pending
            ),
            'all' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('status', 'all', $base_url)),
                'all' === $current_status ? 'current' : '',
                __('All', 'zerisetaxo'),
                $total_all
            ),
            'approved' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('status', 'approved', $base_url)),
                'approved' === $current_status ? 'current' : '',
                __('Approved', 'zerisetaxo'),
                $total_approved
            ),
            'merged' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('status', 'merged', $base_url)),
                'merged' === $current_status ? 'current' : '',
                __('Merged', 'zerisetaxo'),
                $total_merged
            ),
            'rejected' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('status', 'rejected', $base_url)),
                'rejected' === $current_status ? 'current' : '',
                __('Rejected', 'zerisetaxo'),
                $total_rejected
            ),
        ];

        return $views;
    }

    /**
     * Text displayed when no items are found.
     */
    public function no_items() {
        esc_html_e('All caught up! There are no category suggestions waiting for review.', 'zerisetaxo');
    }

    /**
     * Prepare table items for rendering.
     */
    public function prepare_items() {
        global $wpdb;

        $table        = esc_sql(Schema::get_table_name());
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $offset       = ($current_page - 1) * $per_page;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status filtering in admin table
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'pending';

        $where_clauses = [];
        $params        = [];

        if (!empty($status) && 'all' !== $status) {
            $where_clauses[] = 's.status = %s';
            $params[]        = $status;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Order and sorting
        $orderby_allowed = ['created_at', 'suggested_category', 'status', 'post_title'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting in admin table
        $raw_orderby     = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'created_at';
        $orderby         = in_array($raw_orderby, $orderby_allowed, true) ? $raw_orderby : 'created_at';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort order in admin table
        $raw_order       = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc';
        $order           = 'asc' === strtolower($raw_order) ? 'ASC' : 'DESC';

        // Count total
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table escaped via esc_sql, params prepared
            $count_query = "SELECT COUNT(*) FROM {$table} s {$where_sql}";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total_items = (int) $wpdb->get_var($wpdb->prepare($count_query, ...$params));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table escaped via esc_sql
            $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} s");
        }

        // Fetch rows
        $query_params   = array_merge($params, [$per_page, $offset]);
        $order_col      = ('post_title' === $orderby) ? 'p.post_title' : 's.' . $orderby;

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic query with validated order column and splat params
        $sql = $wpdb->prepare(
            "SELECT s.*, p.post_title, p.post_status 
             FROM {$table} s 
             LEFT JOIN {$wpdb->posts} p ON s.post_id = p.ID 
             {$where_sql} 
             ORDER BY {$order_col} {$order} 
             LIMIT %d OFFSET %d",
            ...$query_params
        );
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Explicitly set column headers for WP_List_Table rendering
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            $this->get_primary_column_name(),
        ];

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $raw_items = $wpdb->get_results($sql, ARRAY_A);
        $this->items = is_array($raw_items)
            ? array_map(function ($row) { return (array) $row; }, $raw_items)
            : [];

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }
}
