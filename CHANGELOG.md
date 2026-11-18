# Changelog

All notable changes to ZeriseTaxo will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-11-14

### Security
- Enforce `manage_categories` capability requirement for inserting categories and verifying suggestion permissions.
- Purge encrypted API keys, privacy consent flags, and temporary LLM transients unconditionally upon plugin uninstallation.
- Remove default loopback connection fallback (`127.0.0.1:8000`) in `InferenceClient`.

### Fixed
- Limit pending suggestion queue to 20 suggestions per post (returns 429) and cap suggestion names at 191 characters (returns 400).
- Restrict `terms_clauses` category order filter to execute only when `orderby` is empty or `'name'`, leaving technical queries (`term_order`, `slug`, `count`, `id`, `include`, `none`) completely unchanged.
- Unified default category ordering mode across all controllers and settings views to `'custom_order'`.
- Clear `zerisetaxo_cron_rebuild_site_model` scheduled event upon plugin deactivation.
- Corrected feedback table name reference in `uninstall.php` to `{$wpdb->prefix}zerisetaxo_feedback_log` with WordPress multisite support.

### Changed
- Enforce default 100 calls/day site limit and 30 calls/day per-editor limit for Google Gemini fallback, with an admin settings number input.
- Removed legacy `myplugin/v1` REST route alias and unreferenced AJAX endpoints.
- Rewrote `readme.txt` with plain, transparent disclosures and accurate external service documentation.

## [1.1.0] - 2026-10-31

### Added
- **Visual Drag & Drop Category Order**: Dedicated visual drag-and-drop category prioritization with live site-wide query synchronization via `terms_clauses`.
- **Flexible Ranking Modes**: Support for Custom Drag & Drop (`custom_order`), Post Count (`post_count`), and Default WordPress / AI confidence matching (`confidence`).
- **Instant Unlocked Sorting**: Category Order tab opens directly in active Custom Drag & Drop mode with live grab handles ready.

### Changed
- **Unified Navigation Tabs**: Reordered top admin tabs to General Settings -> Advanced Engine Options -> Category Order -> User Guide.
- **Plugin Readme & Docs**: Comprehensive update with Category Order documentation and fast, simple, and easy workflows.

## [1.0.0] - 2026-09-30

### Added
- **PHP-Native Runtime Engine**: Integrated high-performance embedded inference engine (`PhpPredictionEngine`) executing in ~0.2ms using bundled binary float64 packed weights (`metadata_float64.json` + `weights_float64.bin`). Zero Python runtime or external daemon required for standard WordPress operation.
- **Site-Adaptive Learning (Tier 1)**: In-process Naive Bayes classifier (`SiteModelService`) that trains directly on the WordPress site's labeled content. Includes Phase 9 safety gates:
  - Token coverage gate ($\ge 0.30$)
  - Minimum confidence gate ($\ge 0.45$)
  - Top-1 vs Top-2 margin gate ($\ge 0.10$)
  - Canonical WordPress `term_id` validation
- **Optional Google Gemini Assistance**: Privacy-first cloud fallback for low-confidence and novel out-of-distribution content.
  - Multi-tier credential resolution: `wp-config.php` constants (`ML_WP_TAXONOMY_LLM_API_KEY`), server environment variables (`$_SERVER`, `$_ENV`, `getenv`), or encrypted database store.
  - Budget controls: Maximum daily call budget with automatic cutoff.
  - Request sanitization: Strips PII, HTML markup, shortcodes, and truncates post text to 500 characters.
  - Response validation: Strict schema and safety checks to prevent hallucinations.
  - Transient caching: 24-hour SHA-256 hash caching of LLM classifications.
  - Graceful fallback: Any provider failure, network timeout, or quota exhaustion safely preserves local ML state without interrupting the user.
- **Reproducible Verification Suite**:
  - Independent evaluation across Site ML (Mode A), Global ML (Mode B), Local Cascade (Mode C), and Hybrid (Mode D).
  - 5 case-level mathematical invariants enforced without relying on PHP `assert()`.
  - Full 9-step customer journey acceptance suite verified on live WordPress 7.1.2.
- **Packaging and Production Build System**:
  - Automated `build-release.py` script generating `dist/ml-wp-taxonomy-v1.0.0.zip` (35.25 MB).
  - Built-in security audit rejecting private keys, credentials, and forbidden files.

### Changed
- **Admin Settings UX**:
  - Reorganized into General, Advanced, and Developer Diagnostics.
  - In PHP-native mode, System Status card displays local active status instead of misleading FastAPI connection warnings.
  - Unused customer-facing FastAPI connection controls are gated to developer mode.
- **Security Hardening**:
  - Eliminated `.env` file from plugin directory to protect against web server misconfigurations.
  - Strict capability verification (`manage_categories`) on category creation.
  - Canonical `term_id` enforcement on category application.
  - Cryptographic nonces and output escaping across all admin interfaces.

### Fixed
- **Benchmark Integrity**: Resolved Phase 13 reporting anomalies; decoupled MockProvider from test labels and eliminated oracle leakage.
- **SiteModelStore Readiness Gate**: Fixed `SiteModelStore::is_available()` to properly recognize `healthy` and `limited` readiness states alongside `ready`.
- **Taxonomy Synonyms**: Expanded fuzzy topic synonyms in `PhpPredictionEngine` to map common web development, health, and personal finance taxonomy variants.
