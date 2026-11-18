<?php
/**
 * Admin User Guide View for ZeriseTaxo.
 * Clean, Customer-Friendly SaaS Documentation Hub with Complete Feature Guidance.
 */
defined('ABSPATH') || exit;

$zerisetaxo_settings_url = admin_url('admin.php?page=' . \ZeriseTaxo\Admin\SettingsPage::MENU_SLUG);
$zerisetaxo_new_post_url = admin_url('post-new.php');
?>
<div class="zerisetaxo-db-wrap" style="max-width: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">

    <!-- DOCUMENTATION TWO-COLUMN LAYOUT (STARTS CLEANLY DIRECTLY BELOW TABS) -->
    <style>
html {
    scroll-behavior: smooth;
}
.zerisetaxo-col-sidebar {
    position: sticky;
    top: 48px;
    align-self: start;
}
.zerisetaxo-saas-card {
    scroll-margin-top: 60px;
}
</style>
    <div class="zerisetaxo-two-col" style="display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 24px; align-items: start; margin-bottom: 20px;">

        <!-- LEFT SIDEBAR: STICKY TABLE OF CONTENTS -->
        <aside class="zerisetaxo-col-sidebar" style="order: 1;">
            <div class="zerisetaxo-sidebar-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 18px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #94A3B8; margin-bottom: 12px; padding-left: 8px;">
                    <?php esc_html_e('Table of Contents', 'zerisetaxo'); ?>
                </div>

                <nav style="display: flex; flex-direction: column; gap: 4px; font-size: 13px;">
                    <a href="#doc-quick-start" class="zerisetaxo-toc-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; transition: all 0.15s ease;">
                        <i class="fa-solid fa-rocket" style="color: #4F46E5; width: 16px; font-size: 13px;"></i>
                        <span><?php esc_html_e('1. Quick Start Guide', 'zerisetaxo'); ?></span>
                    </a>
                    <a href="#doc-editor-features" class="zerisetaxo-toc-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; transition: all 0.15s ease;">
                        <i class="fa-solid fa-pen-to-square" style="color: #4F46E5; width: 16px; font-size: 13px;"></i>
                        <span><?php esc_html_e('2. Editor Panel Features', 'zerisetaxo'); ?></span>
                    </a>
                    <a href="#doc-architecture" class="zerisetaxo-toc-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; transition: all 0.15s ease;">
                        <i class="fa-solid fa-brain" style="color: #4F46E5; width: 16px; font-size: 13px;"></i>
                        <span><?php esc_html_e('3. How the Engine Works', 'zerisetaxo'); ?></span>
                    </a>
                    <a href="#doc-training" class="zerisetaxo-toc-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; transition: all 0.15s ease;">
                        <i class="fa-solid fa-graduation-cap" style="color: #4F46E5; width: 16px; font-size: 13px;"></i>
                        <span><?php esc_html_e('4. Site-Specific Learning', 'zerisetaxo'); ?></span>
                    </a>
                    <a href="#doc-cloud-ai" class="zerisetaxo-toc-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; transition: all 0.15s ease;">
                        <i class="fa-solid fa-wand-magic-sparkles" style="color: #4F46E5; width: 16px; font-size: 13px;"></i>
                        <span><?php esc_html_e('5. Google Gemini AI & Privacy', 'zerisetaxo'); ?></span>
                    </a>
                    <a href="#doc-rest-api" class="zerisetaxo-toc-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; transition: all 0.15s ease;">
                        <i class="fa-solid fa-code" style="color: #4F46E5; width: 16px; font-size: 13px;"></i>
                        <span><?php esc_html_e('6. REST API Reference', 'zerisetaxo'); ?></span>
                    </a>
                    <a href="#doc-faq" class="zerisetaxo-toc-item" style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; transition: all 0.15s ease;">
                        <i class="fa-solid fa-circle-question" style="color: #4F46E5; width: 16px; font-size: 13px;"></i>
                        <span><?php esc_html_e('7. Common Questions & FAQ', 'zerisetaxo'); ?></span>
                    </a>
                </nav>

                <div style="border-top: 1px solid #F1F5F9; margin-top: 18px; padding-top: 14px;">
                    <a href="<?php echo esc_url($zerisetaxo_settings_url); ?>" class="button button-secondary" style="width: 100%; text-align: center; font-weight: 600; border-radius: 6px; font-size: 12px; padding: 4px 10px;">
                        <i class="fa-solid fa-sliders" style="margin-right: 4px;"></i>
                        <?php esc_html_e('Open Settings', 'zerisetaxo'); ?>
                    </a>
                </div>
            </div>
        </aside>

        <!-- RIGHT MAIN CONTENT COLUMN -->
        <main class="zerisetaxo-col-main" style="order: 2; display: flex; flex-direction: column; gap: 24px;">

            <!-- SECTION 1: QUICK START GUIDE -->
            <section id="doc-quick-start" class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 26px; scroll-margin-top: 30px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <h3 style="margin: 0 0 10px; font-size: 17px; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-rocket" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('1. Quick Start Guide', 'zerisetaxo'); ?></span>
                </h3>
                <p style="font-size: 13.5px; color: #475569; line-height: 1.6; margin: 0 0 18px;">
                    <?php esc_html_e('ZeriseTaxo starts working immediately upon activation. Follow these 3 simple steps to tag your content automatically:', 'zerisetaxo'); ?>
                </p>

                <!-- 3 Visual Step Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 20px;">
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 18px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <span style="display: inline-flex; width: 22px; height: 22px; border-radius: 50%; background: #4F46E5; color: #FFFFFF; font-size: 11px; font-weight: 800; align-items: center; justify-content: center;">1</span>
                            <div style="font-size: 14px; font-weight: 700; color: #0F172A;"><?php esc_html_e('Write Your Article', 'zerisetaxo'); ?></div>
                        </div>
                        <div style="font-size: 12.5px; color: #64748B; line-height: 1.55;"><?php esc_html_e('Open the post editor and enter your title and body copy as you normally do.', 'zerisetaxo'); ?></div>
                    </div>

                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 18px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <span style="display: inline-flex; width: 22px; height: 22px; border-radius: 50%; background: #4F46E5; color: #FFFFFF; font-size: 11px; font-weight: 800; align-items: center; justify-content: center;">2</span>
                            <div style="font-size: 14px; font-weight: 700; color: #0F172A;"><?php esc_html_e('View Suggestions', 'zerisetaxo'); ?></div>
                        </div>
                        <div style="font-size: 12.5px; color: #64748B; line-height: 1.55;"><?php esc_html_e('ZeriseTaxo scans your text in ~0.2ms and displays category and tag recommendations in the sidebar.', 'zerisetaxo'); ?></div>
                    </div>

                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 18px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <span style="display: inline-flex; width: 22px; height: 22px; border-radius: 50%; background: #10B981; color: #FFFFFF; font-size: 11px; font-weight: 800; align-items: center; justify-content: center;">3</span>
                            <div style="font-size: 14px; font-weight: 700; color: #0F172A;"><?php esc_html_e('1-Click Assign', 'zerisetaxo'); ?></div>
                        </div>
                        <div style="font-size: 12.5px; color: #64748B; line-height: 1.55;"><?php esc_html_e('Click "Use Category" or click tag chips to apply them directly into WordPress with zero typing.', 'zerisetaxo'); ?></div>
                    </div>
                </div>

                <!-- Feature Note Box -->
                <div style="background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px; padding: 14px 18px; font-size: 13px; color: #065F46; line-height: 1.6; display: flex; align-items: flex-start; gap: 12px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 16px; color: #10B981; margin-top: 2px; flex-shrink: 0;"></i>
                    <div>
                        <strong><?php esc_html_e('100% Native & Private:', 'zerisetaxo'); ?></strong>
                        <?php esc_html_e('ZeriseTaxo runs directly inside PHP on your server. It does not require any Python servers, external subscriptions, or third-party tracking.', 'zerisetaxo'); ?>
                    </div>
                </div>
            </section>

            <!-- SECTION 2: EDITOR PANEL FEATURES -->
            <section id="doc-editor-features" class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 26px; scroll-margin-top: 30px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <h3 style="margin: 0 0 10px; font-size: 17px; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-to-square" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('2. Editor Panel Features & Controls', 'zerisetaxo'); ?></span>
                </h3>
                <p style="font-size: 13.5px; color: #475569; line-height: 1.6; margin: 0 0 16px;">
                    <?php esc_html_e('When writing or editing any post, look for the "ZeriseTaxo Suggestions" box in the right sidebar. Here is what each element does:', 'zerisetaxo'); ?>
                </p>

                <!-- Visual Editor Preview Card -->
                <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 18px; margin-bottom: 18px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748B; letter-spacing: 0.05em; margin-bottom: 12px;">
                        <?php esc_html_e('Sidebar Panel Preview', 'zerisetaxo'); ?>
                    </div>

                    <!-- Category Row -->
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; margin-bottom: 2px;">
                                <?php esc_html_e('Top Recommended Category', 'zerisetaxo'); ?>
                            </div>
                            <strong style="font-size: 15px; color: #0F172A;">Technology &amp; Web</strong>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 12px; font-weight: 700; color: #059669;">
                                94% match
                            </span>
                            <span class="button button-small button-primary" style="font-weight: 600; font-size: 12px; border-radius: 6px;">
                                <?php esc_html_e('Use Category', 'zerisetaxo'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Tags Row -->
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                            <span style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">
                                <?php esc_html_e('Recommended Tags', 'zerisetaxo'); ?>
                            </span>
                            <span style="font-size: 12px; font-weight: 700; color: #4F46E5; cursor: pointer;">
                                <i class="fa-solid fa-plus-circle" style="margin-right: 3px;"></i>
                                <?php esc_html_e('Apply All Tags', 'zerisetaxo'); ?>
                            </span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                            <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; border-radius: 14px; cursor: pointer;">+ WordPress</span>
                            <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; border-radius: 14px; cursor: pointer;">+ Machine Learning</span>
                            <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; border-radius: 14px; cursor: pointer;">+ Web Development</span>
                        </div>
                    </div>
                </div>

                <ul style="font-size: 13.5px; color: #334155; line-height: 1.8; margin: 0; padding-left: 20px;">
                    <li><strong><?php esc_html_e('Use Category Button:', 'zerisetaxo'); ?></strong> Automatically checks the recommended category box in your WordPress category checklist.</li>
                    <li><strong><?php esc_html_e('Apply All Tags Button:', 'zerisetaxo'); ?></strong> Adds all candidate tags to your post with one click, saving valuable editing time.</li>
                    <li><strong><?php esc_html_e('Individual Tag Chips:', 'zerisetaxo'); ?></strong> Click any single tag chip to selectively add only that tag.</li>
                    <li><strong><?php esc_html_e('Confidence Rating:', 'zerisetaxo'); ?></strong> Shows how strongly your content matches the selected taxonomy topic.</li>
                </ul>
            </section>

            <!-- SECTION 3: HOW THE ENGINE WORKS (NO DECORATIVE PILLS) -->
            <section id="doc-architecture" class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 26px; scroll-margin-top: 30px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <h3 style="margin: 0 0 10px; font-size: 17px; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-brain" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('3. How the Intelligent Engine Works', 'zerisetaxo'); ?></span>
                </h3>
                <p style="font-size: 13.5px; color: #475569; line-height: 1.6; margin: 0 0 18px;">
                    <?php esc_html_e('ZeriseTaxo combines three intelligent tiers to ensure recommendations are fast, accurate, and completely reliable:', 'zerisetaxo'); ?>
                </p>

                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <!-- Tier 1 -->
                    <div style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; background: #F8FAFC;">
                        <strong style="font-size: 14.5px; color: #0F172A; display: block; margin-bottom: 4px;">
                            <i class="fa-solid fa-bolt" style="color: #10B981; margin-right: 6px;"></i>
                            <?php esc_html_e('Tier 1: Instant Local Classifier (~0.2ms)', 'zerisetaxo'); ?>
                        </strong>
                        <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.55;">
                            <?php esc_html_e('Runs 100% locally on your WordPress server. Analyzes your article words and calculates category affinity in less than a millisecond with zero server strain.', 'zerisetaxo'); ?>
                        </p>
                    </div>

                    <!-- Tier 2 -->
                    <div style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; background: #FFFFFF;">
                        <strong style="font-size: 14.5px; color: #0F172A; display: block; margin-bottom: 4px;">
                            <i class="fa-solid fa-shapes" style="color: #4F46E5; margin-right: 6px;"></i>
                            <?php esc_html_e('Tier 2: Semantic Topic Matcher', 'zerisetaxo'); ?>
                        </strong>
                        <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.55;">
                            <?php esc_html_e('Recognizes the underlying subject matter even when your article does not repeat the exact category name word-for-word, ensuring high-quality context matching.', 'zerisetaxo'); ?>
                        </p>
                    </div>

                    <!-- Tier 3 -->
                    <div style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; background: #FFFFFF;">
                        <strong style="font-size: 14.5px; color: #0F172A; display: block; margin-bottom: 4px;">
                            <i class="fa-solid fa-wand-magic-sparkles" style="color: #2563EB; margin-right: 6px;"></i>
                            <?php esc_html_e('Tier 3: Google Gemini AI (Optional Supercharger)', 'zerisetaxo'); ?>
                        </strong>
                        <p style="margin: 0; font-size: 13px; color: #64748B; line-height: 1.55;">
                            <?php esc_html_e('Steps in only when local confidence is uncertain or when dealing with novel topics. Connects for free to Google AI Studio to propose creative tags.', 'zerisetaxo'); ?>
                        </p>
                    </div>
                </div>
            </section>

            <!-- SECTION 4: SITE-SPECIFIC LEARNING -->
            <section id="doc-training" class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 26px; scroll-margin-top: 30px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <h3 style="margin: 0 0 10px; font-size: 17px; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-graduation-cap" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('4. Site-Specific Vocabulary Learning', 'zerisetaxo'); ?></span>
                </h3>
                <p style="font-size: 13.5px; color: #475569; line-height: 1.6; margin: 0 0 14px;">
                    <?php esc_html_e('ZeriseTaxo can learn your website\'s unique industry niche, jargon, and taxonomy structure by scanning your existing published posts:', 'zerisetaxo'); ?>
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-bottom: 16px;">
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px;">
                        <strong style="display: block; font-size: 13.5px; color: #4F46E5; margin-bottom: 4px;">
                            <i class="fa-solid fa-sliders" style="margin-right: 5px;"></i>
                            <?php esc_html_e('Step 1: Enable Learning', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 12.5px; color: #64748B;">
                            <?php esc_html_e('Navigate to General Settings and turn on "Enable site-specific learning".', 'zerisetaxo'); ?>
                        </span>
                    </div>

                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px;">
                        <strong style="display: block; font-size: 13.5px; color: #4F46E5; margin-bottom: 4px;">
                            <i class="fa-solid fa-arrows-rotate" style="margin-right: 5px;"></i>
                            <?php esc_html_e('Step 2: Rebuild Site Model', 'zerisetaxo'); ?>
                        </strong>
                        <span style="font-size: 12.5px; color: #64748B;">
                            <?php esc_html_e('Click "Rebuild Site Model". ZeriseTaxo will calibrate its weights directly to your blog.', 'zerisetaxo'); ?>
                        </span>
                    </div>
                </div>

                <p style="font-size: 12.5px; color: #64748B; line-height: 1.5; margin: 0;">
                    <i class="fa-solid fa-lightbulb" style="color: #F59E0B; margin-right: 4px;"></i>
                    <?php esc_html_e('Tip: Rebuild your site model whenever you add or restructure major categories or import content in bulk.', 'zerisetaxo'); ?>
                </p>
            </section>

            <!-- SECTION 5: GOOGLE GEMINI AI & PRIVACY -->
            <section id="doc-cloud-ai" class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 26px; scroll-margin-top: 30px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <h3 style="margin: 0 0 10px; font-size: 17px; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('5. Google Gemini AI Setup & Privacy', 'zerisetaxo'); ?></span>
                </h3>
                <p style="font-size: 13.5px; color: #475569; line-height: 1.6; margin: 0 0 14px;">
                    <?php esc_html_e('If you want AI assistance for novel or complex topics, connect your free Google Gemini key in under 2 minutes:', 'zerisetaxo'); ?>
                </p>

                <ol style="font-size: 13.5px; color: #334155; line-height: 1.8; margin: 0 0 16px; padding-left: 22px;">
                    <li><?php printf(/* translators: %s: Settings URL. */ esc_html__('Go to %s and scroll down to the Google Gemini card.', 'zerisetaxo'), '<a href="' . esc_url($zerisetaxo_settings_url) . '" style="color: #4F46E5; font-weight: 600;">' . esc_html__('General Settings', 'zerisetaxo') . '</a>'); ?></li>
                    <li><?php printf(/* translators: %s: Google AI Studio link. */ esc_html__('Generate a free API key at %s (no credit card required).', 'zerisetaxo'), '<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer" style="color: #4F46E5; font-weight: 600;">Google AI Studio ↗</a>'); ?></li>
                    <li><?php esc_html_e('Paste your key into the API Key field and click "Test Connection".', 'zerisetaxo'); ?></li>
                    <li><?php esc_html_e('Check the privacy consent box and click "Save Settings".', 'zerisetaxo'); ?></li>
                </ol>

                <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px; font-size: 13px; color: #475569; line-height: 1.6; display: flex; align-items: flex-start; gap: 10px;">
                    <i class="fa-solid fa-shield-halved" style="color: #2563EB; font-size: 16px; margin-top: 2px; flex-shrink: 0;"></i>
                    <div>
                        <strong><?php esc_html_e('Strict Privacy Promise:', 'zerisetaxo'); ?></strong>
                        <?php esc_html_e('ZeriseTaxo only sends your article title and clean excerpt to Gemini when local confidence is uncertain. Your site visitors\' data, passwords, and user details are never accessed or sent anywhere.', 'zerisetaxo'); ?>
                    </div>
                </div>
            </section>

            <!-- SECTION 6: REST API REFERENCE -->
            <section id="doc-rest-api" class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 26px; scroll-margin-top: 30px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <h3 style="margin: 0 0 10px; font-size: 17px; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-code" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('6. REST API Reference (For Developers)', 'zerisetaxo'); ?></span>
                </h3>
                <p style="font-size: 13.5px; color: #475569; line-height: 1.6; margin: 0 0 14px;">
                    <?php esc_html_e('Developers can query ZeriseTaxo prediction endpoints directly for custom headless WordPress apps, mobile apps, or custom front-ends:', 'zerisetaxo'); ?>
                </p>

                <div style="font-size: 13px; font-weight: 700; color: #0F172A; margin-bottom: 8px; font-family: monospace;">
                    <span style="background: #ECFDF5; color: #065F46; padding: 3px 8px; border-radius: 4px; margin-right: 6px;">POST</span>
                    /wp-json/zerisetaxo/v1/predict
                </div>

                <div class="zerisetaxo-code-block" style="background: #0F172A; color: #F8FAFC; border-radius: 8px; padding: 16px 20px; font-family: monospace; font-size: 12.5px; line-height: 1.6; overflow-x: auto; border: 1px solid #1E293B; margin-bottom: 12px;">
<span style="color: #94A3B8;">// Request Payload (JSON)</span>
{
  <span style="color: #A5B4FC;">"title"</span>: <span style="color: #6EE7B7;">"Building High-Performance PHP Applications"</span>,
  <span style="color: #A5B4FC;">"content"</span>: <span style="color: #6EE7B7;">"Learn how to optimize vector math and memory usage in PHP 8.2..."</span>
}

<span style="color: #94A3B8;">// Response (200 OK)</span>
{
  <span style="color: #A5B4FC;">"success"</span>: <span style="color: #FCD34D;">true</span>,
  <span style="color: #A5B4FC;">"category"</span>: <span style="color: #6EE7B7;">"Technology & Web"</span>,
  <span style="color: #A5B4FC;">"confidence"</span>: <span style="color: #FCD34D;">94</span>,
  <span style="color: #A5B4FC;">"tags"</span>: [<span style="color: #6EE7B7;">"PHP"</span>, <span style="color: #6EE7B7;">"Performance"</span>, <span style="color: #6EE7B7;">"Web Development"</span>]
}
                </div>
            </section>

            <!-- SECTION 7: COMMON QUESTIONS & FAQ -->
            <section id="doc-faq" class="zerisetaxo-saas-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 26px; scroll-margin-top: 30px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);">
                <h3 style="margin: 0 0 16px; font-size: 17px; font-weight: 800; color: #0F172A; border-bottom: 1px solid #F1F5F9; padding-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-question" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('7. Common Questions & FAQ', 'zerisetaxo'); ?></span>
                </h3>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px;">
                        <strong style="display: block; font-size: 13.5px; color: #0F172A; margin-bottom: 4px;">
                            <?php esc_html_e('Q: Why am I not seeing recommendations in the post editor?', 'zerisetaxo'); ?>
                        </strong>
                        <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.55;">
                            <?php esc_html_e('Ensure "Enable Suggestions" is toggled ON under General Settings. If you set Suggestion Timing to "Manual", click the "Suggest Category" button in the editor panel to trigger recommendations.', 'zerisetaxo'); ?>
                        </p>
                    </div>

                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px;">
                        <strong style="display: block; font-size: 13.5px; color: #0F172A; margin-bottom: 4px;">
                            <?php esc_html_e('Q: Can I use ZeriseTaxo completely free without paying monthly subscriptions?', 'zerisetaxo'); ?>
                        </strong>
                        <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.55;">
                            <?php esc_html_e('Yes! ZeriseTaxo is 100% free to use. The local PHP inference engine runs entirely on your own server. Google Gemini also provides a generous free tier via Google AI Studio with no credit card required.', 'zerisetaxo'); ?>
                        </p>
                    </div>

                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px;">
                        <strong style="display: block; font-size: 13.5px; color: #0F172A; margin-bottom: 4px;">
                            <?php esc_html_e('Q: Will ZeriseTaxo slow down my site speed or post saving?', 'zerisetaxo'); ?>
                        </strong>
                        <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.55;">
                            <?php esc_html_e('No. ZeriseTaxo runs in-memory in ~0.2 milliseconds and only during editor interactions. It has zero impact on your front-end page speed, Core Web Vitals, or database query count.', 'zerisetaxo'); ?>
                        </p>
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: center; font-size: 12.5px; color: #94A3B8;">
                    <?php esc_html_e('ZeriseTaxo &mdash; Intelligent Taxonomy & Tagging Assistant for WordPress', 'zerisetaxo'); ?>
                </div>
            </section>

        </main>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('main.zerisetaxo-col-main > section');
    const navLinks = document.querySelectorAll('.zerisetaxo-toc-item');
    if (!sections.length || !navLinks.length) return;

    // Smooth click navigation
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href').replace('#', '');
            const targetSection = document.getElementById(targetId);
            if (targetSection) {
                targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // Window scroll tracking to highlight active TOC item smoothly
    function updateActiveToc() {
        const scrollPos = window.scrollY || window.pageYOffset;
        let activeId = sections[0].getAttribute('id');

        sections.forEach(sec => {
            const top = sec.offsetTop - 90;
            if (scrollPos >= top) {
                activeId = sec.getAttribute('id');
            }
        });

        navLinks.forEach(link => {
            if (link.getAttribute('href') === '#' + activeId) {
                link.style.background = '#EEF2FF';
                link.style.color = '#4338CA';
            } else {
                link.style.background = 'transparent';
                link.style.color = '#334155';
            }
        });
    }

    window.addEventListener('scroll', updateActiveToc, { passive: true });
    updateActiveToc();
});
</script>
