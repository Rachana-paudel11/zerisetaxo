<?php
/**
 * Plugin Name:       ZeriseTaxo
 * Description:       Suggests WordPress post categories and tags using an embedded local model, site vocabulary learning, and optional Google Gemini assistance.
 * Version:           1.1.3
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            rachanapaudel26
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zerisetaxo
 */

defined('ABSPATH') || exit;

define('ZERISETAXO_VERSION', '1.1.3');
define('ZERISETAXO_PATH', plugin_dir_path(__FILE__));
define('ZERISETAXO_URL', plugin_dir_url(__FILE__));
define('ZERISETAXO_BASENAME', plugin_basename(__FILE__));

// PSR-4 style autoloader for ZeriseTaxo namespace
spl_autoload_register(function ($class) {
    $prefix = 'ZeriseTaxo\\';
    $base_dir = ZERISETAXO_PATH . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Register activation hook for custom database table creation
register_activation_hook(__FILE__, function () {
    \ZeriseTaxo\Database\Schema::create_tables();
});

// Register deactivation hook: unschedule site model rebuild and legacy crons
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('zerisetaxo_feedback_retry');
    wp_clear_scheduled_hook('zerisetaxo_cron_rebuild_site_model');
});

// Initialize plugin
function zerisetaxo_init() {
    \ZeriseTaxo\Database\Schema::maybe_create_tables();
    return \ZeriseTaxo\Core\Plugin::get_instance();
}

add_action('plugins_loaded', 'zerisetaxo_init');
