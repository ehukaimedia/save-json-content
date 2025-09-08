<?php
namespace SaveJSON;

if (!defined('ABSPATH')) { exit; }

class Migration {
    const STATE_OPTION = 'savejson_migration';
    const PROCESSED_META = '_save_migrated_yoast';

    public static function init() : void {
        add_action('savejson_migrate_batch', [__CLASS__, 'process_batch']);
    }

    public static function get_state() : array {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    public static function set_state(array $state) : void {
        update_option(self::STATE_OPTION, $state);
    }

    public static function start(int $batch = 200) : void {
        $batch = max(1, $batch);
        $total = self::estimate_total();
        $state = [
            'in_progress' => true,
            'started_at'  => time(),
            'batch'       => $batch,
            'total'       => $total,
            'processed'   => 0,
            'modified'    => 0,
        ];
        self::set_state($state);
        self::schedule_next();
    }

    public static function stop() : void {
        $state = self::get_state();
        $state['in_progress'] = false;
        self::set_state($state);
        // Clear any pending events
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('savejson_migrate_batch');
        }
    }

    private static function schedule_next() : void {
        if (function_exists('wp_schedule_single_event')) {
            // Run soon; WP Cron triggers on next request
            wp_schedule_single_event(time() + 5, 'savejson_migrate_batch');
        }
    }

    private static function yoast_keys() : array {
        return [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_canonical',
            '_yoast_wpseo_meta-robots-noindex',
            '_yoast_wpseo_meta-robots-nofollow',
            '_yoast_wpseo_meta-robots-adv',
            '_yoast_wpseo_opengraph-title',
            '_yoast_wpseo_opengraph-description',
            '_yoast_wpseo_opengraph-image',
            '_yoast_wpseo_twitter-title',
            '_yoast_wpseo_twitter-description',
            '_yoast_wpseo_twitter-image',
            '_yoast_wpseo_bctitle',
            '_yoast_wpseo_primary_category',
        ];
    }

    private static function estimate_total() : int {
        global $wpdb;
        $keys = self::yoast_keys();
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)\n             FROM {$wpdb->posts} p\n             JOIN {$wpdb->postmeta} m ON p.ID = m.post_id\n             WHERE p.post_status='publish'\n               AND p.post_type IN ('post','page')\n               AND m.meta_key IN ($placeholders)",
            ...$keys
        );
        $count = (int) $wpdb->get_var($sql);
        return $count > 0 ? $count : 0;
    }

    public static function process_batch() : void {
        $state = self::get_state();
        if (empty($state['in_progress'])) {
            return;
        }
        $batch = isset($state['batch']) ? max(1, (int)$state['batch']) : 200;

        // Build a query for posts not yet processed and with any Yoast key
        $yoast_meta_or = ['relation' => 'OR'];
        foreach (self::yoast_keys() as $k) {
            $yoast_meta_or[] = [ 'key' => $k, 'compare' => 'EXISTS' ];
        }
        $not_processed = [
            'relation' => 'OR',
            [ 'key' => self::PROCESSED_META, 'compare' => 'NOT EXISTS' ],
            [ 'key' => self::PROCESSED_META, 'value' => '1', 'compare' => '!=' ],
        ];

        $q = new \WP_Query([
            'post_type'      => ['post','page'],
            'post_status'    => 'publish',
            'posts_per_page' => $batch,
            'fields'         => 'ids',
            'meta_query'     => [ 'relation' => 'AND', $not_processed, $yoast_meta_or ],
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $processed_this_run = 0;
        $modified_this_run  = 0;

        if (!empty($q->posts)) {
            foreach ($q->posts as $pid) {
                $changes = self::map_yoast_to_save($pid);
                if (!empty($changes)) {
                    $modified_this_run++;
                }
                update_post_meta($pid, self::PROCESSED_META, '1');
                $processed_this_run++;
            }
        }

        $state['processed'] = (int) ($state['processed'] ?? 0) + $processed_this_run;
        $state['modified']  = (int) ($state['modified']  ?? 0) + $modified_this_run;

        // If fewer than batch were found, or none, finish
        if (empty($q->posts) || $processed_this_run < $batch) {
            $state['in_progress'] = false;
            // Mark options flag
            $opts = get_option('savejson_options', []);
            if (is_array($opts)) {
                $opts['flags']['migration_yoast_done'] = true;
                update_option('savejson_options', $opts);
            }
            self::set_state($state);
            return;
        }

        self::set_state($state);
        self::schedule_next();
    }

    private static function map_yoast_to_save(int $post_id) : array {
        $changes = [];
        // Read Yoast meta
        $yoast = [
            'title'        => get_post_meta($post_id, '_yoast_wpseo_title', true),
            'desc'         => get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            'canonical'    => get_post_meta($post_id, '_yoast_wpseo_canonical', true),
            'noindex'      => get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true),
            'nofollow'     => get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true),
            'robots_adv'   => get_post_meta($post_id, '_yoast_wpseo_meta-robots-adv', true),
            'og_title'     => get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true),
            'og_desc'      => get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true),
            'og_image'     => get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true),
            'tw_title'     => get_post_meta($post_id, '_yoast_wpseo_twitter-title', true),
            'tw_desc'      => get_post_meta($post_id, '_yoast_wpseo_twitter-description', true),
            'tw_image'     => get_post_meta($post_id, '_yoast_wpseo_twitter-image', true),
            'breadcrumb_t' => get_post_meta($post_id, '_yoast_wpseo_bctitle', true),
        ];

        if (!empty($yoast['title']))       { update_post_meta($post_id, Plugin::META_META_TITLE, sanitize_text_field($yoast['title'])); $changes[]='title'; }
        if (!empty($yoast['desc']))        { update_post_meta($post_id, Plugin::META_DESC, sanitize_textarea_field($yoast['desc'])); $changes[]='desc'; }
        if (!empty($yoast['canonical']))   { update_post_meta($post_id, Plugin::META_CANONICAL, esc_url_raw($yoast['canonical'])); $changes[]='canonical'; }
        if ($yoast['noindex']!=='')        { if ($yoast['noindex']) update_post_meta($post_id, Plugin::META_NOINDEX,'1'); else delete_post_meta($post_id, Plugin::META_NOINDEX); $changes[]='noindex'; }
        if ($yoast['nofollow']!=='')       { if ($yoast['nofollow']) update_post_meta($post_id, Plugin::META_ROBOTS_FOLLOW,'0'); else delete_post_meta($post_id, Plugin::META_ROBOTS_FOLLOW); $changes[]='nofollow'; }
        if ($yoast['robots_adv']!=='')     { update_post_meta($post_id, Plugin::META_ROBOTS_ADV, sanitize_text_field($yoast['robots_adv'])); $changes[]='robots_adv'; }

        if (!empty($yoast['og_title']))    { update_post_meta($post_id, Plugin::META_SOC_TITLE, sanitize_text_field($yoast['og_title'])); $changes[]='og_title'; }
        if (!empty($yoast['og_desc']))     { update_post_meta($post_id, Plugin::META_SOC_DESC, sanitize_textarea_field($yoast['og_desc'])); $changes[]='og_desc'; }
        $img = $yoast['og_image'] ?: $yoast['tw_image'];
        if (!empty($img))                  { update_post_meta($post_id, Plugin::META_SOC_IMAGE, esc_url_raw($img)); $changes[]='image'; }

        if (!empty($yoast['tw_title']) && empty(get_post_meta($post_id, Plugin::META_SOC_TITLE, true))) {
            update_post_meta($post_id, Plugin::META_SOC_TITLE, sanitize_text_field($yoast['tw_title'])); $changes[]='tw_title';
        }
        if (!empty($yoast['tw_desc']) && empty(get_post_meta($post_id, Plugin::META_SOC_DESC, true))) {
            update_post_meta($post_id, Plugin::META_SOC_DESC, sanitize_textarea_field($yoast['tw_desc'])); $changes[]='tw_desc';
        }
        if (!empty($yoast['breadcrumb_t'])) { update_post_meta($post_id, Plugin::META_BREADCRUMB_T, sanitize_text_field($yoast['breadcrumb_t'])); $changes[]='breadcrumb_title'; }

        $primary_term = get_post_meta($post_id, '_yoast_wpseo_primary_category', true);
        if ($primary_term) {
            update_post_meta($post_id, '_save_primary_category', (int) $primary_term);
            $changes[] = 'primary_category';
        }

        return $changes;
    }
}

