/**
 * ML Category Predictor - Admin Settings Interactions
 * Handles connection testing, real-time feedback, and dynamic status updates.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // ── Site Model Elements (100% PHP-Native) ──────────────────────────
        var $rebuildBtn = $('.zerisetaxo-rebuild-model-btn, #zerisetaxo-btn-rebuild-model, #zerisetaxo-dashboard-rebuild-btn');
        var $rebuildProgressBox = $('#zerisetaxo-rebuild-progress-box');
        var $errorBox = $('#zerisetaxo-sync-error-box');
        var $statusDisplay = $('#zerisetaxo-sync-status-display');
        var isRebuilding = false;
        var isSyncing = false;
        var $syncBtn = $();

        // ── Site Model Rebuild Handler ───────────────────────────────────────
        $(document).on('click', '.zerisetaxo-rebuild-model-btn, #zerisetaxo-btn-rebuild-model, #zerisetaxo-dashboard-rebuild-btn', function (e) {
            e.preventDefault();
            if (isRebuilding || isSyncing) return;
            isRebuilding = true;

            $rebuildBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin" style="margin-right: 4px;"></i> ' + (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.i18n && zeriseTaxoSettingsData.i18n.rebuilding ? zeriseTaxoSettingsData.i18n.rebuilding : 'Rebuilding...'));
            $syncBtn.prop('disabled', true);
            $errorBox.hide();
            $rebuildProgressBox.show();

            var ajaxUrl = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.ajaxUrl) ? zeriseTaxoSettingsData.ajaxUrl : ajaxurl;
            var nonce = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.rebuildNonce) ? zeriseTaxoSettingsData.rebuildNonce : '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'zerisetaxo_rebuild_site_model',
                    nonce: nonce
                },
                timeout: 70000
            })
            .done(function (response) {
                $rebuildProgressBox.hide();
                $rebuildBtn.prop('disabled', false).html('<i class="fa-solid fa-brain" style="margin-right: 4px;"></i> ' + (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.i18n && zeriseTaxoSettingsData.i18n.rebuildButton ? zeriseTaxoSettingsData.i18n.rebuildButton : 'Rebuild Model'));
                $syncBtn.prop('disabled', false);
                isRebuilding = false;

                if (response && response.success && response.data) {
                    var data = response.data;
                    var successTitle = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.i18n && zeriseTaxoSettingsData.i18n.lastSyncDone)
                        ? zeriseTaxoSettingsData.i18n.lastSyncDone
                        : 'Last site sync completed: Just now';

                    if (data.status === 'ready') {
                        var ver = data.version ? ' (' + data.version + ')' : '';
                        $statusDisplay.html(
                            '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">' +
                                '<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #3B82F6;"></span>' +
                                '<span style="font-size: 13.5px; font-weight: 600; color: #1E40AF;">' + successTitle + '</span>' +
                            '</div>' +
                            '<p style="margin: 0; font-size: 13px; color: #059669;">' +
                                '<i class="fa-solid fa-circle-check" style="margin-right: 4px;"></i>' +
                                'Personalized model active' + ver +
                            '</p>'
                        );
                    } else if (data.status === 'needs_more_data') {
                        var needsMsg = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.i18n && zeriseTaxoSettingsData.i18n.needsMoreData)
                            ? zeriseTaxoSettingsData.i18n.needsMoreData
                            : 'Site needs more category training data';
                        $statusDisplay.html(
                            '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">' +
                                '<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #3B82F6;"></span>' +
                                '<span style="font-size: 13.5px; font-weight: 600; color: #1E40AF;">' + successTitle + '</span>' +
                            '</div>' +
                            '<p style="margin: 0; font-size: 13px; color: #D97706;">' +
                                '<i class="fa-solid fa-circle-info" style="margin-right: 4px;"></i>' +
                                needsMsg +
                            '</p>'
                        );
                    }
                } else {
                    var msg = (response && response.data && response.data.message)
                        ? response.data.message
                        : 'Model rebuild failed.';
                    $errorBox.html('<i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i><strong>' + msg + '</strong>').show();
                }
            })
            .fail(function (xhr, textStatus) {
                $rebuildProgressBox.hide();
                $rebuildBtn.prop('disabled', false).html('<i class="fa-solid fa-brain" style="margin-right: 4px;"></i> ' + (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.i18n && zeriseTaxoSettingsData.i18n.rebuildButton ? zeriseTaxoSettingsData.i18n.rebuildButton : 'Rebuild Model'));
                $syncBtn.prop('disabled', false);
                isRebuilding = false;

                var msg = 'Model rebuild failed (' + textStatus + ').';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                $errorBox.html('<i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i><strong>' + msg + '</strong>').show();
            });
        });

        // LLM Test connection button handler (Google Gemini)
        $(document).on('click', '#zerisetaxo-btn-test-llm-connection, #zerisetaxo-test-llm-btn', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var $resultBox = $('.zerisetaxo-test-llm-result, #zerisetaxo-test-llm-result');
            var originalHtml = $btn.html();

            $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin" style="margin-right: 4px;"></i> ' + (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.i18n ? zeriseTaxoSettingsData.i18n.testing : 'Testing...'));
            $resultBox.hide().empty();

            $.ajax({
                url: (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.ajaxUrl) ? zeriseTaxoSettingsData.ajaxUrl : ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'zerisetaxo_test_llm_connection',
                    nonce: (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.testLlmNonce) ? zeriseTaxoSettingsData.testLlmNonce : ''
                },
                timeout: 15000
            })
            .done(function (response) {
                var isSuccess = response && response.success;
                var data = response && response.data ? response.data : {};
                var status = data.status || (isSuccess ? 'Connected' : 'Provider unavailable');
                var message = data.message || status;

                if (isSuccess || status === 'Connected') {
                    $resultBox.css({
                        'display': 'block',
                        'background': '#ECFDF5',
                        'border': '1px solid #A7F3D0',
                        'color': '#065F46'
                    }).html('<i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i><strong>' + message + '</strong>');
                } else if (status === 'Invalid API key') {
                    $resultBox.css({
                        'display': 'block',
                        'background': '#FEF2F2',
                        'border': '1px solid #FECACA',
                        'color': '#991B1B'
                    }).html('<i class="fa-solid fa-circle-xmark" style="margin-right: 6px;"></i><strong>' + message + '</strong>');
                } else if (status === 'Rate limited') {
                    $resultBox.css({
                        'display': 'block',
                        'background': '#FFFBEB',
                        'border': '1px solid #FDE68A',
                        'color': '#92400E'
                    }).html('<i class="fa-solid fa-hourglass-half" style="margin-right: 6px;"></i><strong>' + message + '</strong>');
                } else {
                    $resultBox.css({
                        'display': 'block',
                        'background': '#FEF2F2',
                        'border': '1px solid #FECACA',
                        'color': '#991B1B'
                    }).html('<i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i><strong>' + message + '</strong>');
                }
            })
            .fail(function (xhr, textStatus) {
                var msg = textStatus === 'timeout' ? 'Connection timed out.' : 'Failed to reach local server.';
                $resultBox.css({
                    'display': 'block',
                    'background': '#FEF2F2',
                    'border': '1px solid #FECACA',
                    'color': '#991B1B'
                }).html('<i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i><strong>' + msg + '</strong>');
            })
            .always(function () {
                $btn.prop('disabled', false).html(originalHtml);
            });
        });

        // -------------------------------------------------------------
        // COLD-START TAXONOMY BOOTSTRAP INTERACTIONS
        // -------------------------------------------------------------
        $(document).on('submit', '#zerisetaxo-bootstrap-form', function (e) {
            e.preventDefault();

            var $btn     = $('#zerisetaxo-generate-bootstrap-btn');
            var $notice  = $('#zerisetaxo-bootstrap-status-notice');
            var $panel   = $('#zerisetaxo-bootstrap-results-panel');
            var $list    = $('#zerisetaxo-bootstrap-suggestions-list');

            var desc     = $.trim($('#zerisetaxo_bootstrap_description').val());
            var topics   = $.trim($('#zerisetaxo_bootstrap_topics').val());
            var niche    = $.trim($('#zerisetaxo_bootstrap_niche').val());

            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin" style="margin-right: 6px;"></i> Generating Suggestions...');
            $notice.addClass('hidden').empty();

            var restUrl = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.bootstrapRestUrl)
                ? zeriseTaxoSettingsData.bootstrapRestUrl
                : '/wp-json/zerisetaxo/v1/bootstrap/suggest';

            var restNonce = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.restNonce) ? zeriseTaxoSettingsData.restNonce : '';

            $.ajax({
                url: restUrl,
                type: 'POST',
                beforeSend: function (xhr) {
                    if (restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', restNonce);
                    }
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    site_description: desc,
                    topics: topics,
                    niche: niche
                }),
                timeout: 25000
            })
            .done(function (resp) {
                if (resp && resp.suggestions && resp.suggestions.length > 0) {
                    renderBootstrapSuggestions(resp.suggestions);
                    $panel.removeClass('hidden').show();
                    $notice.css({ 'background': '#F0FDF4', 'color': '#166534', 'border': '1px solid #BBF7D0' })
                        .html('<i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> ' + resp.suggestions.length + ' category suggestions generated. Review and approve below.')
                        .removeClass('hidden').show();
                } else {
                    $notice.css({ 'background': '#FFFBEB', 'color': '#92400E', 'border': '1px solid #FDE68A' })
                        .html('<i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i> No taxonomy suggestions generated. Please add more topic keywords.')
                        .removeClass('hidden').show();
                }
            })
            .fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to generate taxonomy suggestions.';
                $notice.css({ 'background': '#FEF2F2', 'color': '#991B1B', 'border': '1px solid #FCA5A5' })
                    .html('<i class="fa-solid fa-circle-xmark" style="margin-right: 6px;"></i> ' + err)
                    .removeClass('hidden').show();
            })
            .always(function () {
                $btn.prop('disabled', false).html(origHtml);
            });
        });

        function renderBootstrapSuggestions(suggestions) {
            var $list = $('#zerisetaxo-bootstrap-suggestions-list');
            $list.empty();

            $.each(suggestions, function (idx, item) {
                var name        = item.name || '';
                var parent      = item.parent || '';
                var type        = item.type || 'parent';
                var conf        = item.confidence ? Math.round(item.confidence * 100) + '%' : '85%';
                var reason      = item.reason || '';
                var matchType   = item.match_type || 'taxonomy_expansion';
                var existingId  = item.existing_category_id || null;

                var typeBadge   = type === 'parent' ? 'Parent Category' : ('Child of ' + parent);
                var matchBadge  = existingId ? 'Existing Match' : 'Taxonomy Expansion';

                var $card = $('<div class="zerisetaxo-bootstrap-card" style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;"></div>')
                    .data('catName', name)
                    .data('catParent', parent);

                var html = '';
                html += '<div>';
                html += '  <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">';
                html += '    <strong class="zerisetaxo-bootstrap-name" style="font-size: 13.5px; color: #0F172A;">' + escapeHtml(name) + '</strong>';
                html += '    <span style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 10px;">' + escapeHtml(typeBadge) + '</span>';
                html += '    <span style="background: ' + (existingId ? '#F0FDF4' : '#FFFBEB') + '; color: ' + (existingId ? '#166534' : '#92400E') + '; border: 1px solid ' + (existingId ? '#BBF7D0' : '#FDE68A') + '; font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 10px;">' + escapeHtml(matchBadge) + '</span>';
                html += '    <span style="font-size: 11px; color: #64748B; font-weight: 600;">Confidence: ' + conf + '</span>';
                html += '  </div>';
                if (reason) {
                    html += '  <div style="font-size: 11.5px; color: #64748B; margin-top: 3px;">' + escapeHtml(reason) + '</div>';
                }
                html += '</div>';

                html += '<div class="zerisetaxo-bootstrap-actions" style="display: flex; align-items: center; gap: 6px;">';
                if (existingId) {
                    html += '  <span style="font-size: 12px; color: #166534; font-weight: 600;"><i class="fa-solid fa-check"></i> Already Active</span>';
                } else {
                    html += '  <button type="button" class="button button-small button-primary zerisetaxo-bootstrap-approve-btn" style="font-weight: 600;"><i class="fa-solid fa-check"></i> Approve</button>';
                    html += '  <button type="button" class="button button-small button-secondary zerisetaxo-bootstrap-edit-btn"><i class="fa-solid fa-pen"></i> Edit</button>';
                    html += '  <button type="button" class="button button-small button-secondary zerisetaxo-bootstrap-reject-btn" style="color: #991B1B;"><i class="fa-solid fa-xmark"></i> Reject</button>';
                }
                html += '</div>';

                $card.html(html);
                $list.append($card);
            });
        }

        function escapeHtml(str) {
            return $('<div>').text(str || '').html();
        }

        // Action: Approve Category Suggestion
        $(document).on('click', '.zerisetaxo-bootstrap-approve-btn', function (e) {
            e.preventDefault();
            var $btn  = $(this);
            var $card = $btn.closest('.zerisetaxo-bootstrap-card');
            var name  = $card.data('catName');
            var parent = $card.data('catParent');

            $btn.prop('disabled', true).text('Approving...');

            var approveUrl = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.bootstrapApproveUrl)
                ? zeriseTaxoSettingsData.bootstrapApproveUrl
                : '/wp-json/zerisetaxo/v1/bootstrap/approve-suggestion';

            var restNonce = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.restNonce) ? zeriseTaxoSettingsData.restNonce : '';

            $.ajax({
                url: approveUrl,
                type: 'POST',
                beforeSend: function (xhr) {
                    if (restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', restNonce);
                    }
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    name: name,
                    action: 'approve',
                    parent: parent
                }),
                timeout: 15000
            })
            .done(function (resp) {
                $card.find('.zerisetaxo-bootstrap-actions').html('<span style="font-size: 12px; color: #166534; font-weight: 700;"><i class="fa-solid fa-circle-check"></i> Approved & Active</span>');
                $card.css({ 'background': '#F0FDF4', 'border-color': '#BBF7D0' });
            })
            .fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to approve category.';
                alert(err);
                $btn.prop('disabled', false).html('<i class="fa-solid fa-check"></i> Approve');
            });
        });

        // Action: Reject Category Suggestion
        $(document).on('click', '.zerisetaxo-bootstrap-reject-btn', function (e) {
            e.preventDefault();
            var $btn  = $(this);
            var $card = $btn.closest('.zerisetaxo-bootstrap-card');
            var name  = $card.data('catName');

            var approveUrl = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.bootstrapApproveUrl)
                ? zeriseTaxoSettingsData.bootstrapApproveUrl
                : '/wp-json/zerisetaxo/v1/bootstrap/approve-suggestion';

            var restNonce = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.restNonce) ? zeriseTaxoSettingsData.restNonce : '';

            $.ajax({
                url: approveUrl,
                type: 'POST',
                beforeSend: function (xhr) {
                    if (restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', restNonce);
                    }
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    name: name,
                    action: 'reject'
                }),
                timeout: 10000
            })
            .always(function () {
                $card.fadeOut(200, function () {
                    $(this).remove();
                });
            });
        });

        // Action: Edit Category Suggestion Name
        $(document).on('click', '.zerisetaxo-bootstrap-edit-btn', function (e) {
            e.preventDefault();
            var $btn  = $(this);
            var $card = $btn.closest('.zerisetaxo-bootstrap-card');
            var name  = $card.data('catName');

            var $nameEl = $card.find('.zerisetaxo-bootstrap-name');
            if ($card.find('.zerisetaxo-bootstrap-edit-input').length) return;

            var $input = $('<input type="text" class="zerisetaxo-bootstrap-edit-input" style="font-size: 13px; padding: 2px 6px; border: 1px solid #CBD5E1; border-radius: 4px;" />').val(name);
            var $saveBtn = $('<button type="button" class="button button-small button-primary zerisetaxo-bootstrap-save-edit-btn" style="margin-left: 4px;"><i class="fa-solid fa-floppy-disk"></i> Save</button>');

            $nameEl.hide().after($input).after($saveBtn);
            $input.focus();
        });

        $(document).on('click', '.zerisetaxo-bootstrap-save-edit-btn', function (e) {
            e.preventDefault();
            var $saveBtn = $(this);
            var $card    = $saveBtn.closest('.zerisetaxo-bootstrap-card');
            var origName = $card.data('catName');
            var parent   = $card.data('catParent');
            var newName  = $.trim($card.find('.zerisetaxo-bootstrap-edit-input').val());

            if (!newName) return;

            $saveBtn.prop('disabled', true).text('Saving...');

            var approveUrl = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.bootstrapApproveUrl)
                ? zeriseTaxoSettingsData.bootstrapApproveUrl
                : '/wp-json/zerisetaxo/v1/bootstrap/approve-suggestion';

            var restNonce = (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.restNonce) ? zeriseTaxoSettingsData.restNonce : '';

            $.ajax({
                url: approveUrl,
                type: 'POST',
                beforeSend: function (xhr) {
                    if (restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', restNonce);
                    }
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    name: origName,
                    new_name: newName,
                    action: 'edit',
                    parent: parent
                }),
                timeout: 15000
            })
            .done(function (resp) {
                $card.data('catName', newName);
                $card.find('.zerisetaxo-bootstrap-name').text(newName).show();
                $card.find('.zerisetaxo-bootstrap-edit-input, .zerisetaxo-bootstrap-save-edit-btn').remove();
                $card.find('.zerisetaxo-bootstrap-actions').html('<span style="font-size: 12px; color: #166534; font-weight: 700;"><i class="fa-solid fa-circle-check"></i> Approved & Active ("' + escapeHtml(newName) + '")</span>');
                $card.css({ 'background': '#F0FDF4', 'border-color': '#BBF7D0' });
            })
            .fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to save edited category.';
                alert(err);
                $saveBtn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk"></i> Save');
            });
        });


        // --- INSTANT TEST PLAYGROUND HANDLERS ---
        $(document).on('click', '.zerisetaxo-pg-sample-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            $('#zerisetaxo-pg-title').val($btn.data('title'));
            $('#zerisetaxo-pg-content').val($btn.data('content'));
            $('#zerisetaxo-pg-run-btn').trigger('click');
        });

        $(document).on('click', '#zerisetaxo-pg-run-btn', function (e) {
            e.preventDefault();
            var title = $.trim($('#zerisetaxo-pg-title').val());
            var content = $.trim($('#zerisetaxo-pg-content').val());

            if (!title && !content) {
                alert('Please enter a post title or content excerpt to test suggestions.');
                $('#zerisetaxo-pg-title').focus();
                return;
            }

            var $btn = $(this);
            var $loading = $('#zerisetaxo-pg-loading');
            var $results = $('#zerisetaxo-pg-results');

            $btn.prop('disabled', true);
            $loading.show();
            $results.slideUp(150);

            $.ajax({
                url: (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.ajaxUrl) ? zeriseTaxoSettingsData.ajaxUrl : ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'zerisetaxo_playground_predict',
                    nonce: (window.zeriseTaxoSettingsData && zeriseTaxoSettingsData.playgroundNonce) ? zeriseTaxoSettingsData.playgroundNonce : '',
                    title: title,
                    content: content
                },
                timeout: 15000
            })
            .done(function (resp) {
                $btn.prop('disabled', false);
                $loading.hide();

                if (resp && resp.success && resp.data) {
                    var d = resp.data;
                    $('#zerisetaxo-pg-cat-name').text(d.category || 'Uncategorized');
                    $('#zerisetaxo-pg-conf-bar').css('width', Math.min(100, Math.max(0, d.confidence)) + '%');
                    $('#zerisetaxo-pg-cat-conf').text(d.confidence + '% Match • ' + (d.state ? d.state.replace('_', ' ') : 'Strong'));

                    var pillHtml = d.is_ai
                        ? '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 12px; color: #166534;"><i class="fa-solid fa-sparkles"></i> ' + escapeHtml(d.tier_name) + '</span>'
                        : '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 12px; color: #1E40AF;"><i class="fa-solid fa-bolt"></i> ' + escapeHtml(d.tier_name) + '</span>';
                    $('#zerisetaxo-pg-engine-pill').html(pillHtml);

                    var tagsHtml = '';
                    if (d.tags && d.tags.length > 0) {
                        for (var i = 0; i < d.tags.length; i++) {
                            tagsHtml += '<span style="display: inline-flex; align-items: center; gap: 3px; padding: 3px 10px; background: #EEF2FF; border: 1px solid #C7D2FE; border-radius: 14px; font-size: 12px; font-weight: 600; color: #4338CA;">#' + escapeHtml(d.tags[i]) + '</span>';
                        }
                    } else {
                        tagsHtml = '<span style="font-size: 12px; color: #64748B; font-style: italic;">No specific tags found.</span>';
                    }
                    $('#zerisetaxo-pg-tags-container').html(tagsHtml);

                    if (d.reasoning) {
                        $('#zerisetaxo-pg-reasoning').html('<i class="fa-solid fa-circle-info" style="margin-right: 4px; color: #6366F1;"></i> ' + escapeHtml(d.reasoning)).show();
                    } else {
                        $('#zerisetaxo-pg-reasoning').hide();
                    }

                    $results.slideDown(200);
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error analyzing content.';
                    alert(msg);
                }
            })
            .fail(function (xhr, status, error) {
                $btn.prop('disabled', false);
                $loading.hide();
                alert('Analysis request failed: ' + error);
            });
        });

        // -------------------------------------------------------------
                // -------------------------------------------------------------
        // Category Ranking & Ordering Strategy Interactions
        // -------------------------------------------------------------
        var $sortableList = $('#zerisetaxo-sortable-categories');
        var $hintText     = $('#zerisetaxo-strategy-hint');

        function updateOrderBadgesAndInput() {
            var order = [];
            $sortableList.find('li').each(function (idx) {
                var id = $(this).data('termId');
                if (id) {
                    order.push(id);
                }
                $(this).find('.zerisetaxo-order-badge').text('#' + (idx + 1));
            });
            $('#zerisetaxo_custom_category_order').val(order.join(','));
        }

        function syncSortableStateToMode(mode) {
            var isCustom    = (mode === 'custom_order');
            var $bannerIcon = $('#zerisetaxo-drag-hint-icon');
            var $bannerText = $('#zerisetaxo-drag-hint-text');

            if ($sortableList.length && typeof $.fn.sortable === 'function') {
                if (isCustom) {
                    $sortableList.sortable('enable');
                    $sortableList.addClass('zerisetaxo-sortable-active');
                    $sortableList.find('li').css({ 'cursor': 'grab' });
                    $sortableList.find('.zerisetaxo-drag-handle').css({ 'opacity': '1', 'cursor': 'grab' });
                    if ($bannerIcon.length) {
                        $bannerIcon.attr('class', 'fa-solid fa-hand-pointer').css('color', '#4F46E5');
                    }
                    if ($bannerText.length) {
                        $bannerText.html('<strong>Custom Drag & Drop active:</strong> Click and drag any category card below to customize your priority order.');
                    }
                } else {
                    $sortableList.sortable('disable');
                    $sortableList.removeClass('zerisetaxo-sortable-active');
                    $sortableList.find('li').css({ 'cursor': 'default' });
                    $sortableList.find('.zerisetaxo-drag-handle').css({ 'opacity': '0.4', 'cursor': 'not-allowed' });
                    if ($bannerIcon.length) {
                        $bannerIcon.attr('class', 'fa-solid fa-lock').css('color', '#64748B');
                    }
                    if ($bannerText.length) {
                        if (mode === 'post_count') {
                            $bannerText.html('<strong>Locked (Post Count):</strong> Categories are automatically sorted by post volume. <a href="#" class="zerisetaxo-switch-to-custom-link" style="color: #4F46E5; font-weight: 600; text-decoration: underline; margin-left: 4px;">Switch to Custom Drag & Drop</a> to reorder.');
                        } else {
                            $bannerText.html('<strong>Locked (Standard Order):</strong> Categories use default WordPress ordering. <a href="#" class="zerisetaxo-switch-to-custom-link" style="color: #4F46E5; font-weight: 600; text-decoration: underline; margin-left: 4px;">Switch to Custom Drag & Drop</a> to reorder.');
                        }
                    }
                }
            }
        }

        // Initialize sortable on the category list
        if ($sortableList.length && typeof $.fn.sortable === 'function') {
            $sortableList.sortable({
                items: '> li',
                cancel: 'a, button, input, select, textarea',
                axis: 'y',
                cursor: 'grabbing',
                placeholder: 'zerisetaxo-sortable-placeholder',
                forcePlaceholderSize: true,
                opacity: 0.85,
                tolerance: 'pointer',
                distance: 1,
                start: function (e, ui) {
                    ui.placeholder.height(ui.item.outerHeight());
                },
                update: function () {
                    updateOrderBadgesAndInput();
                }
            });

            // Set initial enabled/disabled state based on current selected radio
            var currentMode = $('input[name="zerisetaxo_category_order_mode"]:checked').val() || 'custom_order';
            syncSortableStateToMode(currentMode);
        }

        // Quick-switch link from banner
        $(document).on('click', '.zerisetaxo-switch-to-custom-link', function (e) {
            e.preventDefault();
            var $customRadio = $('input[name="zerisetaxo_category_order_mode"][value="custom_order"]');
            if ($customRadio.length) {
                $customRadio.prop('checked', true).trigger('change');
            }
        });

        // Live dynamic sorting when switching strategy pills
        $('input[name="zerisetaxo_category_order_mode"]').on('change', function () {
            var mode = $(this).val();
            syncSortableStateToMode(mode);

            if (mode === 'post_count') {
                if ($hintText.length) {
                    $hintText.text('Categories are sorted by post volume (most popular first).');
                }
                var items = $sortableList.children('li').get();
                items.sort(function (a, b) {
                    var countA = parseInt($(a).data('postCount'), 10) || 0;
                    var countB = parseInt($(b).data('postCount'), 10) || 0;
                    if (countA === countB) {
                        return ($(a).data('name') || '').localeCompare($(b).data('name') || '');
                    }
                    return countB - countA;
                });
                $.each(items, function (idx, itm) {
                    $sortableList.append(itm);
                });
                updateOrderBadgesAndInput();
            } else if (mode === 'confidence') {
                if ($hintText.length) {
                    $hintText.text('Categories use standard WordPress ordering, with AI confidence matching for post suggestions.');
                }
                var items = $sortableList.children('li').get();
                items.sort(function (a, b) {
                    return ($(a).data('name') || '').localeCompare($(b).data('name') || '');
                });
                $.each(items, function (idx, itm) {
                    $sortableList.append(itm);
                });
                updateOrderBadgesAndInput();
            } else if (mode === 'custom_order') {
                if ($hintText.length) {
                    $hintText.text('Drag and drop any category below to customize order priority.');
                }
            }
        });

    }); // End of $(document).ready()

})(jQuery);