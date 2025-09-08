<?php
namespace SaveJSON;

if (!defined('ABSPATH')) { exit; }

class Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Tools actions
        add_action('admin_post_savejson_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_savejson_fileeditor',    [$this, 'handle_file_editor']);
        add_action('admin_post_savejson_rss',           [$this, 'handle_rss_save']);
        add_action('admin_post_savejson_bulk_update',   [$this, 'handle_bulk_update']);
        add_action('admin_post_savejson_export_settings', [$this, 'handle_export_settings']);
        add_action('admin_post_savejson_import_settings', [$this, 'handle_import_settings']);

        // Migration actions
        add_action('admin_post_savejson_migrate_yoast',        [$this, 'handle_migrate_yoast']);
        add_action('admin_post_savejson_migrate_yoast_dryrun', [$this, 'handle_migrate_yoast_dryrun']);
        add_action('admin_post_savejson_migrate_bg_start',     [$this, 'handle_migrate_bg_start']);
        add_action('admin_post_savejson_migrate_bg_stop',      [$this, 'handle_migrate_bg_stop']);

        // Header/Footer GPT migration actions
        add_action('admin_post_savejson_migrate_hfg',        [$this, 'handle_migrate_hfg']);
        add_action('admin_post_savejson_migrate_hfg_dryrun', [$this, 'handle_migrate_hfg_dryrun']);
    }

    public function menu() {
        add_menu_page(
            __('SAVE JSON', 'save-json-content'),
            __('SAVE JSON', 'save-json-content'),
            'manage_options',
            'savejson',
            [$this, 'screen_dashboard'],
            'dashicons-search',
            59
        );

        add_submenu_page('savejson', __('Dashboard','save-json-content'), __('Dashboard','save-json-content'), 'manage_options', 'savejson', [$this, 'screen_dashboard']);
        add_submenu_page('savejson', __('Search Appearance','save-json-content'), __('Search Appearance','save-json-content'), 'manage_options', 'savejson-appearance', [$this, 'screen_appearance']);
        add_submenu_page('savejson', __('Site Representation','save-json-content'), __('Site Representation','save-json-content'), 'manage_options', 'savejson-siterep', [$this, 'screen_siterep']);
        add_submenu_page('savejson', __('Social & Sharing','save-json-content'), __('Social & Sharing','save-json-content'), 'manage_options', 'savejson-social', [$this, 'screen_social']);
        add_submenu_page('savejson', __('Sitemaps','save-json-content'), __('Sitemaps','save-json-content'), 'manage_options', 'savejson-sitemaps', [$this, 'screen_sitemaps']);
        add_submenu_page('savejson', __('Tools','save-json-content'), __('Tools','save-json-content'), 'manage_options', 'savejson-tools', [$this, 'screen_tools']);
        add_submenu_page('savejson', __('Yoast Migration','save-json-content'), __('Yoast Migration','save-json-content'), 'manage_options', 'savejson-migrate-yoast', [$this, 'screen_migrate_yoast']);
        add_submenu_page('savejson', __('Header/Footer Migration','save-json-content'), __('Header/Footer Migration','save-json-content'), 'manage_options', 'savejson-migrate-hfg', [$this, 'screen_migrate_hfg']);
    }

    public function get_opts() : array {
        $o = get_option('savejson_options', []);
        return is_array($o) ? $o : [];
    }

    public function register_settings() {
        register_setting('savejson_options_group', 'savejson_options');
    }

    private function sanitize_options(array $incoming) : array {
        $clean = [];
        // Templates
        if (isset($incoming['templates']) && is_array($incoming['templates'])) {
            $t = $incoming['templates'];
            $ct = function($v){ return is_string($v) ? sanitize_text_field($v) : ''; };
            $clean['templates'] = [
                'separator' => isset($t['separator']) ? sanitize_text_field((string)$t['separator']) : ' - ',
            ];
            foreach (['home','post','page','category','post_tag'] as $ctx) {
                if (isset($t[$ctx]) && is_array($t[$ctx])) {
                    $clean['templates'][$ctx] = [
                        'title' => isset($t[$ctx]['title']) ? $ct($t[$ctx]['title']) : '',
                        'meta'  => isset($t[$ctx]['meta'])  ? $ct($t[$ctx]['meta'])  : '',
                    ];
                }
            }
        }

        // Site representation
        if (isset($incoming['site']) && is_array($incoming['site'])) {
            $s = $incoming['site'];
            $sameAs = [];
            if (!empty($s['sameAs'])) {
                $lines = is_array($s['sameAs']) ? $s['sameAs'] : explode("\n", (string)$s['sameAs']);
                foreach ($lines as $line) {
                    $u = esc_url_raw(trim((string)$line));
                    if ($u !== '') { $sameAs[$u] = true; }
                }
            }
            $clean['site'] = [
                'entity' => (isset($s['entity']) && in_array($s['entity'], ['organization','person'], true)) ? $s['entity'] : 'organization',
                'name'   => isset($s['name']) ? sanitize_text_field((string)$s['name']) : get_bloginfo('name'),
                'logo'   => isset($s['logo']) ? esc_url_raw((string)$s['logo']) : '',
                'sameAs' => array_keys($sameAs),
            ];
        }

        // Social defaults
        if (isset($incoming['social']) && is_array($incoming['social'])) {
            $so = $incoming['social'];
            $tw = isset($so['twitter']) && is_array($so['twitter']) ? $so['twitter'] : [];
            $clean['social'] = [
                'default_image' => isset($so['default_image']) ? esc_url_raw((string)$so['default_image']) : '',
                'twitter' => [
                    'card'    => isset($tw['card']) ? sanitize_text_field((string)$tw['card']) : 'summary_large_image',
                    'site'    => isset($tw['site']) ? sanitize_text_field((string)$tw['site']) : '',
                    'creator' => isset($tw['creator']) ? sanitize_text_field((string)$tw['creator']) : '',
                ],
            ];
        }

        // Sitemaps
        if (isset($incoming['sitemaps']) && is_array($incoming['sitemaps'])) {
            $sm = $incoming['sitemaps'];
            $clean['sitemaps'] = [
                'enabled'        => !empty($sm['enabled']) ? 1 : 0,
                'include_images' => !empty($sm['include_images']) ? 1 : 0,
                'types'      => [],
                'taxonomies' => [],
                'users'      => !empty($sm['users']) ? 1 : 0,
            ];
            foreach (['post','page'] as $t) {
                $clean['sitemaps']['types'][$t] = !empty($sm['types'][$t]) ? 1 : 0;
            }
            foreach (['category','post_tag'] as $tx) {
                $clean['sitemaps']['taxonomies'][$tx] = !empty($sm['taxonomies'][$tx]) ? 1 : 0;
            }
        }

        // RSS
        if (isset($incoming['rss']) && is_array($incoming['rss'])) {
            $clean['rss'] = [
                'before' => isset($incoming['rss']['before']) ? sanitize_textarea_field((string)$incoming['rss']['before']) : '',
                'after'  => isset($incoming['rss']['after'])  ? sanitize_textarea_field((string)$incoming['rss']['after'])  : '',
            ];
        }

        // Flags (internal)
        if (isset($incoming['flags']) && is_array($incoming['flags'])) {
            $f = [];
            foreach ($incoming['flags'] as $k=>$v) { $f[sanitize_key($k)] = (int) !empty($v); }
            $clean['flags'] = $f;
        }

        return $clean;
    }

    private function field($name, $value, $label, $type='text', $attrs='') {
        printf('<p><label><strong>%s</strong><br/><input type="%s" name="savejson_options[%s]" value="%s" %s style="width:100%%;"/></label></p>',
            esc_html($label), esc_attr($type), esc_attr($name), esc_attr($value), $attrs
        );
    }

    private function textarea($name, $value, $label) {
        printf('<p><label><strong>%s</strong><br/><textarea name="savejson_options[%s]" rows="5" style="width:100%%;">%s</textarea></label></p>',
            esc_html($label), esc_attr($name), esc_textarea($value)
        );
    }

    public function screen_dashboard() {
        if (!current_user_can('manage_options')) return;
        $opts = $this->get_opts();
        $counts = [
            'posts_total' => (int) wp_count_posts('post')->publish,
            'pages_total' => (int) wp_count_posts('page')->publish,
        ];
        $noindex = new \WP_Query([ 'post_type'=>['post','page'], 'posts_per_page'=>1, 'meta_key'=>Plugin::META_NOINDEX, 'meta_value'=>'1' ]);
        $has_desc = new \WP_Query([ 'post_type'=>['post','page'], 'posts_per_page'=>1, 'meta_key'=>Plugin::META_DESC, 'meta_compare'=>'EXISTS' ]);
        echo '<div class="wrap"><h1>🧠 SAVE JSON — Dashboard</h1>';
        echo '<p>'.esc_html__('Quick status & shortcuts.', 'save-json-content').'</p>';

        echo '<div class="savejson-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
        echo '<div class="card"><h2>'.esc_html__('Search Appearance','save-json-content').'</h2>';
        echo '<p>'.esc_html__('Templates drive your titles & descriptions.','save-json-content').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=savejson-appearance')).'">'.esc_html__('Open Search Appearance','save-json-content').'</a></p></div>';

        echo '<div class="card"><h2>'.esc_html__('Yoast Migration','save-json-content').'</h2>';
        echo '<p>'.esc_html__('Import Yoast titles, descriptions, canonicals, robots, and social settings.','save-json-content').'</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=savejson-migrate-yoast')).'">'.esc_html__('Open Migration Wizard','save-json-content').'</a></p></div>';

        echo '<div class="card"><h2>'.esc_html__('Header/Footer Migration','save-json-content').'</h2>';
        echo '<p>'.esc_html__('Migrate per‑post code from Header Footer GPT.','save-json-content').'</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=savejson-migrate-hfg')).'">'.esc_html__('Open H/F Migration','save-json-content').'</a></p></div>';

        echo '<div class="card"><h2>'.esc_html__('Sitemaps','save-json-content').'</h2>';
        echo '<p>'.esc_html__('Manage which types are listed in XML sitemaps.','save-json-content').'</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=savejson-sitemaps')).'">'.esc_html__('Sitemaps Settings','save-json-content').'</a></p></div>';

        echo '<div class="card"><h2>'.esc_html__('Tools','save-json-content').'</h2>';
        echo '<p>'.esc_html__('Bulk editor, file editor, and RSS content.','save-json-content').'</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=savejson-tools')).'">'.esc_html__('Open Tools','save-json-content').'</a></p></div>';
        echo '</div>';

        // Conflict detection
        if (defined('WPSEO_VERSION')) {
            echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Yoast SEO is active.','save-json-content').'</strong> '.esc_html__('To avoid duplicate meta/schema, deactivate Yoast after migration.','save-json-content').'</p></div>';
        }

        echo '</div>';
    }

    public function screen_appearance() {
        if (!current_user_can('manage_options')) return;
        $o = $this->get_opts();
        $tpl = $o['templates'] ?? [];
        $sep = $tpl['separator'] ?? ' - ';
        echo '<div class="wrap"><h1>Search Appearance</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_save_settings','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_save_settings" />';
        echo '<input type="hidden" name="redirect_to" value="savejson-appearance" />';
        echo '<h2 class="title">'.esc_html__('Title Separator','save-json-content').'</h2>';
        printf('<p><input type="text" name="savejson_options[templates][separator]" value="%s" maxlength="5" style="width:80px;text-align:center;"/> <span class="description">%s</span></p>', esc_attr($sep), esc_html__('e.g., "-", "|", "•"', 'save-json-content'));

        $sections = [
            'home' => __('Homepage / Blog', 'save-json-content'),
            'post' => __('Posts', 'save-json-content'),
            'page' => __('Pages', 'save-json-content'),
            'category' => __('Categories', 'save-json-content'),
            'post_tag' => __('Tags', 'save-json-content'),
        ];
        foreach ($sections as $key => $label) {
            $t = $tpl[$key]['title'] ?? '';
            $m = $tpl[$key]['meta']  ?? '';
            echo '<hr/><h2>'.esc_html($label).'</h2>';
            printf('<p><label><strong>%s</strong><br/><input type="text" name="savejson_options[templates][%s][title]" value="%s" style="width:100%%;" placeholder="%%title%% %%sep%% %%sitename%%"/></label></p>',
                esc_html__('Title template','save-json-content'), esc_attr($key), esc_attr($t));
            printf('<p><label><strong>%s</strong><br/><textarea name="savejson_options[templates][%s][meta]" rows="3" style="width:100%%;" placeholder="%%excerpt%%">%s</textarea></label></p>',
                esc_html__('Meta description template','save-json-content'), esc_attr($key), esc_textarea($m));
            echo '<p class="description">'.esc_html__('Variables: %%title%%, %%sep%%, %%sitename%%, %%tagline%% (%%sitedesc%%), %%excerpt%%, %%category%%, %%primary_category%%, %%author%%, %%date%%, %%page%%, %%searchphrase%%, %%term_title%%, %%term_description%%, %%cf_{field}%%','save-json-content').'</p>';
        }
        submit_button(__('Save Changes','save-json-content'));
        echo '</form></div>';
    }

    public function screen_siterep() {
        if (!current_user_can('manage_options')) return;
        $o = $this->get_opts();
        $s = $o['site'] ?? [];
        $entity = $s['entity'] ?? 'organization';
        echo '<div class="wrap"><h1>'.esc_html__('Site Representation','save-json-content').'</h1>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_save_settings','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_save_settings" />';
        echo '<input type="hidden" name="redirect_to" value="savejson-siterep" />';
        echo '<p><label><strong>'.esc_html__('Entity type','save-json-content').'</strong><br/>';
        echo '<select name="savejson_options[site][entity]">';
        printf('<option value="organization" %s>%s</option>', selected($entity,'organization',false), esc_html__('Organization','save-json-content'));
        printf('<option value="person" %s>%s</option>', selected($entity,'person',false), esc_html__('Person','save-json-content'));
        echo '</select></label></p>';
        $this->field('site[name]', $s['name'] ?? get_bloginfo('name'), __('Name','save-json-content'));
        $this->field('site[logo]', $s['logo'] ?? '', __('Logo URL','save-json-content'), 'url');
        // Social profiles (sameAs)
        $sameAs = isset($s['sameAs']) && is_array($s['sameAs']) ? $s['sameAs'] : [];
        echo '<p><strong>'.esc_html__('Social Profiles (one per line)','save-json-content').'</strong><br/>';
        echo '<textarea name="savejson_options[site][sameAs]" rows="5" style="width:100%;">'.esc_textarea(implode("\n", $sameAs)).'</textarea></p>';
        submit_button(__('Save Changes','save-json-content'));
        echo '</form></div>';
    }

    public function screen_social() {
        if (!current_user_can('manage_options')) return;
        $o = $this->get_opts();
        $s = $o['social'] ?? [];
        $def_img = $s['default_image'] ?? '';
        $tw = $s['twitter'] ?? [];
        echo '<div class="wrap"><h1>'.esc_html__('Social & Sharing','save-json-content').'</h1>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_save_settings','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_save_settings" />';
        echo '<input type="hidden" name="redirect_to" value="savejson-social" />';
        $this->field('social[default_image]', $def_img, __('Default Sharing Image URL','save-json-content'),'url');
        echo '<h2>'.esc_html__('Twitter','save-json-content').'</h2>';
        echo '<p><label><strong>'.esc_html__('Card type','save-json-content').'</strong><br/>';
        echo '<select name="savejson_options[social][twitter][card]">';
        $card = $tw['card'] ?? 'summary_large_image';
        printf('<option value="summary" %s>summary</option>', selected($card,'summary',false));
        printf('<option value="summary_large_image" %s>summary_large_image</option>', selected($card,'summary_large_image',false));
        echo '</select></label></p>';
        $this->field('social[twitter][site]', $tw['site'] ?? '', __('Site handle (e.g., @site)','save-json-content'));
        $this->field('social[twitter][creator]', $tw['creator'] ?? '', __('Creator handle (e.g., @you)','save-json-content'));
        submit_button(__('Save Changes','save-json-content'));
        echo '</form></div>';
    }

    public function screen_sitemaps() {
        if (!current_user_can('manage_options')) return;
        $o = $this->get_opts();
        $s = $o['sitemaps'] ?? [];
        echo '<div class="wrap"><h1>'.esc_html__('Sitemaps','save-json-content').'</h1>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_save_settings','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_save_settings" />';
        echo '<input type="hidden" name="redirect_to" value="savejson-sitemaps" />';
        $enabled = !empty($s['enabled']);
        echo '<p><label><input type="checkbox" name="savejson_options[sitemaps][enabled]" value="1" '.checked($enabled,true,false).'> '.esc_html__('Enable sitemaps UI (uses WordPress Core sitemaps)','save-json-content').'</label></p>';
        $include_images = !empty($s['include_images']);
        echo '<p><label><input type="checkbox" name="savejson_options[sitemaps][include_images]" value="1" '.checked($include_images,true,false).'> '.esc_html__('Include images where available','save-json-content').'</label></p>';
        echo '<h2>'.esc_html__('Post Types','save-json-content').'</h2>';
        $types = ['post','page'];
        foreach ($types as $t) {
            $val = !empty($s['types'][$t]);
            printf('<p><label><input type="checkbox" name="savejson_options[sitemaps][types][%s]" value="1" %s> %s</label></p>',
                esc_attr($t), checked($val,true,false), esc_html($t));
        }
        echo '<h2>'.esc_html__('Taxonomies','save-json-content').'</h2>';
        $tax = ['category','post_tag'];
        foreach ($tax as $t) {
            $val = !empty($s['taxonomies'][$t]);
            printf('<p><label><input type="checkbox" name="savejson_options[sitemaps][taxonomies][%s]" value="1" %s> %s</label></p>',
                esc_attr($t), checked($val,true,false), esc_html($t));
        }
        echo '<h2>'.esc_html__('Other','save-json-content').'</h2>';
        $users_on = !empty($s['users']);
        echo '<p><label><input type="checkbox" name="savejson_options[sitemaps][users]" value="1" '.checked($users_on,true,false).'> '.esc_html__('Include Users sitemap','save-json-content').'</label></p>';
        submit_button(__('Save Changes','save-json-content'));
        echo '</form>';
        echo '<p>'.esc_html__('Core sitemaps are available at /wp-sitemap.xml','save-json-content').'</p>';
        echo '</div>';
    }

    public function screen_tools() {
        if (!current_user_can('manage_options')) return;
        $o = $this->get_opts();
        echo '<div class="wrap"><h1>'.esc_html__('Tools','save-json-content').'</h1>';
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Changes saved.', 'save-json-content').'</p></div>';
        }
        if (isset($_GET['file_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>'.esc_html__('Failed to save one or more files. Check filesystem permissions.', 'save-json-content').'</p></div>';
        }
        echo '<h2>'.esc_html__('Bulk Editor (SEO Title & Meta Description)','save-json-content').'</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_bulk_update','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_bulk_update" />';
        $paged = max(1, (int)($_GET['paged'] ?? 1));
        $q = new \WP_Query([
            'post_type'      => ['post','page'],
            'posts_per_page' => 50,
            'paged'          => $paged,
        ]);
        echo '<table class="widefat striped"><thead><tr><th>'.esc_html__('ID','save-json-content').'</th><th>'.esc_html__('Title','save-json-content').'</th><th>'.esc_html__('SEO Title','save-json-content').'</th><th>'.esc_html__('Meta Description','save-json-content').'</th></tr></thead><tbody>';
        foreach ($q->posts as $p) {
            $seo_t = get_post_meta($p->ID, Plugin::META_META_TITLE, true);
            $seo_d = get_post_meta($p->ID, Plugin::META_DESC, true);
            printf('<tr><td>%d<input type="hidden" name="ids[]" value="%d"/></td><td><a href="%s" target="_blank">%s</a></td><td><input type="text" name="t[%d]" value="%s" style="width:100%%;"/></td><td><textarea name="d[%d]" rows="2" style="width:100%%;">%s</textarea></td></tr>',
                $p->ID, $p->ID, esc_url(get_edit_post_link($p->ID)), esc_html(get_the_title($p)), $p->ID, esc_attr($seo_t), $p->ID, esc_textarea($seo_d));
        }
        echo '</tbody></table>';
        submit_button(__('Save Bulk Changes','save-json-content'));
        echo '</form>';
        // Pagination for bulk editor
        $links = paginate_links([
            'base'      => add_query_arg('paged','%#%'),
            'format'    => '',
            'current'   => $paged,
            'total'     => max(1, (int)$q->max_num_pages),
            'prev_text' => __('« Prev','save-json-content'),
            'next_text' => __('Next »','save-json-content'),
            'type'      => 'list',
        ]);
        if ($links) {
            echo '<div class="tablenav"><div class="tablenav-pages">'.$links.'</div></div>';
        }

        echo '<hr/><h2>'.esc_html__('File Editor','save-json-content').'</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_fileeditor','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_fileeditor" />';
        $robots_path = ABSPATH . 'robots.txt';
        $robots = file_exists($robots_path) ? file_get_contents($robots_path) : "User-agent: *\nDisallow:";
        echo '<p><strong>robots.txt</strong></p>';
        echo '<textarea name="robots" rows="10" style="width:100%;">'.esc_textarea($robots).'</textarea>';
        echo '<p class="description">'.esc_html__('Writes to the site root if writable.','save-json-content').'</p>';
        echo '<p><strong>.htaccess</strong></p>';
        $hta_path = ABSPATH . '.htaccess';
        $hta = file_exists($hta_path) ? file_get_contents($hta_path) : '';
        echo '<textarea name="htaccess" rows="10" style="width:100%;">'.esc_textarea($hta).'</textarea>';
        submit_button(__('Save Files','save-json-content'));
        echo '</form>';

        echo '<hr/><h2>'.esc_html__('RSS Content','save-json-content').'</h2>';
        $rss = $o['rss'] ?? ['before'=>'','after'=>''];
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_rss','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_rss" />';
        printf('<p><label><strong>%s</strong><br/><textarea name="before" rows="3" style="width:100%%;">%s</textarea></label></p>',
            esc_html__('Before each post','save-json-content'), esc_textarea($rss['before'] ?? ''));
        printf('<p><label><strong>%s</strong><br/><textarea name="after" rows="3" style="width:100%%;">%s</textarea></label></p>',
            esc_html__('After each post','save-json-content'), esc_textarea($rss['after'] ?? ''));
        submit_button(__('Save RSS Content','save-json-content'));
        echo '</form>';

        echo '<hr/><h2>'.esc_html__('Settings Import/Export','save-json-content').'</h2>';
        // Export
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-bottom:12px;">';
        wp_nonce_field('savejson_export_settings','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_export_settings" />';
        submit_button(__('Export Settings (JSON)','save-json-content'), 'secondary');
        echo '</form>';
        // Import
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
        wp_nonce_field('savejson_import_settings','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_import_settings" />';
        echo '<input type="file" name="settings_file" accept="application/json" /> ';
        submit_button(__('Import Settings','save-json-content'), 'primary', '', false);
        echo '</form>';
        echo '</div>';
    }

    public function screen_migrate_yoast() {
        if (!current_user_can('manage_options')) return;
        $has = get_option('wpseo_titles') || get_option('wpseo') || get_option('wpseo_social');
        echo '<div class="wrap"><h1>'.esc_html__('Yoast Migration Wizard','save-json-content').'</h1>';
        if (isset($_GET['updated'])) {
            $done = isset($_GET['done']) ? intval($_GET['done']) : 0;
            $msg  = $done > 0
                ? sprintf(esc_html__('%d posts migrated successfully.', 'save-json-content'), $done)
                : esc_html__('Migration completed. No changes were necessary.', 'save-json-content');
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).' ';
            echo esc_html__('To prevent duplicate tags, consider deactivating Yoast.', 'save-json-content');
            echo ' <a class="button button-secondary" href="'.esc_url(admin_url('plugins.php')).'">'.esc_html__('Open Plugins','save-json-content').'</a>';
            echo '</p></div>';
        }
        if ($has) {
            echo '<p>'.esc_html__('Yoast settings detected. Run a dry run first, then migrate.','save-json-content').'</p>';
        } else {
            echo '<p>'.esc_html__('Yoast settings not detected in options table. You can still migrate per‑post meta keys.','save-json-content').'</p>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:12px; align-items:center;">';
        wp_nonce_field('savejson_migrate_yoast','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_migrate_yoast_dryrun" />';
        submit_button(__('Run Dry Run','save-json-content'),'secondary');
        echo '</form>';

        if (isset($_GET['report'])) {
            echo '<h2>'.esc_html__('Dry Run Report','save-json-content').'</h2>';
            echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ddd;padding:12px;max-height:400px;overflow:auto;">'.esc_html(wp_kses_post(base64_decode((string) $_GET['report']))).'</pre>';
        }

        echo '<hr/>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('savejson_migrate_yoast','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_migrate_yoast" />';
        submit_button(__('Migrate Now','save-json-content'),'primary');
        echo '</form>';

        // Background migration controls
        $state = \SaveJSON\Migration::get_state();
        $in_progress = !empty($state['in_progress']);
        echo '<hr/>';
        echo '<h2>'.esc_html__('Background Migration','save-json-content').'</h2>';
        if ($in_progress) {
            $processed = (int) ($state['processed'] ?? 0);
            $modified  = (int) ($state['modified'] ?? 0);
            $total     = isset($state['total']) ? (int)$state['total'] : 0;
            $batch     = (int) ($state['batch'] ?? 200);
            echo '<p>'.sprintf(esc_html__('In progress… Processed %1$d of ~%2$d; Modified %3$d. Batch size: %4$d.', 'save-json-content'), $processed, $total, $modified, $batch).'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:12px;">';
            wp_nonce_field('savejson_migrate_bg','savejson_nonce');
            echo '<input type="hidden" name="action" value="savejson_migrate_bg_stop" />';
            submit_button(__('Stop Background Migration','save-json-content'),'secondary', '', false);
            echo '</form>';
        } else {
            echo '<p>'.esc_html__('Run migration in the background in small batches via WP‑Cron.', 'save-json-content').'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex;gap:8px;align-items:center;">';
            wp_nonce_field('savejson_migrate_bg','savejson_nonce');
            echo '<input type="hidden" name="action" value="savejson_migrate_bg_start" />';
            echo '<label>'.esc_html__('Batch size:','save-json-content').' <input type="number" name="batch" value="200" min="50" max="2000" step="50" /></label>';
            submit_button(__('Start Background Migration','save-json-content'),'secondary', '', false);
            echo '</form>';
            if (!empty($state)) {
                $processed = (int) ($state['processed'] ?? 0);
                $modified  = (int) ($state['modified'] ?? 0);
                $total     = isset($state['total']) ? (int)$state['total'] : 0;
                echo '<p class="description">'.sprintf(esc_html__('Last run summary: Processed %1$d of ~%2$d; Modified %3$d.', 'save-json-content'), $processed, $total, $modified).'</p>';
            }
        }
        echo '</div>';
    }

    public function screen_migrate_hfg() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>'.esc_html__('Header/Footer GPT Migration','save-json-content').'</h1>';
        if (isset($_GET['updated'])) {
            $head = isset($_GET['moved_head']) ? intval($_GET['moved_head']) : 0;
            $foot = isset($_GET['moved_foot']) ? intval($_GET['moved_foot']) : 0;
            $msg  = sprintf(esc_html__('Migrated head code on %1$d posts and footer code on %2$d posts.', 'save-json-content'), $head, $foot);
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).' ';
            echo esc_html__('To prevent duplicate injections, deactivate the old Header/Footer plugin.','save-json-content');
            echo ' <a class="button button-secondary" href="'.esc_url(admin_url('plugins.php')).'">'.esc_html__('Open Plugins','save-json-content').'</a>';
            echo '</p></div>';
        }

        echo '<p>'.esc_html__('This tool migrates per‑post header and footer scripts from the “Header Footer GPT” plugin to SAVE JSON’s own Head & Footer fields.', 'save-json-content').'</p>';
        echo '<p class="description">'.esc_html__('Tip: After migrating, deactivate the other plugin to avoid duplicate code output.', 'save-json-content').'</p>';

        echo '<h2>'.esc_html__('Dry Run','save-json-content').'</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:12px; align-items:center;">';
        wp_nonce_field('savejson_migrate_hfg','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_migrate_hfg_dryrun" />';
        echo '<label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="overwrite" value="1"/> '.esc_html__('Simulate overwrite existing SAVE JSON code', 'save-json-content').'</label>';
        submit_button(__('Run Dry Run','save-json-content'),'secondary', '', false);
        echo '</form>';

        if (isset($_GET['report'])) {
            echo '<h3>'.esc_html__('Dry Run Report','save-json-content').'</h3>';
            echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ddd;padding:12px;max-height:400px;overflow:auto;">'.esc_html(wp_kses_post(base64_decode((string) $_GET['report']))).'</pre>';
        }

        echo '<hr/>';
        echo '<h2>'.esc_html__('Migrate Now','save-json-content').'</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">';
        wp_nonce_field('savejson_migrate_hfg','savejson_nonce');
        echo '<input type="hidden" name="action" value="savejson_migrate_hfg" />';
        echo '<label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="overwrite" value="1"/> '.esc_html__('Overwrite existing SAVE JSON head/footer code if present', 'save-json-content').'</label>';
        echo '<label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="cleanup" value="1"/> '.esc_html__('Delete original Header/Footer GPT meta after migrating (only when copied)', 'save-json-content').'</label>';
        submit_button(__('Migrate Header/Footer Scripts','save-json-content'),'primary', '', false);
        echo '</form>';
        echo '</div>';
    }

    /* ===========================
     * Handlers
     * =========================== */
    public function handle_save_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_save_settings')) wp_die('Bad nonce');
        $incoming = $_POST['savejson_options'] ?? [];
        if (!is_array($incoming)) $incoming = [];

        // Normalize sameAs lines -> array
        if (isset($incoming['site']['sameAs']) && is_string($incoming['site']['sameAs'])) {
            $lines = array_filter(array_map('trim', explode("\n", $incoming['site']['sameAs'])));
            $incoming['site']['sameAs'] = array_values($lines);
        }

        $clean = $this->sanitize_options($incoming);
        $opts = array_replace_recursive($this->get_opts(), $clean);
        update_option('savejson_options', $opts);

        $page = isset($_POST['redirect_to']) ? sanitize_text_field((string) $_POST['redirect_to']) : '';
        if ($page !== '') {
            $url = admin_url('admin.php?page='.$page.'&updated=1');
        } elseif (wp_get_referer()) {
            $url = add_query_arg('updated', '1', wp_get_referer());
        } else {
            $url = admin_url('admin.php?page=savejson&updated=1');
        }
        wp_safe_redirect($url);
        exit;
    }

    public function handle_file_editor() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_fileeditor')) wp_die('Bad nonce');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        \WP_Filesystem();
        global $wp_filesystem;
        if (!$wp_filesystem) wp_die('Filesystem unavailable');
        $robots_path = ABSPATH . 'robots.txt';
        $hta_path = ABSPATH . '.htaccess';
        $ok1 = $wp_filesystem->put_contents($robots_path, (string) ($_POST['robots'] ?? ''), FS_CHMOD_FILE);
        $ok2 = $wp_filesystem->put_contents($hta_path, (string) ($_POST['htaccess'] ?? ''), FS_CHMOD_FILE);
        $url = admin_url('admin.php?page=savejson-tools');
        if ($ok1 && $ok2) {
            $url = add_query_arg('updated', '1', $url);
        } else {
            $url = add_query_arg('file_error', '1', $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public function handle_rss_save() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_rss')) wp_die('Bad nonce');
        $opts = $this->get_opts();
        $opts['rss'] = [
            'before' => sanitize_textarea_field((string) ($_POST['before'] ?? '')),
            'after'  => sanitize_textarea_field((string)  ($_POST['after'] ?? '')),
        ];
        update_option('savejson_options', $opts);
        wp_safe_redirect(admin_url('admin.php?page=savejson-tools&updated=1'));
        exit;
    }

    public function handle_bulk_update() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_bulk_update')) wp_die('Bad nonce');
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        $t   = $_POST['t'] ?? [];
        $d   = $_POST['d'] ?? [];
        foreach ($ids as $id) {
            $title = isset($t[$id]) ? sanitize_text_field($t[$id]) : '';
            $desc  = isset($d[$id]) ? sanitize_textarea_field($d[$id]) : '';
            if ($title !== '') { update_post_meta($id, Plugin::META_META_TITLE, $title); } else { delete_post_meta($id, Plugin::META_META_TITLE); }
            if ($desc  !== '') { update_post_meta($id, Plugin::META_DESC, $desc); } else { delete_post_meta($id, Plugin::META_DESC); }
        }
        wp_safe_redirect(admin_url('admin.php?page=savejson-tools&updated=1'));
        exit;
    }

    public function handle_export_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_export_settings')) wp_die('Bad nonce');
        $opts = get_option('savejson_options', []);
        $json = wp_json_encode(is_array($opts) ? $opts : [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="savejson-settings.json"');
        echo $json;
        exit;
    }

    public function handle_import_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_import_settings')) wp_die('Bad nonce');
        if (empty($_FILES['settings_file']['tmp_name'])) wp_die('No file uploaded');
        $raw = file_get_contents($_FILES['settings_file']['tmp_name']);
        $data = json_decode($raw, true);
        if (!is_array($data)) wp_die('Invalid JSON');
        // Shallow validation: keep only known top-level keys
        $allowed = ['templates','site','social','sitemaps','rss','flags'];
        $clean = [];
        foreach ($allowed as $k) {
            if (isset($data[$k])) $clean[$k] = $data[$k];
        }
        if (!empty($clean)) {
            $opts = array_replace_recursive($this->get_opts(), $clean);
            update_option('savejson_options', $opts);
        }
        wp_safe_redirect(admin_url('admin.php?page=savejson-tools&updated=1'));
        exit;
    }

    /* ===========================
     * Yoast migration (basic mapping)
     * =========================== */
    private function map_yoast_to_save($post_id) : array {
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

        // Apply mappings to SAVE JSON keys
        if (!empty($yoast['title']))       { update_post_meta($post_id, Plugin::META_META_TITLE, sanitize_text_field($yoast['title'])); $changes[]='title'; }
        if (!empty($yoast['desc']))        { update_post_meta($post_id, Plugin::META_DESC, sanitize_textarea_field($yoast['desc'])); $changes[]='desc'; }
        if (!empty($yoast['canonical']))   { update_post_meta($post_id, Plugin::META_CANONICAL, esc_url_raw($yoast['canonical'])); $changes[]='canonical'; }
        if ($yoast['noindex']!=='')        { if ($yoast['noindex']) update_post_meta($post_id, Plugin::META_NOINDEX,'1'); else delete_post_meta($post_id, Plugin::META_NOINDEX); $changes[]='noindex'; }
        if ($yoast['nofollow']!=='')       { if ($yoast['nofollow']) update_post_meta($post_id, Plugin::META_ROBOTS_FOLLOW,'0'); else delete_post_meta($post_id, Plugin::META_ROBOTS_FOLLOW); $changes[]='nofollow'; }
        if ($yoast['robots_adv']!=='')     { update_post_meta($post_id, Plugin::META_ROBOTS_ADV, sanitize_text_field($yoast['robots_adv'])); $changes[]='robots_adv'; }

        // Social: prefer OG; if empty use twitter
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

        // Primary category (Yoast)
        $primary_term = get_post_meta($post_id, '_yoast_wpseo_primary_category', true);
        if ($primary_term) {
            update_post_meta($post_id, '_save_primary_category', (int) $primary_term);
            $changes[] = 'primary_category';
        }

        return $changes;
    }

    public function handle_migrate_yoast_dryrun() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_migrate_yoast')) wp_die('Bad nonce');
        $q = new \WP_Query([ 'post_type'=>['post','page'], 'posts_per_page'=>200, 'fields'=>'ids' ]);
        $report = [];
        foreach ($q->posts as $pid) {
            $changes = array_filter($this->map_yoast_to_save($pid)); // actually writes; revert for dry run
            // Revert by deleting just-written meta to simulate dry run (simple approach)
            foreach ($changes as $ch) {
                // Skip revert for *_primary_category because it's harmless
                if ($ch === 'title') delete_post_meta($pid, Plugin::META_META_TITLE);
                if ($ch === 'desc') delete_post_meta($pid, Plugin::META_DESC);
                if ($ch === 'canonical') delete_post_meta($pid, Plugin::META_CANONICAL);
                if ($ch === 'noindex') delete_post_meta($pid, Plugin::META_NOINDEX);
                if ($ch === 'nofollow') delete_post_meta($pid, Plugin::META_ROBOTS_FOLLOW);
                if ($ch === 'robots_adv') delete_post_meta($pid, Plugin::META_ROBOTS_ADV);
                if ($ch === 'og_title') delete_post_meta($pid, Plugin::META_SOC_TITLE);
                if ($ch === 'og_desc') delete_post_meta($pid, Plugin::META_SOC_DESC);
                if ($ch === 'image') delete_post_meta($pid, Plugin::META_SOC_IMAGE);
                if ($ch === 'tw_title') delete_post_meta($pid, Plugin::META_SOC_TITLE);
                if ($ch === 'tw_desc') delete_post_meta($pid, Plugin::META_SOC_DESC);
                if ($ch === 'breadcrumb_title') delete_post_meta($pid, Plugin::META_BREADCRUMB_T);
            }
            if (!empty($changes)) {
                $report[] = "Post {$pid}: ".implode(', ', $changes);
            }
        }
        $encoded = base64_encode(implode("\n", $report) ?: 'No changes would be made.');
        wp_safe_redirect(admin_url('admin.php?page=savejson-migrate-yoast&report='.$encoded));
        exit;
    }

    public function handle_migrate_yoast() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_migrate_yoast')) wp_die('Bad nonce');

        $paged = 1;
        $total = 0;
        do {
            $q = new \WP_Query([ 'post_type'=>['post','page'], 'posts_per_page'=>200, 'paged'=>$paged, 'fields'=>'ids' ]);
            foreach ($q->posts as $pid) {
                $changes = $this->map_yoast_to_save($pid);
                if (!empty($changes)) $total += 1;
            }
            $paged++;
        } while ($q->max_num_pages >= $paged);

        // Global options mapping (simple)
        $opts = $this->get_opts();
        $yo_titles = get_option('wpseo_titles', []);
        if (is_array($yo_titles) && !empty($yo_titles)) {
            // Title separator
            if (!empty($yo_titles['separator'])) {
                $opts['templates']['separator'] = (string) $yo_titles['separator'];
            }
            // Home
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

        wp_safe_redirect(admin_url('admin.php?page=savejson-migrate-yoast&updated=1&done='.$total));
        exit;
    }

    public function handle_migrate_bg_start() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_migrate_bg')) wp_die('Bad nonce');
        $batch = isset($_POST['batch']) ? max(50, min(2000, (int)$_POST['batch'])) : 200;
        \SaveJSON\Migration::start($batch);
        wp_safe_redirect(admin_url('admin.php?page=savejson-migrate-yoast&updated=1'));
        exit;
    }

    public function handle_migrate_bg_stop() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_migrate_bg')) wp_die('Bad nonce');
        \SaveJSON\Migration::stop();
        wp_safe_redirect(admin_url('admin.php?page=savejson-migrate-yoast&updated=1'));
        exit;
    }

    /* ===========================
     * Header/Footer GPT migration
     * =========================== */
    public function handle_migrate_hfg_dryrun() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_migrate_hfg')) wp_die('Bad nonce');

        $overwrite = !empty($_POST['overwrite']);
        $paged = 1;
        $batch = 400;
        $report = [];
        $head_candidates = 0; $foot_candidates = 0;
        $head_will = 0; $foot_will = 0;

        do {
            $q = new \WP_Query([
                'post_type'      => ['post','page'],
                'post_status'    => 'publish',
                'posts_per_page' => $batch,
                'paged'          => $paged,
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'OR',
                    [ 'key' => '_hfg_header_scripts', 'compare' => 'EXISTS' ],
                    [ 'key' => '_hfg_footer_scripts', 'compare' => 'EXISTS' ],
                ],
            ]);
            if (empty($q->posts)) break;
            foreach ($q->posts as $pid) {
                $h = (string) get_post_meta($pid, '_hfg_header_scripts', true);
                $f = (string) get_post_meta($pid, '_hfg_footer_scripts', true);
                $th = (string) get_post_meta($pid, Plugin::META_HEAD_CODE, true);
                $tf = (string) get_post_meta($pid, Plugin::META_FOOT_CODE, true);

                $line_parts = [];
                if ($h !== '') { $head_candidates++; $can = ($th === '' || $overwrite); if ($can) { $head_will++; $line_parts[] = 'HEAD: migrate'; } else { $line_parts[] = 'HEAD: skip (exists)'; } }
                if ($f !== '') { $foot_candidates++; $can = ($tf === '' || $overwrite); if ($can) { $foot_will++; $line_parts[] = 'FOOT: migrate'; } else { $line_parts[] = 'FOOT: skip (exists)'; } }
                if (!empty($line_parts) && count($report) < 200) {
                    $title = get_the_title($pid);
                    $report[] = "Post {$pid} — {$title}: ".implode('; ', $line_parts);
                }
            }
            $paged++;
        } while ($q->max_num_pages >= $paged && $paged < 1000);

        array_unshift($report, sprintf(
            'Found candidates — Head: %1$d, Footer: %2$d. Will migrate (with%5$s overwrite) — Head: %3$d, Footer: %4$d.',
            $head_candidates, $foot_candidates, $head_will, $foot_will, $overwrite ? '' : 'out'
        ));
        $encoded = base64_encode(implode("\n", $report));
        wp_safe_redirect(admin_url('admin.php?page=savejson-migrate-hfg&report='.$encoded));
        exit;
    }

    public function handle_migrate_hfg() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_POST['savejson_nonce']) || !wp_verify_nonce($_POST['savejson_nonce'], 'savejson_migrate_hfg')) wp_die('Bad nonce');

        $overwrite = !empty($_POST['overwrite']);
        $cleanup   = !empty($_POST['cleanup']);
        $paged = 1;
        $batch = 400;
        $moved_head = 0; $moved_foot = 0;

        do {
            $q = new \WP_Query([
                'post_type'      => ['post','page'],
                'post_status'    => 'publish',
                'posts_per_page' => $batch,
                'paged'          => $paged,
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'OR',
                    [ 'key' => '_hfg_header_scripts', 'compare' => 'EXISTS' ],
                    [ 'key' => '_hfg_footer_scripts', 'compare' => 'EXISTS' ],
                ],
            ]);
            if (empty($q->posts)) break;
            foreach ($q->posts as $pid) {
                // Source
                $h = (string) get_post_meta($pid, '_hfg_header_scripts', true);
                $f = (string) get_post_meta($pid, '_hfg_footer_scripts', true);
                // Targets
                $th = (string) get_post_meta($pid, Plugin::META_HEAD_CODE, true);
                $tf = (string) get_post_meta($pid, Plugin::META_FOOT_CODE, true);

                if ($h !== '' && ($th === '' || $overwrite)) {
                    update_post_meta($pid, Plugin::META_HEAD_CODE, $h);
                    $moved_head++;
                    if ($cleanup) { delete_post_meta($pid, '_hfg_header_scripts'); }
                }
                if ($f !== '' && ($tf === '' || $overwrite)) {
                    update_post_meta($pid, Plugin::META_FOOT_CODE, $f);
                    $moved_foot++;
                    if ($cleanup) { delete_post_meta($pid, '_hfg_footer_scripts'); }
                }
            }
            $paged++;
        } while ($q->max_num_pages >= $paged && $paged < 1000);

        wp_safe_redirect(admin_url('admin.php?page=savejson-migrate-hfg&updated=1&moved_head='.$moved_head.'&moved_foot='.$moved_foot));
        exit;
    }
}
