=== ZeriseTaxo ===
Contributors: rachanapaudel26
Tags: categories, tags, taxonomy, category-order, suggestions
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Instant AI-powered category & tag suggestions inside Gutenberg, plus visual drag-and-drop category ordering. 100% local, fast, and private.

== Description ==

**Stop guessing categories. Let AI organize your content in real time.**

How much time do you waste scrolling through dozens of categories and racking your brain for relevant tags every time you publish an article? Disorganized taxonomy hurts internal linking, confuses readers, and drags down your SEO rankings.

**ZeriseTaxo is your intelligent WordPress taxonomy copilot.**

It works seamlessly in the background as you write. By analyzing your post title and draft content in real time, ZeriseTaxo automatically recommends the most relevant categories and extracts high-impact tags directly inside the Gutenberg editor sidebar and Classic Editor.

Best of all? **It's 100% PHP-native.** No external subscriptions, no third-party API dependencies, no Python microservices, and sub-millisecond execution (<1ms) that never slows down your server or editorial workflow.

= Why Editors, Publishers & Agencies Choose ZeriseTaxo =

* ⚡ **Real-Time In-Editor Recommendations**: As you type, ZeriseTaxo detects key topical entities and displays instant Category and Tag recommendations in the Gutenberg block editor sidebar and Classic Editor metabox.
* 🎯 **1-Click "Apply All" Tagging**: Stop manually typing the same tags over and over. Review extracted high-relevance tags and apply them all to your post with a single click.
* 🧠 **Adaptive Site Learning**: ZeriseTaxo learns from your existing published articles. It trains directly on your own site vocabulary, adapting to your specific editorial niche, industry jargon, and custom taxonomy structure.
* 📂 **Visual Drag & Drop Category Ordering**: Take full control of how your categories appear across menus, widgets, and archive listings. Reorder categories with a simple drag-and-drop interface or sort them automatically by post volume.
* 🔒 **100% On-Device Privacy**: All standard machine learning inference happens directly on your WordPress server. No draft text, credentials, or visitor data ever leaves your hosting environment.
* 🚀 **Blazing Fast (<1ms Latency)**: Powered by pure, optimized PHP algorithms and in-memory vector centroids. Zero impact on editor responsiveness or front-end page load speeds.
* ☁️ **Optional Cloud AI Fallback**: Need an extra layer of intelligence for rare, novel, or ambiguous topics? Administrators can optionally enable Google Gemini API assistance with strict rate limits and privacy controls.

= Tailored for Every WordPress User =

* **For Bloggers & Content Creators**: Focus 100% on writing great content. Let ZeriseTaxo handle category filing and tag generation in 1 click.
* **For High-Volume News Sites & Magazines**: Maintain strict editorial taxonomy consistency across multiple authors and contributors without micromanagement.
* **For Agencies & Site Administrators**: Provide clients with an intuitive, guided publishing experience that prevents misfiled posts and messy tag bloat.

== External Services ==

This plugin operates 100% locally by default using an embedded in-process PHP machine learning engine. It does not send any data externally for standard operations.

Optionally, site administrators may connect an external cloud service, Google Gemini, for optional AI taxonomy assistance:

* **Service**: Google Gemini API
* **Endpoint**: https://generativelanguage.googleapis.com/
* **When It Runs**: Only when a site administrator enables "Cloud AI Fallback" in Settings, checks the explicit privacy consent box, and saves a valid Gemini API key. When enabled, it runs when any user who can edit posts triggers a suggestion that the local model is unsure about (for example, ambiguous matches or topics novel to the site).
* **Data Sent**: The post title (capped at 200 characters), a sanitized post excerpt (capped at 600 characters, with all HTML tags, Gutenberg block comments, shortcodes, URLs, email addresses, and phone numbers removed), and the site's existing category names and term IDs. No post IDs, author IDs, user credentials, or other WordPress metadata are sent.
* **Usage Limits**: Capped at 100 calls per day for the whole site, with each post editor limited to 30 calls per day.
* **Terms of Service**: https://ai.google.dev/terms
* **Privacy Policy**: https://policies.google.com/privacy

== Installation ==

1. In your WordPress admin, navigate to **Plugins > Add New > Upload Plugin**.
2. Upload the `zerisetaxo.zip` archive and click **Install Now**.
3. Click **Activate Plugin**.
4. Open any post in the Gutenberg editor or Classic Editor—category and tag suggestions appear automatically in the sidebar!
5. (Optional) Go to **ZeriseTaxo > Category Order** to visually arrange your category display order.
6. (Optional) Go to **ZeriseTaxo > Settings** to configure custom confidence thresholds or enable optional Site Learning and Gemini fallback.

== Frequently Asked Questions ==

= Does ZeriseTaxo require an external API key or account? =
No! ZeriseTaxo is completely self-contained and operates 100% on your WordPress server using an embedded PHP model. You do not need any API key, external account, or credit card. An API key is only needed if you explicitly choose to activate the optional Google Gemini cloud fallback.

= Will ZeriseTaxo slow down my post editor or website? =
Not at all. The prediction engine executes in less than 1 millisecond (<1ms) in pure PHP. It performs zero heavy background jobs during editorial typing and has 0.00% impact on front-end visitor page loads.

= How does the Site Learning feature work? =
When activated, ZeriseTaxo scans the titles, content, and assigned categories of your site's published posts to build a localized Naive Bayes / centroid model. It adapts to your specific niche, custom taxonomy terms, and editorial style without sending any data off your server.

= How does the Category Order feature work? =
Under **ZeriseTaxo > Category Order**, you can reorder categories visually using drag-and-drop or sort them by post count. By default, ZeriseTaxo preserves standard WordPress ordering until an administrator explicitly saves a custom order.

= Can authors and editors still select categories and tags manually? =
Yes, absolutely. ZeriseTaxo is strictly assistive. It recommends categories and tags with 1-click convenience, but editors retain 100% manual control over native WordPress category checkboxes and tag input fields.

= What happens when the plugin is uninstalled? =
When uninstalled, stored Gemini API keys, consent records, and temporary cache transients are immediately wiped. Database tables and settings are preserved unless the administrator explicitly checks "Delete data on uninstall" in Advanced Settings before deletion.

== Screenshots ==

1. Category & Tag suggestions in the Gutenberg block editor sidebar.
2. Visual Drag & Drop Category Ordering interface.
3. Settings dashboard with local engine and confidence controls.
4. Taxonomy overview screen showing published posts and category metrics.

== Changelog ==

= 1.1.3 =
* Assets: New official branding banner (1544x500 and 772x250) and plugin icons (256x256 and 128x128) featuring Gutenberg category & tag suggestion interfaces and official logo.
* Reliability: Disambiguate idiomatic polysemy in machine learning fold predictions, preventing false positives from everyday conversational idioms.
* Accuracy: Enhance semantic taxonomy grounding with root-stem morphological clustering for wellness, health, routine, lifestyle, and developer categories.
* Verification: 100% PHP-native with 0 external dependencies, sub-millisecond execution, and zero regressions across all core workflows.

= 1.1.2 =
* Feature: PHP-Native Dynamic Semantic Taxonomy Matcher using in-memory vector centroids and cosine similarity for instant (<1ms) and accurate zero-shot matching on arbitrary new articles and site categories.
* Reliability: Fix category prediction failures on new unsaved drafts (post_id = 0) in Gutenberg and Classic Editor with client-supplied editor text handling.
* Accuracy: Boost title tokens 3x in PHP inference to preserve high topical density and prevent vocabulary dilution on short drafts.
* Knowledge Expansion: Expand semantic synonym ontology across multi-domain technical, developer, marketing, business, tutorial, and lifestyle categories.
* Performance: 100% PHP-native with 0 external dependencies, 0 background commands, 0 Python requirements, and 0 impact on front-end visitor page loads.

= 1.1.1 =
* Security: Enforce `manage_categories` capability on category creation and suggestion post permission checks.
* Reliability: Cap category suggestion queue inputs at 191 characters and limit pending suggestions to 20 per post.
* Cost Control: Enforce a default daily limit of 100 Gemini calls per site and 30 calls per editor, with an admin settings input.
* Data Cleanup: Rewrite uninstall.php to always delete API keys and LLM transients, clean custom tables and meta conditionally, and support WordPress multisite.
* Deactivation: Unschedule the site model rebuild background task on plugin deactivation.
* API Cleanup: Remove obsolete `myplugin/v1` REST route alias and redundant route registration.
* Connection Security: Remove default fallback to 127.0.0.1:8000, gate feedback retry cron to configured endpoints, and unregister unused legacy AJAX actions.
* Taxonomy Ordering: Restrict `terms_clauses` query modification to empty or 'name' orderby requests and unify default order mode to `custom_order`.
* Documentation: Rewrite readme.txt with plain language, detailed external service disclosures, and five standard tags.

= 1.1.0 =
* Category Order: Added drag-and-drop category prioritization with site query synchronization.
* Ranking Options: Added Custom Drag & Drop, Post Count, and Default WordPress / AI modes.
* Navigation: Reordered admin menu tabs for clearer navigation.

= 1.0.0 =
* Initial public release with embedded local PHP prediction engine, site vocabulary training, and optional Gemini fallback.
