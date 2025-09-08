<?php
// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete plugin options/state
delete_option('savejson_options');
delete_option('savejson_migration');

// Clean post meta keys used by the plugin
$meta_keys = [
    '_save_tldr',
    '_save_meta_desc',
    '_save_meta_title',
    '_save_noindex',
    '_save_voice_enabled',
    '_save_faq',
    '_save_social_title',
    '_save_social_desc',
    '_save_social_image',
    '_save_twitter_card',
    '_save_twitter_site',
    '_save_twitter_creator',
    '_save_head_code',
    '_save_foot_code',
    '_save_canonical',
    '_save_robots_follow',
    '_save_robots_advanced',
    '_save_breadcrumb_title',
    '_save_primary_category',
    '_save_migrated_yoast',
];

if (!empty($meta_keys)) {
    $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)", ...$meta_keys));
}

