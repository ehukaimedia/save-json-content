<?php
namespace SaveJSON;

if (!defined('ABSPATH')) { exit; }

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('savejson', new class {
        /**
         * Migrate Yoast data to SAVE JSON post meta and options.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Show what would change without writing.
         *
         * [--batch=<n>]
         * : Process this many posts per page (default 500).
         *
         * ## EXAMPLES
         *
         *   wp savejson migrate_yoast --dry-run
         *   wp savejson migrate_yoast --batch=1000
         */
        public function migrate_yoast($args, $assoc_args) {
            $dry  = isset($assoc_args['dry-run']);
            $batch= isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 500;
            $paged= 1;
            $changed_posts = 0;

            do {
                $q = new \WP_Query([
                    'post_type'      => ['post','page'],
                    'posts_per_page' => $batch,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                ]);
                if (empty($q->posts)) break;
                foreach ($q->posts as $pid) {
                    $changes = $this->map_yoast_to_save($pid, $dry);
                    if (!empty($changes)) {
                        $changed_posts++;
                        \WP_CLI::line("Post {$pid}: ".implode(', ', $changes));
                    }
                }
                $paged++;
            } while ($paged <= max(1, (int)$q->max_num_pages));

            if (!$dry) {
                // Simple global options mapping
                $opts = get_option('savejson_options', []);
                if (!is_array($opts)) $opts = [];
                $yo_titles = get_option('wpseo_titles', []);
                if (is_array($yo_titles) && !empty($yo_titles)) {
                    if (!empty($yo_titles['separator'])) $opts['templates']['separator'] = (string) $yo_titles['separator'];
                    if (!empty($yo_titles['title-home-wpseo'])) $opts['templates']['home']['title'] = (string) $yo_titles['title-home-wpseo'];
                    if (!empty($yo_titles['metadesc-home-wpseo'])) $opts['templates']['home']['meta'] = (string) $yo_titles['metadesc-home-wpseo'];
                }
                $yo_social = get_option('wpseo_social', []);
                if (is_array($yo_social) && !empty($yo_social)) {
                    if (!empty($yo_social['og_default_image'])) $opts['social']['default_image'] = esc_url_raw($yo_social['og_default_image']);
                    if (!empty($yo_social['twitter_site']))     $opts['social']['twitter']['site'] = sanitize_text_field($yo_social['twitter_site']);
                }
                $yo = get_option('wpseo', []);
                if (is_array($yo) && !empty($yo)) {
                    if (!empty($yo['company_name'])) $opts['site']['name'] = sanitize_text_field($yo['company_name']);
                    if (!empty($yo['company_logo'])) $opts['site']['logo'] = esc_url_raw($yo['company_logo']);
                }
                $opts['flags']['migration_yoast_done'] = true;
                update_option('savejson_options', $opts);
            }

            \WP_CLI::success(($dry ? 'Dry run: ' : '') . "Processed {$changed_posts} posts");
        }

        /**
         * Recalculate and print basic coverage stats.
         *
         * ## EXAMPLES
         *   wp savejson recalc
         */
        public function recalc($args, $assoc_args) {
            $total = (int) wp_count_posts('post')->publish + (int) wp_count_posts('page')->publish;
            $with_title = $this->count_meta(Plugin::META_META_TITLE);
            $with_desc  = $this->count_meta(Plugin::META_DESC);
            $noindex    = $this->count_meta(Plugin::META_NOINDEX, '1');
            \WP_CLI::line("Total published posts/pages: {$total}");
            \WP_CLI::line("With SEO title override: {$with_title}");
            \WP_CLI::line("With meta description: {$with_desc}");
            \WP_CLI::line("Marked noindex: {$noindex}");
        }

        /**
         * Export SAVE JSON settings as JSON.
         *
         * ## EXAMPLES
         *   wp savejson export_settings > savejson-settings.json
         */
        public function export_settings($args, $assoc_args) {
            $opts = get_option('savejson_options', []);
            echo wp_json_encode(is_array($opts) ? $opts : [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n";
        }

        private function count_meta($key, $value = null) : int {
            global $wpdb;
            if ($value === null) {
                $sql = $wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=%s AND p.post_status='publish'", $key);
            } else {
                $sql = $wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=%s AND pm.meta_value=%s AND p.post_status='publish'", $key, $value);
            }
            return (int) $wpdb->get_var($sql);
        }

        private function map_yoast_to_save(int $post_id, bool $dry_run = false) : array {
            $changes = [];
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

            $maybe_update = function($cond, $cb) use (&$changes, $dry_run) {
                if ($cond) { if (!$dry_run) { $cb(); } $changes[] = true; }
            };

            if (!empty($yoast['title']))       { $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_META_TITLE, sanitize_text_field($yoast['title'])); }); }
            if (!empty($yoast['desc']))        { $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_DESC, sanitize_textarea_field($yoast['desc'])); }); }
            if (!empty($yoast['canonical']))   { $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_CANONICAL, esc_url_raw($yoast['canonical'])); }); }
            if ($yoast['noindex']!=='')        { $maybe_update(true, function() use($post_id,$yoast){ if ($yoast['noindex']) update_post_meta($post_id, Plugin::META_NOINDEX,'1'); else delete_post_meta($post_id, Plugin::META_NOINDEX); }); }
            if ($yoast['nofollow']!=='')       { $maybe_update(true, function() use($post_id,$yoast){ if ($yoast['nofollow']) update_post_meta($post_id, Plugin::META_ROBOTS_FOLLOW,'0'); else delete_post_meta($post_id, Plugin::META_ROBOTS_FOLLOW); }); }
            if ($yoast['robots_adv']!=='')     { $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_ROBOTS_ADV, sanitize_text_field($yoast['robots_adv'])); }); }

            if (!empty($yoast['og_title']))    { $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_SOC_TITLE, sanitize_text_field($yoast['og_title'])); }); }
            if (!empty($yoast['og_desc']))     { $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_SOC_DESC, sanitize_textarea_field($yoast['og_desc'])); }); }
            $img = $yoast['og_image'] ?: $yoast['tw_image'];
            if (!empty($img))                  { $maybe_update(true, function() use($post_id,$img){ update_post_meta($post_id, Plugin::META_SOC_IMAGE, esc_url_raw($img)); }); }

            if (!empty($yoast['tw_title']) && empty(get_post_meta($post_id, Plugin::META_SOC_TITLE, true))) {
                $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_SOC_TITLE, sanitize_text_field($yoast['tw_title'])); });
            }
            if (!empty($yoast['tw_desc']) && empty(get_post_meta($post_id, Plugin::META_SOC_DESC, true))) {
                $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_SOC_DESC, sanitize_textarea_field($yoast['tw_desc'])); });
            }
            if (!empty($yoast['breadcrumb_t'])) {
                $maybe_update(true, function() use($post_id,$yoast){ update_post_meta($post_id, Plugin::META_BREADCRUMB_T, sanitize_text_field($yoast['breadcrumb_t'])); });
            }

            $primary_term = get_post_meta($post_id, '_yoast_wpseo_primary_category', true);
            if ($primary_term) {
                $maybe_update(true, function() use($post_id,$primary_term){ update_post_meta($post_id, '_save_primary_category', (int) $primary_term); });
            }

            // Normalize changes list to named keys for output
            $named = [];
            foreach (['title','desc','canonical','noindex','nofollow','robots_adv','og_title','og_desc','image','tw_title','tw_desc','breadcrumb_title','primary_category'] as $key) {
                // We appended booleans above; for CLI output, we just collect keys if present in Yoast source
                if (($key === 'image' && !empty($img)) || !empty($yoast[str_replace(['breadcrumb_title','primary_category'], ['breadcrumb_t','primary_category'], $key)])) {
                    $named[] = $key;
                }
            }
            return array_unique($named);
        }
    });
}

