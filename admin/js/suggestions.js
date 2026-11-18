/**
 * JavaScript handler for AI Category Suggestions Admin Table.
 * Provides AJAX Approve, Reject, and Merge actions with immediate UI feedback.
 */
(function ($) {
    'use strict';

    if (typeof zeriseTaxoSuggestionsSettings === 'undefined') {
        return;
    }

    const { restUrl, nonce, i18n } = zeriseTaxoSuggestionsSettings;

    function showNotice(message, isError) {
        const $notice = $('#zerisetaxo-suggestions-notice');
        const noticeClass = isError ? 'notice notice-error' : 'notice notice-success is-dismissible';

        $notice
            .attr('class', noticeClass)
            .html('<p>' + message + '</p>')
            .fadeIn(200);

        if (!isError) {
            setTimeout(function () {
                $notice.fadeOut(400);
            }, 5000);
        }
    }

    function decrementMenuCount() {
        const $badges = $('a[href*="zerisetaxo"] .pending-count, a[href*="zerisetaxo-category-suggestions"] .pending-count, .nav-tab .pending-count');
        $badges.each(function () {
            const $badge = $(this);
            let count = parseInt($badge.text().replace(/,/g, ''), 10);
            if (!isNaN(count) && count > 0) {
                count -= 1;
                if (count === 0) {
                    $badge.closest('.awaiting-mod').remove();
                } else {
                    $badge.text(count.toLocaleString());
                }
            }
        });
    }

    // Handle "Approve & Create" button click
    $(document).on('click', '.zerisetaxo-btn-approve', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const id = $btn.data('id');
        const categoryName = $btn.data('category');
        const $row = $btn.closest('tr');

        if (!id) return;

        $btn.prop('disabled', true).text(i18n.approving);

        fetch(restUrl + id + '/approve', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            }
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (res) {
                if (res.ok) {
                    showNotice(res.data.message || 'Category approved and created successfully.', false);
                    decrementMenuCount();

                    // Update row UI to show approved badge and disable actions
                    $row.find('.row-actions').remove();
                    $row.find('.column-status').html(
                        '<span class="badge" style="background:#dafbe1; color:#1a7f37; border:1px solid #aceebb; padding:3px 8px; border-radius:10px; font-weight:600; font-size:11px;">✓ Approved</span>'
                    );
                    $row.find('.column-suggested_category').append(
                        '<div style="font-size:11px; color:#1a7f37; margin-top:4px;">Approved just now</div>'
                    );
                    $row.css('background-color', '#f6fbf7');
                } else {
                    $btn.prop('disabled', false).text('Approve & Create');
                    showNotice(res.data.message || i18n.actionError, true);
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false).text('Approve & Create');
                showNotice(i18n.actionError, true);
            });
    });

    // Handle "Reject" button click
    $(document).on('click', '.zerisetaxo-btn-reject', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const id = $btn.data('id');
        const $row = $btn.closest('tr');

        if (!id) return;

        if (!confirm(i18n.confirmReject)) {
            return;
        }

        $btn.prop('disabled', true).text(i18n.rejecting);

        fetch(restUrl + id + '/reject', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            }
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (res) {
                if (res.ok) {
                    showNotice(res.data.message || 'Category suggestion rejected.', false);
                    decrementMenuCount();

                    $row.find('.row-actions').remove();
                    $row.find('.column-status').html(
                        '<span class="badge" style="background:#ffebe9; color:#cf222e; border:1px solid #ffcecb; padding:3px 8px; border-radius:10px; font-weight:600; font-size:11px;">✕ Rejected</span>'
                    );
                    $row.find('.column-suggested_category').append(
                        '<div style="font-size:11px; color:#cf222e; margin-top:4px;">Rejected just now</div>'
                    );
                    $row.css('background-color', '#fff8f7');
                } else {
                    $btn.prop('disabled', false).text('Reject');
                    showNotice(res.data.message || i18n.actionError, true);
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false).text('Reject');
                showNotice(i18n.actionError, true);
            });
    });

    // Handle "Merge" button click
    $(document).on('click', '.zerisetaxo-btn-merge', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const id = $btn.data('id');
        const $row = $btn.closest('tr');
        const $select = $row.find('.zerisetaxo-merge-select');
        const targetTermId = $select.val();

        if (!id) return;

        if (!targetTermId) {
            alert(i18n.selectTarget);
            $select.focus();
            return;
        }

        $btn.prop('disabled', true).text(i18n.merging);
        $select.prop('disabled', true);

        fetch(restUrl + id + '/merge', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({
                existing_term_id: parseInt(targetTermId, 10)
            })
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (res) {
                if (res.ok) {
                    showNotice(res.data.message || 'Post merged into existing category.', false);
                    decrementMenuCount();

                    $row.find('.row-actions').remove();
                    $row.find('.column-status').html(
                        '<span class="badge" style="background:#ddf4ff; color:#0969da; border:1px solid #b6e3ff; padding:3px 8px; border-radius:10px; font-weight:600; font-size:11px;">⇶ Merged</span>'
                    );
                    $row.find('.column-suggested_category').append(
                        '<div style="font-size:11px; color:#0969da; margin-top:4px;">Merged into <strong>' + (res.data.category_name || '') + '</strong></div>'
                    );
                    $row.css('background-color', '#f0f8ff');
                } else {
                    $btn.prop('disabled', false).text('Merge');
                    $select.prop('disabled', false);
                    showNotice(res.data.message || i18n.actionError, true);
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false).text('Merge');
                $select.prop('disabled', false);
                showNotice(i18n.actionError, true);
            });
    });

})(jQuery);
