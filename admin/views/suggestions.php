<?php
/**
 * Admin view for AI Category Suggestions list table.
 *
 * @var \ZeriseTaxo\Admin\SuggestionsListTable $list_table
 */
defined('ABSPATH') || exit;
?>
<div class="zerisetaxo-suggestions-content">
    <p class="description" style="margin-top: 0; margin-bottom: 14px; color: #50575e; font-size: 13px;">
        <?php esc_html_e('When the AI classification engine determines that none of your site\'s existing categories adequately fit a post, it suggests a new candidate category. Suggestions remain pending until you explicitly Approve, Reject, or Merge them.', 'zerisetaxo'); ?>
    </p>

    <!-- Dynamic AJAX feedback notice container -->
    <div id="zerisetaxo-suggestions-notice" style="display: none; margin: 12px 0;"></div>

    <form id="zerisetaxo-suggestions-form" method="get">
        <input type="hidden" name="page" value="zerisetaxo" />
        <input type="hidden" name="tab" value="suggestions" />
        <?php
        $list_table->views();
        $list_table->display();
        ?>
    </form>
</div>
