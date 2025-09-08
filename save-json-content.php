<?php
/**
 * Plugin Name: SAVE JSON — Search • Answer • Voice • Engine
 * Plugin URI: https://example.com/save-json
 * Description: A lightweight, hardened SEO toolkit. Site-wide Search Appearance, Site Representation (Organization/Person), Social defaults, Breadcrumbs, Sitemaps UI, per-post meta (TL;DR, SEO Title/Description, Canonical, Robots), optional Voice playback, JSON-LD, and a Yoast migration wizard.
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: save-json-content
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; }

define('SAVEJSON_VERSION', '2.0.0');
define('SAVEJSON_PLUGIN_FILE', __FILE__);
define('SAVEJSON_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAVEJSON_PLUGIN_URL', plugin_dir_url(__FILE__));

// i18n
add_action('init', function(){
    load_plugin_textdomain('save-json-content', false, dirname(plugin_basename(SAVEJSON_PLUGIN_FILE)) . '/languages');
});

// Load core classes/files explicitly to avoid autoload path mismatches
require_once SAVEJSON_PLUGIN_DIR . 'includes/class-savejson.php';
require_once SAVEJSON_PLUGIN_DIR . 'admin/class-savejson-admin.php';
require_once SAVEJSON_PLUGIN_DIR . 'includes/migration.php';
// Features that aren't classes
if (file_exists(SAVEJSON_PLUGIN_DIR . 'includes/breadcrumbs.php')) {
    require_once SAVEJSON_PLUGIN_DIR . 'includes/breadcrumbs.php';
}

// WP-CLI
if (defined('WP_CLI') && WP_CLI && file_exists(SAVEJSON_PLUGIN_DIR . 'includes/cli.php')) {
    require_once SAVEJSON_PLUGIN_DIR . 'includes/cli.php';
}

// Bootstrap the plugin
add_action('plugins_loaded', function(){
    // Core features & hooks
    $GLOBALS['savejson'] = new \SaveJSON\Plugin();
    \SaveJSON\Migration::init();

    // Admin menus & settings pages
    if (is_admin()) {
        $GLOBALS['savejson_admin'] = new \SaveJSON\Admin();
    }
});

// Quick link to settings page in Plugins list
add_filter('plugin_action_links_' . plugin_basename(SAVEJSON_PLUGIN_FILE), function($links){
    $url = admin_url('admin.php?page=savejson');
    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'save-json-content') . '</a>';
    return $links;
});

// Activation: add default options if missing
register_activation_hook(__FILE__, function(){
    $opts = get_option('savejson_options', []);
    if (!is_array($opts)) $opts = [];
    $opts = array_replace_recursive([
        'templates' => [
            'separator' => ' - ',
            'home' => [
                'title' => '%%sitename%% %%sep%% %%tagline%%',
                'meta'  => '%%tagline%%',
            ],
            'post' => [
                'title' => '%%title%% %%sep%% %%sitename%%',
                'meta'  => '%%excerpt%%',
            ],
            'page' => [
                'title' => '%%title%% %%sep%% %%sitename%%',
                'meta'  => '%%excerpt%%',
            ],
            'category' => [
                'title' => '%%term_title%% %%sep%% %%sitename%%',
                'meta'  => '%%term_description%%',
            ],
            'post_tag' => [
                'title' => '%%term_title%% %%sep%% %%sitename%%',
                'meta'  => '%%term_description%%',
            ],
        ],
        'site' => [
            'entity' => 'organization', // organization|person
            'name'   => get_bloginfo('name'),
            'logo'   => '',
            'sameAs' => [],
        ],
        'social' => [
            'default_image' => '',
            'twitter' => [
                'card' => 'summary_large_image',
                'site' => '',
                'creator' => '',
            ],
        ],
        'sitemaps' => [
            'enabled' => true, // rely on WP core, but expose UI toggles
            'include_images' => true,
            'types' => [
                'post' => true,
                'page' => true,
            ],
            'taxonomies' => [
                'category' => true,
                'post_tag' => true,
            ],
            'users' => true,
        ],
        'rss' => [
            'before' => '',
            'after'  => "\nThe post %%title%% appeared first on %%sitename%%.",
        ],
        'flags' => [
            'migration_yoast_done' => false,
        ],
    ], $opts);
    update_option('savejson_options', $opts);
});
