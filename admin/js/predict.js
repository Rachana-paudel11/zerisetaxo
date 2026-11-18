/**
 * ML Category Predictor - Post Editor Interface
 * Clean, distraction-free workflow for WordPress post editors:
 * Suggest -> Review -> Apply -> Ignore.
 * Internal model complexity is decoupled and placed in Global Settings.
 */
(function ($) {
    'use strict';

    // -------------------------------------------------------------
    // GUTENBERG NATIVE SIDEBAR PANEL REGISTRATION
    // -------------------------------------------------------------
    if (window.wp && wp.plugins && (wp.editor || wp.editPost) && wp.element) {
        var registerPlugin = wp.plugins.registerPlugin;
        var PluginDocumentSettingPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || wp.editPost.PluginDocumentSettingPanel;
        var el = wp.element.createElement;

        var $metaboxContent = null;

        try {
            registerPlugin('zerisetaxo-gutenberg-panel', {
                render: function () {
                    return el(
                        PluginDocumentSettingPanel,
                        {
                            name: 'zerisetaxo-sidebar-panel',
                            title: 'ZeriseTaxo Suggestions',
                            icon: 'wand',
                            className: 'zerisetaxo-gutenberg-panel'
                        },
                        el('div', {
                            id: 'zerisetaxo-gutenberg-mount-point',
                            ref: function (node) {
                                if (!node) return;
                                if (!$metaboxContent || !$metaboxContent.length) {
                                    var $legacy = $('#zerisetaxo_metabox .inside');
                                    if ($legacy.length) {
                                        $metaboxContent = $legacy.children();
                                    }
                                }
                                if ($metaboxContent && $metaboxContent.length && !$(node).children().length) {
                                    $(node).append($metaboxContent);
                                    $('#zerisetaxo_metabox').remove(); // Remove duplicate bottom metabox wrapper completely
                                }
                            }
                        })
                    );
                }
            });
        } catch (err) {}
    }

    $(document).ready(function () {
        if (!window.zeriseTaxoData || !zeriseTaxoData.enableSuggestions) {
            return;
        }

        var currentRecTermId     = zeriseTaxoData.savedCategoryId || 0;
        var currentRecCatName    = zeriseTaxoData.savedCategory || '';
        var currentRecParentId   = parseInt(zeriseTaxoData.savedCategoryParentId, 10) || 0;
        var currentRecParentName = zeriseTaxoData.savedCategoryParentName || '';
        var currentAltTermId     = 0;
        var currentAltCatName    = '';
        var currentAltParentId   = parseInt(zeriseTaxoData.savedRunnerUpParentId, 10) || 0;
        var currentAltParentName = zeriseTaxoData.savedRunnerUpParentName || '';
        var currentSuggestedName = zeriseTaxoData.savedSuggestedCategory || '';
        var isRequestInProgress  = false;

        // Initialize ambiguous state on load if post was already saved in ambiguous state
        if (zeriseTaxoData.savedState === 'ambiguous') {
            if (zeriseTaxoData.isValidAmbiguous) {
                var initOpt1Name = zeriseTaxoData.savedCategory || '';
                var initOpt1Id   = parseInt(zeriseTaxoData.savedCategoryId, 10) || 0;
                var initOpt2Name = zeriseTaxoData.savedRunnerUpCategory || '';
                var initOpt2Id   = parseInt(zeriseTaxoData.savedRunnerUpCategoryId, 10) || 0;

                $('#zerisetaxo-ambig-opt-1').val(initOpt1Id).attr('data-cat-name', initOpt1Name).data('catName', initOpt1Name);
                $('#zerisetaxo-ambig-label-1').text(initOpt1Name);

                $('#zerisetaxo-ambig-opt-2').val(initOpt2Id).attr('data-cat-name', initOpt2Name).data('catName', initOpt2Name);
                $('#zerisetaxo-ambig-label-2').text(initOpt2Name);

                $('#zerisetaxo-ambig-opt-1').prop('checked', true);
            } else {
                hideNotice();
                $('#zerisetaxo-error-title').text(zeriseTaxoData.i18n.ambiguousInvalid || 'Multiple categories were suggested, but one or more could not be verified. Please check again.');
                $('#zerisetaxo-error-btn-text').text(zeriseTaxoData.i18n.checkAgain || 'Check again');
                showState('error');
            }
        }

        // Render saved category chips on initial load if present
        if (zeriseTaxoData.savedCategory && zeriseTaxoData.savedCategoryId > 0) {
            var initCatList = [{
                name: zeriseTaxoData.savedCategory,
                term_id: zeriseTaxoData.savedCategoryId,
                parent_id: currentRecParentId,
                parent_name: currentRecParentName,
                is_new: false
            }];
            if (zeriseTaxoData.savedRunnerUpCategory && zeriseTaxoData.savedRunnerUpCategoryId > 0) {
                initCatList.push({
                    name: zeriseTaxoData.savedRunnerUpCategory,
                    term_id: zeriseTaxoData.savedRunnerUpCategoryId,
                    parent_id: currentAltParentId,
                    parent_name: currentAltParentName,
                    is_new: false
                });
            }
            renderCategoryChips(initCatList);
            updateRecommendedButtonState(currentRecTermId, currentRecCatName);
        } else if (zeriseTaxoData.savedSuggestedCategory) {
            renderCategoryChips([{ name: zeriseTaxoData.savedSuggestedCategory, term_id: 0, is_new: true }]);
        }

        // Automatic Suggestion Behavior: Suggest on editor load / save
        var autoTagDone = false;
        var triggerAutoSuggestIfEmpty = function () {
            if (window.zeriseTaxoData && zeriseTaxoData.suggestionBehavior === 'manual') {
                return;
            }
            var postData = getPostContent();
            if (postData.title || postData.content) {
                if (!autoTagDone) {
                    autoTagDone = true;
                    runTagPrediction();
                }
                if (!$('#zerisetaxo-state-initial').hasClass('hidden') || zeriseTaxoData.savedState === 'out_of_distribution') {
                    runPrediction();
                }
            }
        };

        if (window.wp && wp.domReady) {
            wp.domReady(function () {
                setTimeout(triggerAutoSuggestIfEmpty, 800);
                setTimeout(triggerAutoSuggestIfEmpty, 1800);
                setTimeout(triggerAutoSuggestIfEmpty, 3000);
            });
        } else {
            setTimeout(triggerAutoSuggestIfEmpty, 800);
            setTimeout(triggerAutoSuggestIfEmpty, 1800);
        }

            // In Gutenberg, trigger prediction when post save completes and keep category & tag UI in sync
            if (window.wp && wp.data && wp.data.subscribe) {
                var wasSaving = false;
                var lastCategoryIdsStr = '';
                var lastTagIdsStr = '';

                wp.data.subscribe(function () {
                    var coreEditor = wp.data.select('core/editor');
                    if (!coreEditor) {
                        return;
                    }

                    // Synchronize recommended button & category chips when user modifies post categories in Gutenberg
                    var catIds = coreEditor.getEditedPostAttribute('categories') || [];
                    var catIdsStr = catIds.join(',');
                    if (catIdsStr !== lastCategoryIdsStr) {
                        lastCategoryIdsStr = catIdsStr;
                        refreshCategoryUI();
                    }

                    // Synchronize candidate tag chips when user adds or removes tags in Gutenberg
                    var tagIds = coreEditor.getEditedPostAttribute('tags') || [];
                    var tagIdsStr = tagIds.join(',');
                    if (tagIdsStr !== lastTagIdsStr) {
                        lastTagIdsStr = tagIdsStr;
                        refreshTagUI();
                    }

                    var isSaving = coreEditor.isSavingPost();
                    var isAutosaving = coreEditor.isAutosavingPost();

                    if (wasSaving && !isSaving && !isAutosaving) {
                        if (window.zeriseTaxoData && zeriseTaxoData.suggestionBehavior === 'manual') {
                            wasSaving = isSaving;
                            return;
                        }
                        var postData = getPostContent();
                        if (postData.title || postData.content) {
                            runPrediction();
                        }
                    }
                    wasSaving = isSaving;
                });
            }

            // In Classic Editor, trigger when user clicks Save Draft
            $('#save-post').on('click', function () {
                setTimeout(function () {
                    var postData = getPostContent();
                    if (postData.title || postData.content) {
                        runPrediction();
                    }
                }, 1200);
            });

        function showState(stateName) {
            $('#zerisetaxo-state-initial, #zerisetaxo-state-loading, #zerisetaxo-state-recommended, #zerisetaxo-state-ambiguous, #zerisetaxo-state-newcategory, #zerisetaxo-state-nomatch, #zerisetaxo-state-error').addClass('hidden');
            hideNotice();

            // Deduplicate stacked "Check again" buttons:
            // State cards for 'nomatch' and 'error' provide their own prominent action buttons.
            if (stateName === 'nomatch' || stateName === 'error' || stateName === 'initial' || stateName === 'loading') {
                $('#zerisetaxo-bottom-repredict-btn').addClass('hidden');
            } else {
                $('#zerisetaxo-bottom-repredict-btn').removeClass('hidden');
            }

            if (stateName === 'loading') {
                $('#zerisetaxo-state-loading').removeClass('hidden');
            } else if (stateName === 'recommended') {
                $('#zerisetaxo-state-recommended').removeClass('hidden');
            } else if (stateName === 'ambiguous') {
                $('#zerisetaxo-state-ambiguous').removeClass('hidden');
            } else if (stateName === 'newcategory') {
                $('#zerisetaxo-state-newcategory').removeClass('hidden');
            } else if (stateName === 'nomatch') {
                $('#zerisetaxo-state-nomatch').removeClass('hidden');
            } else if (stateName === 'error') {
                $('#zerisetaxo-state-error').removeClass('hidden');
            } else {
                $('#zerisetaxo-state-initial').removeClass('hidden');
            }
        }

        var noticeTimeout = null;
        function showNotice(message, isError) {
            var $statusNotice = $('#zerisetaxo-status-notice');
            var $noticeText   = $('#zerisetaxo-notice-text');
            if (noticeTimeout) {
                clearTimeout(noticeTimeout);
                noticeTimeout = null;
            }
            $statusNotice.removeClass('hidden notice-success notice-error');
            if (isError) {
                $statusNotice.addClass('notice-error');
            } else {
                $statusNotice.addClass('notice-success');
                noticeTimeout = setTimeout(function () {
                    hideNotice();
                }, 3500);
            }
            $noticeText.text(message);
        }

        function hideNotice() {
            $('#zerisetaxo-status-notice').addClass('hidden');
            $('#zerisetaxo-notice-text').empty();
        }

        // Extract title & body content from Block Editor or Classic Editor
        function getPostContent() {
            var title = '';
            var content = '';

            if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
                var editor = wp.data.select('core/editor');
                title = editor.getEditedPostAttribute('title') || '';
                content = editor.getEditedPostContent() || '';
            } else {
                title = $('#title').val() || '';
                if (window.tinyMCE && tinyMCE.get('content') && !tinyMCE.get('content').isHidden()) {
                    content = tinyMCE.get('content').getContent() || '';
                } else {
                    content = $('#content').val() || '';
                }
            }

            return {
                title: $.trim(title),
                content: $.trim(content)
            };
        }

        // Sync applied category with WordPress category checklist
        function syncCategoryWithWordPress(termId, categoryName, parentId) {
            if (!termId && !categoryName) {
                return false;
            }

            var defaultId = (window.zeriseTaxoData && zeriseTaxoData.defaultCategoryId) ? parseInt(zeriseTaxoData.defaultCategoryId, 10) : 1;
            var parentIdInt = parentId ? parseInt(parentId, 10) : 0;

            // Gutenberg Block Editor
            if (window.wp && wp.data && wp.data.dispatch && wp.data.select) {
                try {
                    var coreEditor = wp.data.select('core/editor');
                    if (coreEditor) {
                        var existing = coreEditor.getEditedPostAttribute('categories') || [];
                        var updated = existing.filter(function (id) {
                            return parseInt(id, 10) !== defaultId;
                        });
                        if (termId && updated.indexOf(termId) === -1) {
                            updated.push(termId);
                        }
                        if (parentIdInt > 0 && updated.indexOf(parentIdInt) === -1) {
                            updated.push(parentIdInt);
                        }
                        wp.data.dispatch('core/editor').editPost({ categories: updated });
                        if (termId) {
                            return true;
                        }
                    }
                } catch (e) {
                    // Fall through to DOM selector
                }
            }

            // Classic Editor or Gutenberg DOM fallback: uncheck default/uncategorized category
            if (defaultId) {
                var $defChk = $('#in-category-' + defaultId);
                if ($defChk.length) {
                    $defChk.prop('checked', false).trigger('change');
                }
            }
            $('#categorychecklist label, .editor-post-taxonomies__hierarchical-terms-list label').each(function () {
                var labelText = $.trim($(this).text()).toLowerCase();
                if (labelText === 'uncategorized') {
                    $(this).find('input[type="checkbox"]').prop('checked', false).trigger('change');
                }
            });

            // Classic Editor or Gutenberg DOM fallback: check parent category if present
            if (parentIdInt > 0) {
                var $pChk = $('#in-category-' + parentIdInt);
                if ($pChk.length) {
                    $pChk.prop('checked', true).trigger('change');
                }
            }

            // Classic Editor or Gutenberg DOM fallback: check new category
            if (termId) {
                var $chk = $('#in-category-' + termId);
                if ($chk.length) {
                    $chk.prop('checked', true).trigger('change');
                    return true;
                }
            }

            // Match by label text
            var matched = false;
            $('#categorychecklist label, .editor-post-taxonomies__hierarchical-terms-list label').each(function () {
                var labelText = $.trim($(this).text());
                if (labelText.toLowerCase() === categoryName.toLowerCase()) {
                    $(this).find('input[type="checkbox"]').prop('checked', true).trigger('change');
                    matched = true;
                    return false;
                }
            });

            return matched;
        }

        // Run prediction
        function runPrediction() {
            if (isRequestInProgress) {
                return;
            }

            hideNotice();
            var postData = getPostContent();

            if (!postData.title && !postData.content) {
                showNotice('Post title and body are both empty. Add text before suggesting.', true);
                return;
            }

            isRequestInProgress = true;
            showState('loading');

            $.ajax({
                url: zeriseTaxoData.restUrl,
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', zeriseTaxoData.nonce);
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    post_id: zeriseTaxoData.postId,
                    client_title: postData.title,
                    client_content: postData.content
                }),
                timeout: 30000
            })
            .done(function (response) {
                if (!response || !response.result_type) {
                    showState('error');
                    return;
                }

                var state             = response.state || 'confident';
                var termId            = parseInt(response.category_id, 10) || 0;
                var resultType        = response.result_type || 'none';
                var suggestedCat      = response.suggested_category || '';
                var isNewCatCandidate = (resultType === 'new_category_suggestion' || state === 'no_existing_category') && !!suggestedCat;
                var isOod             = !!response.is_ood || state === 'out_of_distribution' || state === 'insufficient_data';

                // Update diagnostic analytics in clean native layout
                if ($('#zerisetaxo-admin-diagnostics-details').length) {
                    var sourceMap = {
                        'php_site': 'Local Site Model',
                        'php_native': 'Local ML Engine',
                        'zero_shot': 'Zero-Shot Classifier',
                        'llm': 'AI Assistant',
                        'hybrid': 'Hybrid Engine'
                    };
                    var stateMap = {
                        'no_existing_category': zeriseTaxoData.i18n.newCategorySuggested || 'New Category Suggested',
                        'out_of_distribution': zeriseTaxoData.i18n.noStrongMatch || 'No Strong Match',
                        'insufficient_data': zeriseTaxoData.i18n.insufficientContent || 'Insufficient Content',
                        'ambiguous': zeriseTaxoData.i18n.reviewSuggested || 'Needs Review',
                        'confident': zeriseTaxoData.i18n.strongMatch || 'Strong Match',
                        'good_match': zeriseTaxoData.i18n.goodMatch || 'Good Match'
                    };

                    var stateText  = stateMap[state] || state.replace(/_/g, ' ');
                    var sourceText = sourceMap[response.decision_source] || (response.decision_source ? response.decision_source.replace(/_/g, ' ') : 'Local Engine');
                    var termIdText = (termId > 0) ? '#' + termId : '—';
                    var confText   = (response.confidence !== null && response.confidence !== undefined) ? Math.round(response.confidence * 1000) / 10 + '%' : '—';

                    if (isNewCatCandidate) {
                        $('#diag-summary-title').text(zeriseTaxoData.i18n.whySuggestNew || 'Why suggest a new category?');
                    } else if (termId > 0 && response.category && resultType === 'existing') {
                        $('#diag-summary-title').text(zeriseTaxoData.i18n.whyThisCategory || 'Why this category?');
                    } else {
                        $('#diag-summary-title').text(zeriseTaxoData.i18n.whyNoMatch || 'Why no match?');
                    }

                    $('#diag-state').text(stateText);
                    $('#diag-source').text(sourceText);
                    $('#diag-term-id').text(termIdText);
                    $('#diag-confidence').text(confText);
                    $('#diag-reasoning').text(response.reasoning || (isOod ? 'Content did not reach the threshold for a strong category match.' : 'Category prediction completed successfully.'));
                }

                if (isNewCatCandidate && state !== 'ambiguous') {
                    // State: Reliable New Category Candidate
                    currentSuggestedName = suggestedCat;
                    currentRecTermId     = 0;
                    currentRecCatName    = '';
                    $('#zerisetaxo-newcat-name').text(suggestedCat);
                    $('#zerisetaxo-create-use-btn-text').text('Create & Apply "' + suggestedCat + '"');
                    $('#zerisetaxo-use-rec-btn').prop('disabled', true);
                    renderCategoryChips([{ name: suggestedCat, term_id: 0, is_new: true }]);
                    showState('newcategory');
                } else if (isOod && state !== 'ambiguous') {
                    // State: No Strong Match / OOD
                    currentRecTermId     = 0;
                    currentRecCatName    = '';
                    currentSuggestedName = '';
                    $('#zerisetaxo-use-rec-btn').prop('disabled', true);
                    renderCategoryChips([]);
                    showState('nomatch');
                } else if (state === 'ambiguous') {
                    // State: Review Suggested / Ambiguous
                    var opt1Name = $.trim(response.category || '');
                    var opt1Id   = parseInt(response.category_id, 10) || 0;
                    var opt2Name = $.trim(response.runner_up_category || '');
                    var opt2Id   = parseInt(response.runner_up_category_id, 10) || 0;

                    // Safety requirement: Never render a selectable blank category.
                    if (!opt1Name || !opt2Name || opt1Id <= 0 || opt2Id <= 0 || opt1Id === opt2Id) {
                        if (opt1Name && opt1Id > 0) {
                            termId = opt1Id;
                            response.category = opt1Name;
                            resultType = 'existing';
                            state = 'good_match';
                        } else {
                            $('#zerisetaxo-error-title').text(zeriseTaxoData.i18n.ambiguousInvalid || 'Multiple categories were suggested, but one or more could not be verified. Please check again.');
                            $('#zerisetaxo-error-btn-text').text(zeriseTaxoData.i18n.checkAgain || 'Check again');
                            renderCategoryChips([]);
                            showState('error');
                            return;
                        }
                    }

                    if (state === 'ambiguous') {
                        // Reset error state text if previously set
                        $('#zerisetaxo-error-title').text(zeriseTaxoData.i18n.serviceUnavailable || 'Category suggestions are temporarily unavailable.');
                        $('#zerisetaxo-error-btn-text').text(zeriseTaxoData.i18n.tryAgain || 'Try again');

                        $('#zerisetaxo-ambig-opt-1').val(opt1Id).attr('data-cat-name', opt1Name).data('catName', opt1Name);
                        $('#zerisetaxo-ambig-label-1').text(opt1Name);

                        $('#zerisetaxo-ambig-opt-2').val(opt2Id).attr('data-cat-name', opt2Name).data('catName', opt2Name);
                        $('#zerisetaxo-ambig-label-2').text(opt2Name);

                        $('#zerisetaxo-ambig-opt-1').prop('checked', true);

                        var ambigChips = (response.candidate_categories && response.candidate_categories.length > 0)
                            ? response.candidate_categories
                            : [
                                { name: opt1Name, term_id: opt1Id, is_new: false },
                                { name: opt2Name, term_id: opt2Id, is_new: false }
                            ];
                        renderCategoryChips(ambigChips);
                        showState('ambiguous');
                    }
                }

                if (state !== 'ambiguous' && termId > 0 && response.category && resultType === 'existing') {
                    // State: Recommended / Confident (Requires valid existing canonical termId > 0)
                    currentRecTermId     = termId;
                    currentRecCatName    = response.category;
                    currentRecParentId   = parseInt(response.parent_id, 10) || 0;
                    currentRecParentName = response.parent_name || '';
                    lastPredictedContent = postData.title + '|' + postData.content;

                    $('#zerisetaxo-rec-category-name').text(currentRecCatName);
                    // Update engine source pill in category header
                    var source = response.decision_source || '';
                    var isLlm = (source === 'llm_gemini' || source === 'llm');
                    var pillHtml = isLlm
                        ? '<span class="zerisetaxo-engine-badge-gemini" title="Classified via Gemini AI Fallback"><i class="fa-solid fa-wand-magic-sparkles"></i> Gemini AI</span>'
                        : '<span class="zerisetaxo-engine-badge-local" title="Classified locally in < 1ms"><i class="fa-solid fa-bolt"></i> Local ML</span>';
                    $('#zerisetaxo-engine-source-pill').html(pillHtml).removeClass('hidden').show();
                    $('#zerisetaxo-rec-confidence-label').text(response.confidence_label || 'Strong match');
                    $('#zerisetaxo-use-rec-text').text('Apply Category');
                    $('#zerisetaxo-use-rec-btn').prop('disabled', false);

                    var catList = (response.candidate_categories && response.candidate_categories.length > 0)
                        ? response.candidate_categories
                        : [{
                            name: currentRecCatName,
                            term_id: currentRecTermId,
                            parent_id: currentRecParentId,
                            parent_name: currentRecParentName,
                            is_new: false
                        }];

                    // Alternative runner-up
                    var altTermId = parseInt(response.runner_up_category_id, 10) || 0;
                    var altConfidence = (response.runner_up_confidence !== null && response.runner_up_confidence !== undefined) ? parseFloat(response.runner_up_confidence) : 0;
                    if (zeriseTaxoData.showAlternative && response.runner_up_category && altTermId > 0 && altConfidence >= 0.10) {
                        currentAltTermId     = altTermId;
                        currentAltCatName    = response.runner_up_category;
                        currentAltParentId   = parseInt(response.runner_up_parent_id, 10) || 0;
                        currentAltParentName = response.runner_up_parent_name || '';
                        $('#zerisetaxo-alt-category-name').text(currentAltCatName);
                        $('#zerisetaxo-alternative-box').removeClass('hidden');
                    } else {
                        $('#zerisetaxo-alternative-box').addClass('hidden');
                    }

                    renderCategoryChips(catList);
                    updateRecommendedButtonState(currentRecTermId, currentRecCatName);
                    showState('recommended');
                } else {
                    currentRecTermId     = 0;
                    currentRecCatName    = '';
                    currentSuggestedName = '';
                    updateRecommendedButtonState(0, '');
                    renderCategoryChips([]);
                    showState('nomatch');
                }
            })
            .fail(function (xhr) {
                // Graceful failure: show friendly error state without blocking editor
                showState('error');
            })
            .always(function () {
                isRequestInProgress = false;
            });
        }

        // Apply Existing Category using canonical term_id only
        function applyExistingCategory(termId, categoryName, $btn, parentId) {
            if (!termId || termId <= 0) {
                showNotice('Invalid category term ID.', true);
                return;
            }

            var origHtml = $btn.html();
            $btn.prop('disabled', true).text('Applying...');

            $.ajax({
                url: zeriseTaxoData.applyUrl,
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', zeriseTaxoData.nonce);
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    post_id: zeriseTaxoData.postId,
                    term_id: termId
                }),
                timeout: 10000
            })
            .done(function (resp) {
                var resolvedParentId = parentId || (resp && resp.parent_id ? parseInt(resp.parent_id, 10) : 0);
                syncCategoryWithWordPress(termId, categoryName, resolvedParentId);
                showNotice(zeriseTaxoData.i18n.appliedSuccess || 'Category applied to post!', false);
                refreshCategoryUI();
                $btn.addClass('is-applied').html('Category Applied');
                setTimeout(function () {
                    refreshCategoryUI();
                }, 1500);
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to apply category.';
                showNotice(msg, true);
                $btn.prop('disabled', false).html(origHtml);
            });
        }

        // Create New Category & Apply
        function createAndApplyCategory(categoryName, $btn) {
            if (!categoryName) {
                return;
            }

            var origHtml = $btn.html();
            $btn.prop('disabled', true).text('Creating...');

            $.ajax({
                url: zeriseTaxoData.createApplyUrl,
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', zeriseTaxoData.nonce);
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    post_id: zeriseTaxoData.postId,
                    category_name: categoryName
                }),
                timeout: 15000
            })
            .done(function (resp) {
                var termId = resp.category_id || 0;
                syncCategoryWithWordPress(termId, categoryName);
                showNotice(zeriseTaxoData.i18n.createdSuccess || 'New category created and applied!', false);
                $btn.addClass('is-applied').html('Category Created & Applied');
                setTimeout(function () {
                    $btn.prop('disabled', false).html(origHtml);
                }, 1500);
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to create category.';
                showNotice(msg, true);
                $btn.prop('disabled', false).html(origHtml);
            });
        }

        // Content Staleness tracking
        var lastPredictedContent = '';
        function checkContentStaleness() {
            if (!lastPredictedContent || currentRecTermId <= 0) {
                return;
            }
            var currentData = getPostContent();
            var currentStr  = currentData.title + '|' + currentData.content;
            if (currentStr !== lastPredictedContent) {
                currentRecTermId = 0;
                $('#zerisetaxo-use-rec-btn').prop('disabled', true);
                showNotice('Content modified. Click Check again to refresh recommendations.', false);
            }
        }

        if (window.wp && wp.data && wp.data.subscribe) {
            wp.data.subscribe(function () {
                checkContentStaleness();
            });
        }
        $(document).on('input change', '#title, #content', function () {
            checkContentStaleness();
        });

        // Disable Use button on initial page load if savedCategoryId <= 0
        if (currentRecTermId <= 0 || zeriseTaxoData.savedState === 'out_of_distribution') {
            $('#zerisetaxo-use-rec-btn').prop('disabled', true);
        }

        // EVENT HANDLERS (Delegated on document for Gutenberg React compatibility)

        // Click Suggest Category / Check again / Try again
        $(document).on('click', '#zerisetaxo-suggest-btn', function (e) {
            e.preventDefault();
            runPrediction();
        });

        $(document).on('click', '.zerisetaxo-repredict-link', function (e) {
            e.preventDefault();
            e.stopPropagation();
            runPrediction();
        });

        // Click [ Use <Recommended> ]
        $(document).on('click', '#zerisetaxo-use-rec-btn', function (e) {
            e.preventDefault();
            applyExistingCategory(currentRecTermId, currentRecCatName, $(this), currentRecParentId);
        });

        // Click [ Use instead ] (Alternative runner-up)
        $(document).on('click', '#zerisetaxo-use-alt-btn', function (e) {
            e.preventDefault();
            applyExistingCategory(currentAltTermId, currentAltCatName, $(this), currentAltParentId);
        });

        // Click [ Use selected category ] (Ambiguous state)
        $(document).on('click', '#zerisetaxo-use-ambiguous-btn', function (e) {
            e.preventDefault();
            var $checked = $('input[name="zerisetaxo_ambiguous_choice"]:checked');
            if (!$checked.length) {
                showNotice('Please select a category first.', true);
                return;
            }
            var selectedId   = parseInt($checked.val(), 10) || 0;
            var selectedName = $checked.data('catName') || $checked.attr('data-cat-name') || '';

            if (selectedId <= 0 || !selectedName) {
                showNotice('Invalid category selection.', true);
                return;
            }

            applyExistingCategory(selectedId, selectedName, $(this));
        });

        // Click [ Suggest New Category ] (Queues for admin review, never auto-creates)
        $(document).on('click', '#zerisetaxo-queue-suggestion-btn', function (e) {
            e.preventDefault();
            if (!currentSuggestedName) {
                showNotice('No candidate category name to suggest.', true);
                return;
            }

            var $btn = $(this);
            var origHtml = $btn.html();
            $btn.prop('disabled', true).text('Queuing...');

            $.ajax({
                url: zeriseTaxoData.queueUrl,
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', zeriseTaxoData.nonce);
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    post_id: zeriseTaxoData.postId,
                    category_name: currentSuggestedName
                }),
                timeout: 10000
            })
            .done(function (resp) {
                showNotice(resp.message || zeriseTaxoData.i18n.queuedSuccess || 'Category suggestion queued for review!', false);
                $btn.html('<i class="fa-solid fa-check" style="color: #10B981;"></i> Queued for Review');
                setTimeout(function () {
                    $btn.prop('disabled', false).html(origHtml);
                }, 2000);
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to queue category suggestion.';
                showNotice(msg, true);
                $btn.prop('disabled', false).html(origHtml);
            });
        });

        // Click [ Create & use ] (Legacy novel category creation fallback)
        $(document).on('click', '#zerisetaxo-create-use-btn', function (e) {
            e.preventDefault();
            createAndApplyCategory(currentSuggestedName, $(this));
        });

        // Click [ Choose category manually ]
        $(document).on('click', '.zerisetaxo-choose-myself-action, #zerisetaxo-choose-myself-btn', function (e) {
            e.preventDefault();
            if (window.wp && wp.data && wp.data.dispatch) {
                try {
                    var editPost = wp.data.dispatch('core/edit-post');
                    if (editPost && editPost.openGeneralSidebar) {
                        editPost.openGeneralSidebar('edit-post/document');
                    }
                } catch (err) {}
            }

            setTimeout(function () {
                var $catToggle = $('.components-panel__body:has(.editor-post-taxonomies__hierarchical-terms-list) .components-panel__body-toggle, button.components-panel__body-toggle:contains("Categories")');
                if ($catToggle.length && $catToggle.attr('aria-expanded') === 'false') {
                    $catToggle.trigger('click');
                }

                var $gutenbergTerms = $('.editor-post-taxonomies__hierarchical-terms-list, .components-panel__body:has(.editor-post-taxonomies__hierarchical-terms-list)');
                var $classicTarget = $('#taxonomy-category');

                if ($gutenbergTerms.length) {
                    $gutenbergTerms[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    $gutenbergTerms.find('input[type="checkbox"]').first().focus();
                } else if ($classicTarget.length) {
                    $('html, body').animate({ scrollTop: $classicTarget.offset().top - 80 }, 300);
                    $classicTarget.find('input[type="checkbox"]').first().focus();
                }
            }, 80);
        });

        // -------------------------------------------------------------
        // ZERISETAXO V1 TAG SUGGESTIONS BOOTSTRAP UI
        // -------------------------------------------------------------
        var currentTagPredictXhr = null;
        var tagPredictRequestId  = 0;

        function runTagPrediction() {
            if (window.zeriseTaxoData && zeriseTaxoData.enableTagSuggestions === false) {
                return;
            }
            var $tagsLoading   = $('#zerisetaxo-tags-loading');
            var $tagsChipsBox  = $('#zerisetaxo-tags-chips-container');
            var $tagsStatusMsg = $('#zerisetaxo-tags-status-msg');

            var postData = getPostContent();
            if (!postData.title && !postData.content) {
                $tagsStatusMsg.text('Add title or content text to get tag suggestions.').removeClass('hidden').show();
                return;
            }

            // Abort pending tag prediction request (Request Race Safeguard)
            if (currentTagPredictXhr && currentTagPredictXhr.readyState !== 4) {
                currentTagPredictXhr.abort();
            }

            tagPredictRequestId++;
            var requestId = tagPredictRequestId;

            $tagsLoading.removeClass('hidden').show();
            $tagsStatusMsg.addClass('hidden').hide();
            $('#zerisetaxo-tags-hint').addClass('hidden').hide();
            $tagsChipsBox.empty();

            var requestUrl = (window.zeriseTaxoData && zeriseTaxoData.predictTagsUrl)
                ? zeriseTaxoData.predictTagsUrl
                : (window.wp && wp.apiSettings && wp.apiSettings.root
                    ? (wp.apiSettings.root + 'zerisetaxo/v1/predict-tags')
                    : '/wp-json/zerisetaxo/v1/predict-tags');

            currentTagPredictXhr = $.ajax({
                url: requestUrl,
                type: 'POST',
                beforeSend: function (xhr) {
                    if (window.zeriseTaxoData && zeriseTaxoData.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', zeriseTaxoData.nonce);
                    } else if (window.wp && wp.apiSettings && wp.apiSettings.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', wp.apiSettings.nonce);
                    }
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    title: postData.title,
                    content: postData.content,
                    limit: (window.zeriseTaxoData && zeriseTaxoData.tagSuggestionLimit) ? parseInt(zeriseTaxoData.tagSuggestionLimit, 10) : 5
                }),
                timeout: 10000
            })
            .done(function (response) {
                if (requestId !== tagPredictRequestId) return;
                $tagsLoading.addClass('hidden').hide();
                if (response && response.status === 'success' && response.suggestions && response.suggestions.length > 0) {
                    if (response.explanation) {
                        $('#zerisetaxo-tags-group-reason').text(response.explanation);
                    }
                    renderTagChips(response.suggestions);
                } else {
                    $('#zerisetaxo-tags-hint').addClass('hidden').hide();
                    $('#zerisetaxo-tags-explanation-details').addClass('hidden').hide();
                    $tagsStatusMsg.text('No strong tag suggestions found for this post.').removeClass('hidden').show();
                    $('#zerisetaxo-suggest-tags-btn').removeClass('is-refreshed');
                    $('#zerisetaxo-suggest-tags-btn-text').text((window.zeriseTaxoData && zeriseTaxoData.i18n && zeriseTaxoData.i18n.suggestTags) ? zeriseTaxoData.i18n.suggestTags : 'Suggest Tags');
                    $('#zerisetaxo-suggest-tags-btn i').removeClass('fa-rotate-right').addClass('fa-wand-magic-sparkles').css('color', '#BFDBFE');
                }
            })
            .fail(function (xhr, status) {
                if (status === 'abort') return;
                if (requestId !== tagPredictRequestId) return;
                $tagsLoading.addClass('hidden').hide();
                $('#zerisetaxo-tags-hint').addClass('hidden').hide();
                $('#zerisetaxo-tags-explanation-details').addClass('hidden').hide();
                $tagsStatusMsg.text('Unable to suggest tags. Try again.').removeClass('hidden').show();
                $('#zerisetaxo-suggest-tags-btn').removeClass('is-refreshed');
                $('#zerisetaxo-suggest-tags-btn-text').text((window.zeriseTaxoData && zeriseTaxoData.i18n && zeriseTaxoData.i18n.suggestTags) ? zeriseTaxoData.i18n.suggestTags : 'Suggest Tags');
                $('#zerisetaxo-suggest-tags-btn i').removeClass('fa-rotate-right').addClass('fa-wand-magic-sparkles').css('color', '#BFDBFE');
            });
        }

        var lastTagSuggestions = [];

        function refreshTagUI() {
            if (lastTagSuggestions && lastTagSuggestions.length > 0) {
                renderTagChips(lastTagSuggestions);
            }
        }

        function renderTagChips(suggestions) {
            var $tagsChipsBox   = $('#zerisetaxo-tags-chips-container');
            var $tagsHint       = $('#zerisetaxo-tags-hint');
            var $explainDetails = $('#zerisetaxo-tags-explanation-details');
            var $reasonsList    = $('#zerisetaxo-tags-reasons-list');

            $tagsChipsBox.empty();
            $reasonsList.empty();

            if (Array.isArray(suggestions) && suggestions.length > 0) {
                lastTagSuggestions = suggestions;
            }

            if (!suggestions || suggestions.length === 0) {
                $tagsHint.addClass('hidden').hide();
                $('#zerisetaxo-apply-all-tags-btn').addClass('hidden').hide();
                $explainDetails.addClass('hidden').hide();
                return;
            }

            $tagsHint.removeClass('hidden').show();
            var currentPostTags = getCurrentPostTagIds();
            var totalChips = suggestions.length;
            var appliedCount = 0;

            $.each(suggestions, function (idx, item) {
                var tagName = item.tag_name || '';
                var slug = item.slug || '';
                var existingTermId = item.existing_term_id ? parseInt(item.existing_term_id, 10) : null;
                var isAdded = Boolean(existingTermId && currentPostTags.indexOf(existingTermId) !== -1);
                if (isAdded) {
                    appliedCount++;
                }
                var reasonText = item.reason || 'Relevant topic identified in content.';
                var matchLabel = item.match_label || (item.candidate_score >= 0.8 ? 'Strong match' : 'Good match');

                var titleText = isAdded ? ('Tag already applied: ' + tagName) : (tagName + ' — ' + reasonText);

                var $chip = $('<button type="button" class="zerisetaxo-tag-chip-btn"></button>')
                    .data('tagName', tagName)
                    .data('slug', slug)
                    .data('termId', existingTermId)
                    .data('isAdded', isAdded)
                    .attr('title', titleText)
                    .attr('aria-label', titleText)
                    .css({
                        'background': isAdded ? '#F1F5F9' : '#EFF6FF',
                        'color': isAdded ? '#64748B' : '#1D4ED8',
                        'border': '1px solid ' + (isAdded ? '#CBD5E1' : '#BFDBFE'),
                        'border-radius': '14px',
                        'padding': '3px 10px',
                        'font-size': '12px',
                        'font-weight': '600',
                        'cursor': isAdded ? 'default' : 'pointer',
                        'display': 'inline-flex',
                        'align-items': 'center',
                        'gap': '4px',
                        'transition': 'all 0.15s ease'
                    })
                    .html(isAdded ? ('✓ ' + escapeHtml(tagName)) : ('+ ' + escapeHtml(tagName)));

                if (isAdded) {
                    $chip.addClass('is-applied').prop('disabled', true);
                }

                $tagsChipsBox.append($chip);

                // Add per-tag reason to "Why these tags?" panel list
                var $li = $('<li></li>').html(
                    '<strong>' + escapeHtml(tagName) + '</strong> — ' + escapeHtml(reasonText) +
                    ' <span style="color: #64748B; font-size: 11px;">(' + escapeHtml(matchLabel) + ')</span>'
                );
                $reasonsList.append($li);
            });

            if (appliedCount >= totalChips && totalChips > 0) {
                $('#zerisetaxo-apply-all-tags-btn').removeClass('hidden').show().addClass('is-applied').prop('disabled', true).html('✓ All Applied');
            } else {
                $('#zerisetaxo-apply-all-tags-btn').removeClass('hidden is-applied').show().prop('disabled', false).html('+ ' + (zeriseTaxoData.i18n.applyAll || 'Apply All'));
            }

            $explainDetails.removeClass('hidden').show();

            if (suggestions && suggestions.length > 0) {
                $('#zerisetaxo-suggest-tags-btn').addClass('is-refreshed');
                $('#zerisetaxo-suggest-tags-btn-text').text((window.zeriseTaxoData && zeriseTaxoData.i18n && zeriseTaxoData.i18n.refreshTags) ? zeriseTaxoData.i18n.refreshTags : 'Refresh Tags');
                $('#zerisetaxo-suggest-tags-btn i').removeClass('fa-wand-magic-sparkles').addClass('fa-rotate-right').css('color', '#64748B');
            }
        }


        var lastCatList = [];

        function updateRecommendedButtonState(termId, categoryName) {
            var $btn = $('#zerisetaxo-use-rec-btn');
            if (!$btn || !$btn.length) return;

            var termIdInt = parseInt(termId, 10) || 0;
            var catName = categoryName || currentRecCatName || zeriseTaxoData.savedCategory || '';

            if (termIdInt <= 0 || !catName) {
                $btn.prop('disabled', true)
                    .removeClass('is-applied')
                    .html('<i class="fa-solid fa-plus" aria-hidden="true" style="margin-right: 6px;"></i> Apply Category');
                return;
            }

            var currentPostCats = getCurrentPostCategoryIds();
            var isAdded = currentPostCats.indexOf(termIdInt) !== -1;

            if (isAdded) {
                $btn.prop('disabled', true)
                    .addClass('is-applied')
                    .html('Category Applied');
            } else {
                $btn.prop('disabled', false)
                    .removeClass('is-applied')
                    .html('<i class="fa-solid fa-plus" aria-hidden="true" style="margin-right: 6px;"></i> Apply Category');
            }
        }

        function refreshCategoryUI() {
            if (currentRecTermId > 0 && currentRecCatName) {
                updateRecommendedButtonState(currentRecTermId, currentRecCatName);
            }
            if (lastCatList && lastCatList.length > 0) {
                renderCategoryChips(lastCatList);
            }
        }

        // -------------------------------------------------------------
        // CATEGORY SUGGESTION CHIPS (Suggested like tags)
        // -------------------------------------------------------------
        function renderCategoryChips(categories) {
            var $catChipsBox     = $('#zerisetaxo-category-chips-container');
            var $catChipsSection = $('#zerisetaxo-category-chips-section');
            if (!$catChipsBox || !$catChipsBox.length) return;
            $catChipsBox.empty();

            var list = Array.isArray(categories) ? categories : [];
            if (list.length > 0) {
                lastCatList = list;
            }

            if (list.length === 0) {
                if ($catChipsSection && $catChipsSection.length) {
                    $catChipsSection.addClass('hidden');
                }
                return;
            }

            if ($catChipsSection && $catChipsSection.length) {
                $catChipsSection.removeClass('hidden');
            }

            var currentPostCats = getCurrentPostCategoryIds();

            $.each(list, function (idx, item) {
                var catName = escapeHtml(item.name);
                var parentName = item.parent_name ? escapeHtml(item.parent_name) : '';
                var termId = item.term_id ? parseInt(item.term_id, 10) : 0;
                var parentId = item.parent_id ? parseInt(item.parent_id, 10) : 0;
                var isNew = !!item.is_new;
                var isAdded = termId > 0 && currentPostCats.indexOf(termId) !== -1;

                var chipLabel = catName;
                if (parentName) {
                    chipLabel += ' (Sub of ' + parentName + ')';
                }

                var $chip = $('<button type="button" class="zerisetaxo-cat-chip-btn"></button>')
                    .data('catName', item.name)
                    .data('termId', termId)
                    .data('parentId', parentId)
                    .data('isNew', isNew)
                    .attr('title', isAdded ? 'Category already applied' : 'Click on the category to apply')
                    .attr('aria-label', isAdded ? 'Category already applied' : 'Click on the category to apply')
                    .css({
                        'background': isAdded ? '#F1F5F9' : '#EFF6FF',
                        'color': isAdded ? '#64748B' : '#1D4ED8',
                        'border': '1px solid ' + (isAdded ? '#CBD5E1' : '#BFDBFE'),
                        'border-radius': '14px',
                        'padding': '3px 10px',
                        'font-size': '12px',
                        'font-weight': '600',
                        'cursor': isAdded ? 'default' : 'pointer',
                        'display': 'inline-flex',
                        'align-items': 'center',
                        'gap': '4px',
                        'transition': 'all 0.15s ease'
                    })
                    .html(isAdded ? ('✓ ' + chipLabel) : (isNew ? ('+ ' + chipLabel + ' (New)') : ('+ ' + chipLabel)));

                if (isAdded) {
                    $chip.prop('disabled', true);
                }

                $catChipsBox.append($chip);
            });

            if (!$('#zerisetaxo-state-recommended').hasClass('hidden')) {
                $catChipsSection.addClass('hidden');
            } else {
                $catChipsSection.removeClass('hidden');
            }
        }

        function getCurrentPostCategoryIds() {
            if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
                return wp.data.select('core/editor').getEditedPostAttribute('categories') || [];
            }
            return [];
        }

        // Click candidate category chip button [ + CategoryName ]
        $(document).on('click', '.zerisetaxo-cat-chip-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.prop('disabled')) return;

            var catName = $btn.data('catName');
            var termId = $btn.data('termId');
            var parentId = $btn.data('parentId');
            var isNew = $btn.data('isNew');

            if (isNew) {
                createAndApplyCategory(catName, $btn);
            } else if (termId > 0) {
                applyExistingCategory(termId, catName, $btn, parentId);
            }
        });

        function getCurrentPostTagIds() {
            if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
                return wp.data.select('core/editor').getEditedPostAttribute('tags') || [];
            }
            return [];
        }

        function escapeHtml(str) {
            return $('<div>').text(str || '').html();
        }

        $(document).on('click', '#zerisetaxo-suggest-tags-btn', function (e) {
            e.preventDefault();
            runTagPrediction();
        });

        // Click candidate tag chip button [ + TagName ]
        
        // Helper: Mark a tag chip button as successfully applied
        function markChipApplied($chip, termId, tagName) {
            $chip.data('termId', termId)
                .data('isAdded', true)
                .addClass('is-applied')
                .prop('disabled', true)
                .css({
                    'background': '#F1F5F9',
                    'color': '#64748B',
                    'border-color': '#CBD5E1',
                    'cursor': 'default'
                })
                .attr('title', 'Tag already applied: ' + tagName)
                .attr('aria-label', 'Tag already applied: ' + tagName)
                .html('✓ ' + escapeHtml(tagName));
        }

        // Helper: Add tag IDs to Gutenberg core editor or Classic Editor
        function addTagsToPostEditor(termIds, tagNames) {
            if (window.wp && wp.data && wp.data.dispatch && wp.data.select) {
                try {
                    var coreEditor = wp.data.select('core/editor');
                    if (coreEditor) {
                        var existingTags = coreEditor.getEditedPostAttribute('tags') || [];
                        var intIds = termIds.map(function (id) { return parseInt(id, 10); }).filter(function (id) { return id > 0; });
                        var merged = Array.from(new Set(existingTags.concat(intIds)));
                        wp.data.dispatch('core/editor').editPost({ tags: merged });
                    }
                } catch (e) {}
            }

            // Fallback for Classic Editor
            var $classicInput = $('#new-tag-post_tag');
            if ($classicInput.length && tagNames && tagNames.length) {
                var val = $classicInput.val();
                var newNames = tagNames.join(', ');
                $classicInput.val(val ? (val + ', ' + newNames) : newNames);
                $('.tagadd').click();
            }
        }

        // Helper: Check if all tag chips are applied to update Apply All button
        function checkAllTagsApplied() {
            var unappliedCount = $('#zerisetaxo-tags-chips-container .zerisetaxo-tag-chip-btn').filter(function () {
                return !$(this).data('isAdded') && !$(this).hasClass('is-applied');
            }).length;
            if (unappliedCount === 0) {
                $('#zerisetaxo-apply-all-tags-btn').addClass('is-applied').prop('disabled', true).html('✓ All Applied');
            }
        }

        // Helper: Resolve existing term ID or create tag via WP REST API
        function resolveOrFetchTagId(tagName, slug) {
            var dfd = $.Deferred();
            var tagsUrl = (window.zeriseTaxoData && zeriseTaxoData.tagsRestUrl)
                ? zeriseTaxoData.tagsRestUrl
                : (window.wp && wp.apiSettings && wp.apiSettings.root
                    ? (wp.apiSettings.root + 'wp/v2/tags')
                    : '/wp-json/wp/v2/tags');

            $.ajax({
                url: tagsUrl,
                type: 'POST',
                beforeSend: function (xhr) {
                    if (window.zeriseTaxoData && zeriseTaxoData.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', zeriseTaxoData.nonce);
                    } else if (window.wp && wp.apiSettings && wp.apiSettings.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', wp.apiSettings.nonce);
                    }
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    name: tagName,
                    slug: slug
                }),
                timeout: 10000
            })
            .done(function (term) {
                if (term && term.id) {
                    if (window.wp && wp.data && wp.data.dispatch && wp.data.dispatch('core')) {
                        try {
                            wp.data.dispatch('core').receiveEntityRecords('taxonomy', 'post_tag', [term], { page: 1 });
                        } catch (e) {}
                    }
                    dfd.resolve(term.id);
                } else {
                    dfd.reject();
                }
            })
            .fail(function (xhr) {
                if (xhr.responseJSON && xhr.responseJSON.code === 'term_exists' && xhr.responseJSON.data && xhr.responseJSON.data.term_id) {
                    dfd.resolve(xhr.responseJSON.data.term_id);
                } else {
                    dfd.reject(xhr);
                }
            });

            return dfd.promise();
        }

        // Apply all suggested tags in 1 click (coordinated batch)
        $(document).on('click', '#zerisetaxo-apply-all-tags-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.hasClass('is-applied') || $btn.prop('disabled')) return;

            var $unappliedChips = $('#zerisetaxo-tags-chips-container .zerisetaxo-tag-chip-btn').filter(function () {
                return !$(this).data('isAdded') && !$(this).hasClass('is-applied');
            });

            if ($unappliedChips.length === 0) {
                $btn.addClass('is-applied').prop('disabled', true).html('✓ All Applied');
                return;
            }

            $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Applying...');
            $unappliedChips.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Adding...');

            var tasks = [];
            $unappliedChips.each(function () {
                var $chip = $(this);
                var tagName = $chip.data('tagName');
                var slug = $chip.data('slug');
                var existingTermId = $chip.data('termId');

                if (existingTermId && parseInt(existingTermId, 10) > 0) {
                    tasks.push($.Deferred().resolve({ termId: parseInt(existingTermId, 10), tagName: tagName, $chip: $chip }).promise());
                } else {
                    var p = resolveOrFetchTagId(tagName, slug).then(function (newTermId) {
                        return { termId: parseInt(newTermId, 10), tagName: tagName, $chip: $chip };
                    }, function (err) {
                        return { termId: null, tagName: tagName, $chip: $chip, error: err };
                    });
                    tasks.push(p);
                }
            });

            $.when.apply($, tasks).done(function () {
                var results = Array.prototype.slice.call(arguments);
                if (tasks.length === 1) {
                    results = [arguments[0]];
                }

                var idsToApply = [];
                var namesToApply = [];
                var appliedChips = [];

                results.forEach(function (res) {
                    if (res && res.termId && res.termId > 0) {
                        idsToApply.push(res.termId);
                        namesToApply.push(res.tagName);
                        appliedChips.push(res);
                        markChipApplied(res.$chip, res.termId, res.tagName);
                    } else if (res && res.$chip) {
                        res.$chip.prop('disabled', false).html('+ ' + escapeHtml(res.tagName));
                    }
                });

                if (idsToApply.length > 0) {
                    addTagsToPostEditor(idsToApply, namesToApply);
                }

                checkAllTagsApplied();

                if (appliedChips.length > 0) {
                    $btn.addClass('is-applied').prop('disabled', true).html('✓ Applied (' + appliedChips.length + ')');
                    showNotice(appliedChips.length + ' tags applied to post!', false);
                } else {
                    $btn.prop('disabled', false).html('+ ' + (zeriseTaxoData.i18n.applyAll || 'Apply All'));
                }
            });
        });

        // Click individual candidate tag chip button [ + TagName ]
        $(document).on('click', '.zerisetaxo-tag-chip-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.hasClass('is-applied') || $btn.prop('disabled') || $btn.data('isAdded')) return;

            var tagName = $btn.data('tagName');
            var slug = $btn.data('slug');
            var existingTermId = $btn.data('termId');

            $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Adding...');

            if (existingTermId && parseInt(existingTermId, 10) > 0) {
                var termIdInt = parseInt(existingTermId, 10);
                addTagsToPostEditor([termIdInt], [tagName]);
                markChipApplied($btn, termIdInt, tagName);
                checkAllTagsApplied();
                showNotice('Tag "' + tagName + '" applied!', false);
            } else {
                resolveOrFetchTagId(tagName, slug).done(function (newTermId) {
                    var termIdInt = parseInt(newTermId, 10);
                    addTagsToPostEditor([termIdInt], [tagName]);
                    markChipApplied($btn, termIdInt, tagName);
                    checkAllTagsApplied();
                    showNotice('Tag "' + tagName + '" applied!', false);
                }).fail(function (xhr) {
                    $btn.prop('disabled', false).html('+ ' + escapeHtml(tagName));
                    var errorMsg = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Could not add tag. Please try again.';
                    showNotice(errorMsg, true);
                });
            }
        });

    });
})(jQuery);
