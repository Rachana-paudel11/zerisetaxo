=== ZeriseTaxo ===
Contributors: rachanapaudel26
Tags: categories, tags, taxonomy, category-order, suggestions
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Suggests categories and tags for WordPress posts and provides drag-and-drop category ordering.

== Description ==

ZeriseTaxo assists WordPress editors by suggesting relevant categories and tags while writing posts, and provides a drag-and-drop tool to arrange category display order.

= Features =

* **Post Editor Suggestions**: Displays category and tag recommendations in the Gutenberg sidebar and Classic Editor metabox as you write post titles and content.
* **Local Processing by Default**: Suggestions are generated on your own WordPress server using an embedded PHP model. No external services or accounts are required for standard operation.
* **Site Vocabulary Learning**: Builds a local model from your site's existing published posts to adapt recommendations to your site's specific terminology. The model trains on published articles, not on editorial accept or reject clicks.
* **Category Order**: Reorder your site categories using a visual drag-and-drop interface, or order them by post count. The default setting changes nothing on your site until an administrator saves a custom order.
* **Optional Google Gemini Fallback**: Site administrators can optionally connect a Google Gemini API key to assist when the local model is uncertain about novel or ambiguous topics.
* **Language Scope**: Built-in machine learning models and tag synonym lists are English-focused.

== External Services ==

This plugin can connect to an external cloud service, Google Gemini, for optional AI taxonomy assistance:

* **Service**: Google Gemini API
* **Endpoint**: https://generativelanguage.googleapis.com/
* **When It Runs**: Only when a site administrator enables "Cloud AI Fallback" in Settings, checks the explicit privacy consent box, and saves a valid Gemini API key. When enabled, it runs when any user who can edit posts triggers a suggestion that the local model is unsure about (for example, ambiguous matches or topics novel to the site).
* **Data Sent**: The post title (capped at 200 characters), a sanitized post excerpt (capped at 600 characters, with all HTML tags, Gutenberg block comments, shortcodes, URLs, email addresses, and phone numbers removed), and the site's existing category names and term IDs. No post IDs, author IDs, user credentials, or other WordPress metadata are sent.
* **Usage Limits**: Capped at 100 calls per day for the whole site, with each post editor limited to 30 calls per day.
* **Terms of Service**: https://ai.google.dev/terms
* **Privacy Policy**: https://policies.google.com/privacy

== Installation ==

1. In your WordPress admin, navigate to **Plugins > Add New > Upload Plugin**.
2. Upload the plugin zip archive and click **Install Now**.
3. Click **Activate Plugin**.
4. Open the post editor to view category suggestions, or navigate to **ZeriseTaxo > Category Order** to arrange categories.
5. (Optional) Go to **ZeriseTaxo > Settings** if you wish to configure the optional Google Gemini fallback.

== Frequently Asked Questions ==

= Does ZeriseTaxo require an external API key or account? =
No. ZeriseTaxo operates entirely on your WordPress server by default using an embedded PHP model. An external API key is only required if you choose to enable the optional Google Gemini fallback.

= What languages are supported? =
The built-in global model and tag extraction rules are trained on English text. Non-English content can use the site vocabulary learning feature if sufficient published articles exist in that language.

= How does the site model learn? =
The site-specific model reads the titles, content, and assigned categories of published posts on your WordPress site. It does not train on accept or reject clicks in the editor.

= How does the Category Order feature work? =
Under **ZeriseTaxo > Category Order**, administrators can drag categories into a preferred order or choose post count ordering. By default, the feature does not modify any front-end query or term order until an administrator explicitly saves a custom order.

= What happens when the plugin is uninstalled? =
When uninstalled, stored Gemini API keys, consent records, and temporary LLM cache transients are always deleted immediately. Database tables, post meta, and general plugin options are preserved unless the administrator checked "Delete data on uninstall" in the Advanced Settings before deletion.

== Screenshots ==

1. Editor sidebar suggestions in the WordPress block editor.
2. Category Order drag-and-drop interface.
3. Settings page with local engine and Gemini fallback controls.
4. Overview screen showing published posts and taxonomy counts.

== Changelog ==

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
