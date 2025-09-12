<?php
namespace SaveJSON;

if (!defined('ABSPATH')) { exit; }

class Plugin {

    // Existing meta + new keys
    const META_TLDR           = '_save_tldr';
    const META_DESC           = '_save_meta_desc';
    const META_META_TITLE     = '_save_meta_title';
    const META_NOINDEX        = '_save_noindex';
    const META_VOICE          = '_save_voice_enabled';
    const META_FAQ            = '_save_faq';
    const META_SOC_TITLE      = '_save_social_title';
    const META_SOC_DESC       = '_save_social_desc';
    const META_SOC_IMAGE      = '_save_social_image';
    const META_TW_CARD        = '_save_twitter_card';
    const META_TW_SITE        = '_save_twitter_site';
    const META_TW_CREATOR     = '_save_twitter_creator';
    const META_HEAD_CODE      = '_save_head_code';
    const META_FOOT_CODE      = '_save_foot_code';
    const META_CANONICAL      = '_save_canonical';
    const META_ROBOTS_FOLLOW  = '_save_robots_follow';   // '1' (default) or '0'
    const META_ROBOTS_ADV     = '_save_robots_advanced'; // csv string e.g. "nosnippet,noarchive"
    const META_BREADCRUMB_T   = '_save_breadcrumb_title';
    const META_ANSWER         = '_save_main_answer';
    const META_HOWTO          = '_save_howto';
    // Social sharing composer
    const META_SHARE_TW_TEXT  = '_save_share_twitter_text';
    const META_SHARE_TW_TAGS  = '_save_share_twitter_tags'; // csv list without '#'
    const META_SHARE_FB_TEXT  = '_save_share_facebook_text';
    const META_SHARE_LI_TEXT  = '_save_share_linkedin_text';

    // Sanitizers for complex REST meta
    public static function sanitize_faq_value($value = [], $meta_key = '', $object_type = '') {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $row) {
                if (!is_array($row)) { continue; }
                $q = isset($row['question']) ? sanitize_text_field((string) $row['question']) : '';
                $a = isset($row['answer']) ? wp_kses_post((string) $row['answer']) : '';
                if ($q !== '' && $a !== '') {
                    $out[] = [ 'question' => $q, 'answer' => $a ];
                }
            }
        }
        return $out;
    }

    public static function sanitize_howto_value($value = [], $meta_key = '', $object_type = '') {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $row) {
                if (!is_array($row)) { continue; }
                $n = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
                $t = isset($row['text']) ? sanitize_textarea_field((string) $row['text']) : '';
                if ($n !== '' || $t !== '') {
                    $out[] = [ 'name' => $n, 'text' => $t ];
                }
            }
        }
        return $out;
    }

    public function __construct() {
        // Admin UI for per-post meta
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);

        // Editor (Gutenberg) sidebar: register meta + enqueue panel assets
        add_action('init', [$this, 'register_post_meta']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_sidebar']);

        // Frontend meta, schema, voice, custom code
        add_filter('pre_get_document_title', [$this, 'filter_pre_get_document_title'], 20);
        add_filter('document_title_parts',   [$this, 'filter_document_title_parts'], 20);
        add_filter('wp_title',               [$this, 'filter_wp_title'], 20, 3); // legacy themes
        add_action('wp_head',  [$this, 'emit_head_meta'], 5);
        add_action('wp_head',  [$this, 'emit_custom_head_code'], 99);
        add_action('wp_footer',[$this, 'emit_footer_code'], 99);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);

        // Sitemaps filters
        add_filter('wp_sitemaps_enabled', [$this, 'sitemaps_enabled']);
        add_filter('wp_sitemaps_post_types', [$this, 'sitemaps_post_types']);
        add_filter('wp_sitemaps_taxonomies', [$this, 'sitemaps_taxonomies']);
        add_filter('wp_sitemaps_add_provider', [$this, 'sitemaps_add_provider'], 10, 2);
        // Exclude explicit noindex from posts provider queries
        add_filter('wp_sitemaps_posts_query_args', [$this, 'sitemaps_exclude_noindex'], 10, 2);

        // RSS content before/after
        add_filter('the_content_feed', [$this, 'rss_inject'], 10, 1);
        add_filter('the_excerpt_rss', [$this, 'rss_inject'], 10, 1);

        // Redirect thin attachment pages if enabled
        add_action('template_redirect', [$this, 'maybe_redirect_attachment']);
    }

    /* ===========================
     * Admin: Metabox
     * =========================== */
    public function register_metabox() {
        $types = apply_filters('savejson_post_types', ['post', 'page']);
        foreach ($types as $type) {
            add_meta_box(
                'savejson_metabox',
                __('SAVE JSON — Summary & SEO', 'save-json-content'),
                [$this, 'render_metabox'],
                $type,
                'normal',
                'high'
            );
            add_meta_box(
                'savejson_social',
                __('SAVE JSON — Social Cards', 'save-json-content'),
                [$this, 'render_social_metabox'],
                $type,
                'side',
                'default'
            );
            add_meta_box(
                'savejson_scripts',
                __('SAVE JSON — Scripts (Head & Footer)', 'save-json-content'),
                [$this, 'render_scripts_metabox'],
                $type,
                'normal',
                'default'
            );
            add_meta_box(
                'savejson_answers',
                __('SAVE JSON — Answers & HowTo', 'save-json-content'),
                [$this, 'render_answers_metabox'],
                $type,
                'normal',
                'default'
            );
            add_meta_box(
                'savejson_sharing',
                __('SAVE JSON — Social Sharing', 'save-json-content'),
                [$this, 'render_sharing_metabox'],
                $type,
                'side',
                'default'
            );
        }
    }

    public function render_metabox(\WP_Post $post) {
        wp_nonce_field('savejson_meta_action', 'savejson_meta_nonce');

        $tldr   = get_post_meta($post->ID, self::META_TLDR, true);
        $desc   = get_post_meta($post->ID, self::META_DESC, true);
        $title  = get_post_meta($post->ID, self::META_META_TITLE, true);
        $noidx  = (bool) get_post_meta($post->ID, self::META_NOINDEX, true);
        $voice  = (bool) get_post_meta($post->ID, self::META_VOICE, true);
        $faq    = get_post_meta($post->ID, self::META_FAQ, true);
        $canon  = get_post_meta($post->ID, self::META_CANONICAL, true);
        $follow = get_post_meta($post->ID, self::META_ROBOTS_FOLLOW, true);
        $adv    = get_post_meta($post->ID, self::META_ROBOTS_ADV, true);
        if (!is_array($faq)) { $faq = []; }

        ?>
        <style>
            .savejson-field { margin: 12px 0; }
            .savejson-field label { font-weight: 600; display:block; margin-bottom:6px; }
            .savejson-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .savejson-faq-item { border:1px solid #ddd; padding:10px; margin-bottom:8px; background:#fafafa; }
            .savejson-row { display:flex; align-items:center; gap:10px; }
            .savejson-small { color:#555; font-size:12px; }
            .savejson-buttons { display:flex; flex-wrap:wrap; gap:10px; margin-top: 10px; }
        </style>

        <div class="savejson-grid">
            <div class="savejson-field">
                <label for="savejson_meta_title"><?php echo esc_html__('SEO Title (optional override)', 'save-json-content'); ?></label>
                <input id="savejson_meta_title" type="text" name="savejson_meta_title" value="<?php echo esc_attr($title); ?>" style="width:100%;" />
                <p class="savejson-small"><?php echo esc_html__('If empty, uses Search Appearance template.', 'save-json-content'); ?></p>
            </div>
            <div class="savejson-field">
                <label for="savejson_meta_desc"><?php echo esc_html__('Meta Description', 'save-json-content'); ?></label>
                <textarea id="savejson_meta_desc" name="savejson_meta_desc" rows="3" style="width:100%;"><?php echo esc_textarea($desc); ?></textarea>
                <p class="savejson-small"><?php echo esc_html__('If empty, falls back to TL;DR → excerpt → site tagline.', 'save-json-content'); ?></p>
            </div>
        </div>

        <div class="savejson-field">
            <label for="savejson_tldr"><?php echo esc_html__('TL;DR (Short Summary)', 'save-json-content'); ?></label>
            <textarea id="savejson_tldr" name="savejson_tldr" rows="3" style="width:100%;"><?php echo esc_textarea($tldr); ?></textarea>
            <p class="savejson-small"><?php echo esc_html__('Used for meta fallback and voice playback.', 'save-json-content'); ?></p>
        </div>

        <div class="savejson-grid">
            <div class="savejson-field">
                <label><?php echo esc_html__('Indexing', 'save-json-content'); ?></label>
                <label><input type="checkbox" name="savejson_noindex" value="1" <?php checked($noidx); ?> /> <?php echo esc_html__('Noindex this content (exclude from sitemaps)', 'save-json-content'); ?></label>
                <div class="savejson-row" style="margin-top:6px;">
                    <label><input type="checkbox" name="savejson_nofollow" value="1" <?php checked($follow === '0'); ?> /> <?php echo esc_html__('Nofollow (otherwise Follow)', 'save-json-content'); ?></label>
                </div>
                <div class="savejson-row" style="margin-top:6px;">
                    <label for="savejson_robots_adv"><?php echo esc_html__('Robots advanced (CSV)', 'save-json-content'); ?></label>
                </div>
                <input type="text" id="savejson_robots_adv" name="savejson_robots_adv" value="<?php echo esc_attr($adv); ?>" placeholder="nosnippet,noarchive,max-snippet:-1" style="width:100%;" />
            </div>
            <div class="savejson-field">
                <label for="savejson_canonical"><?php echo esc_html__('Canonical URL', 'save-json-content'); ?></label>
                <input id="savejson_canonical" type="url" name="savejson_canonical" value="<?php echo esc_attr($canon); ?>" placeholder="https://example.com/..." style="width:100%;"/>
                <div class="savejson-row" style="margin-top:12px;">
                    <label><input type="checkbox" name="savejson_voice" value="1" <?php checked($voice); ?> /> <?php echo esc_html__('Enable “Listen to summary” button (frontend)', 'save-json-content'); ?></label>
                </div>
            </div>
        </div>

        <div class="savejson-field">
            <label><?php echo esc_html__('FAQ (optional)', 'save-json-content'); ?></label>
            <div id="savejson_faq_wrap">
                <?php
                if (!empty($faq) && is_array($faq)) {
                    foreach ($faq as $i => $item) {
                        $q = isset($item['question']) ? $item['question'] : '';
                        $a = isset($item['answer']) ? $item['answer'] : '';
                        ?>
                        <div class="savejson-faq-item">
                            <input type="text" name="savejson_faq[<?php echo esc_attr($i); ?>][question]" placeholder="<?php echo esc_attr__('Question', 'save-json-content'); ?>" value="<?php echo esc_attr($q); ?>" style="width:100%; margin-bottom:6px;" />
                            <textarea name="savejson_faq[<?php echo esc_attr($i); ?>][answer]" placeholder="<?php echo esc_attr__('Answer (HTML allowed)', 'save-json-content'); ?>" rows="2" style="width:100%;"><?php echo esc_textarea($a); ?></textarea>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>
            <button type="button" class="button" id="savejson_add_faq"><?php echo esc_html__('Add FAQ', 'save-json-content'); ?></button>
            <p class="savejson-small"><?php echo esc_html__('Rendered as FAQPage JSON-LD in the head (sanitized).', 'save-json-content'); ?></p>
        </div>

        <script>
        (function(){
            const wrap = document.getElementById('savejson_faq_wrap');
            const addBtn = document.getElementById('savejson_add_faq');
            let idx = wrap.querySelectorAll('.savejson-faq-item').length;
            addBtn && addBtn.addEventListener('click', function(){
                const div = document.createElement('div');
                div.className = 'savejson-faq-item';
                div.innerHTML = ''
                  + '<input type="text" name="savejson_faq['+idx+'][question]" placeholder="<?php echo esc_attr__('Question', 'save-json-content'); ?>" style="width:100%; margin-bottom:6px;" />'
                  + '<textarea name="savejson_faq['+idx+'][answer]" placeholder="<?php echo esc_attr__('Answer (HTML allowed)', 'save-json-content'); ?>" rows="2" style="width:100%;"></textarea>';
                wrap.appendChild(div);
                idx++;
            });
        })();
        </script>
        <?php
    }

    public function render_social_metabox(\WP_Post $post) {
        if (function_exists('wp_enqueue_media')) { wp_enqueue_media(); }
        $soc_title = get_post_meta($post->ID, self::META_SOC_TITLE, true);
        $soc_desc  = get_post_meta($post->ID, self::META_SOC_DESC, true);
        $soc_image = get_post_meta($post->ID, self::META_SOC_IMAGE, true);
        $tw_card   = get_post_meta($post->ID, self::META_TW_CARD, true) ?: 'summary_large_image';
        $tw_site   = get_post_meta($post->ID, self::META_TW_SITE, true);
        $tw_creator= get_post_meta($post->ID, self::META_TW_CREATOR, true);
        ?>
        <p><strong><?php echo esc_html__('Social Title', 'save-json-content'); ?></strong><br/>
        <input type="text" name="savejson_soc_title" value="<?php echo esc_attr($soc_title); ?>" style="width:100%;"/></p>
        <p><strong><?php echo esc_html__('Social Description', 'save-json-content'); ?></strong><br/>
        <textarea name="savejson_soc_desc" rows="3" style="width:100%;"><?php echo esc_textarea($soc_desc); ?></textarea></p>
        <p><strong><?php echo esc_html__('Social Image', 'save-json-content'); ?></strong><br/>
        <input type="url" id="savejson_soc_image" name="savejson_soc_image" value="<?php echo esc_attr($soc_image); ?>" placeholder="https://example.com/image.jpg" style="width:100%; margin-bottom:6px;"/>
        <br/>
        <button type="button" class="button" id="savejson_soc_image_btn"><?php echo esc_html__('Select from Media Library', 'save-json-content'); ?></button>
        <button type="button" class="button" id="savejson_soc_image_clear"><?php echo esc_html__('Clear', 'save-json-content'); ?></button>
        <div id="savejson_soc_image_preview" style="margin-top:8px;">
            <?php if (!empty($soc_image)) : ?>
                <img src="<?php echo esc_url($soc_image); ?>" alt="" style="max-width:100%; height:auto; border:1px solid #ddd;" />
            <?php endif; ?>
        </div>
        </p>
        <hr/>
        <p><strong><?php echo esc_html__('Twitter Card', 'save-json-content'); ?></strong><br/>
        <select name="savejson_tw_card" style="width:100%;">
            <option value="summary" <?php selected($tw_card, 'summary'); ?>>summary</option>
            <option value="summary_large_image" <?php selected($tw_card, 'summary_large_image'); ?>>summary_large_image</option>
        </select></p>
        <p><strong><?php echo esc_html__('Twitter Site', 'save-json-content'); ?></strong><br/>
        <input type="text" name="savejson_tw_site" value="<?php echo esc_attr($tw_site); ?>" placeholder="@site" style="width:100%;"/></p>
        <p><strong><?php echo esc_html__('Twitter Creator', 'save-json-content'); ?></strong><br/>
        <input type="text" name="savejson_tw_creator" value="<?php echo esc_attr($tw_creator); ?>" placeholder="@author" style="width:100%;"/></p>
        <script>
        (function(){
            var input   = document.getElementById('savejson_soc_image');
            var btn     = document.getElementById('savejson_soc_image_btn');
            var clear   = document.getElementById('savejson_soc_image_clear');
            var preview = document.getElementById('savejson_soc_image_preview');
            var frame;
            function updatePreview(url){
                if (!preview) return;
                if (url) {
                    preview.innerHTML = '<img src="' + url.replace(/"/g,'&quot;') + '" style="max-width:100%;height:auto;border:1px solid #ddd;" />';
                } else {
                    preview.innerHTML = '';
                }
            }
            if (btn) {
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    if (typeof wp === 'undefined' || !wp.media) { alert('<?php echo esc_js(__('Media library is not available.', 'save-json-content')); ?>'); return; }
                    if (frame) { frame.open(); return; }
                    frame = wp.media({
                        title: '<?php echo esc_js(__('Select Social Image', 'save-json-content')); ?>',
                        library: { type: 'image' },
                        button: { text: '<?php echo esc_js(__('Use image', 'save-json-content')); ?>' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        var att = frame.state().get('selection').first().toJSON();
                        var url = (att.sizes && att.sizes.medium && att.sizes.medium.url) || (att.sizes && att.sizes.full && att.sizes.full.url) || att.url;
                        if (input) { input.value = url || ''; }
                        updatePreview(url || '');
                    });
                    frame.open();
                });
            }
            if (clear) {
                clear.addEventListener('click', function(e){ e.preventDefault(); if (input) { input.value=''; } updatePreview(''); });
            }
        })();
        </script>
        <?php
    }

    public function render_scripts_metabox(\WP_Post $post) {
        $head = get_post_meta($post->ID, self::META_HEAD_CODE, true);
        $foot = get_post_meta($post->ID, self::META_FOOT_CODE, true);
        $can_scripts = current_user_can('unfiltered_html');
        ?>
        <p class="description"><?php echo esc_html__('Inject custom code into the page head and footer for this post only.', 'save-json-content'); ?></p>
        <p><strong><?php echo esc_html__('Head Code', 'save-json-content'); ?></strong></p>
        <textarea name="savejson_head_code" rows="5" style="width:100%;" <?php disabled(!$can_scripts); ?> placeholder="&lt;script&gt;...&lt;/script&gt; or &lt;style&gt;...&lt;/style&gt;"><?php echo esc_textarea($head); ?></textarea>
        <?php if (!$can_scripts): ?>
            <p class="description"><?php echo esc_html__('You do not have permission to save scripts (unfiltered_html).', 'save-json-content'); ?></p>
        <?php endif; ?>
        <p><strong><?php echo esc_html__('Footer Code', 'save-json-content'); ?></strong></p>
        <textarea name="savejson_foot_code" rows="5" style="width:100%;" <?php disabled(!$can_scripts); ?> placeholder="&lt;script&gt;...&lt;/script&gt; "><?php echo esc_textarea($foot); ?></textarea>
        <?php if (!$can_scripts): ?>
            <p class="description"><?php echo esc_html__('You do not have permission to save scripts (unfiltered_html).', 'save-json-content'); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_answers_metabox(\WP_Post $post) {
        $answer = get_post_meta($post->ID, self::META_ANSWER, true);
        $howto  = get_post_meta($post->ID, self::META_HOWTO, true);
        if (!is_array($howto)) { $howto = []; }
        ?>
        <p><strong><?php echo esc_html__('Main Answer (40–60 words recommended)', 'save-json-content'); ?></strong><br/>
        <textarea name="savejson_main_answer" rows="3" style="width:100%;" placeholder="<?php echo esc_attr__('Short, direct answer to the primary question.', 'save-json-content'); ?>"><?php echo esc_textarea((string)$answer); ?></textarea></p>

        <hr/>
        <p><strong><?php echo esc_html__('HowTo Steps', 'save-json-content'); ?></strong></p>
        <div id="savejson_howto_wrap">
            <?php foreach ($howto as $i => $row): $n = (int)$i; $name = isset($row['name']) ? $row['name'] : ''; $text = isset($row['text']) ? $row['text'] : ''; ?>
            <div class="savejson-howto-step" style="border:1px solid #ddd; padding:10px; margin-bottom:8px; background:#fafafa;">
                <input type="text" name="savejson_howto[<?php echo esc_attr($n); ?>][name]" value="<?php echo esc_attr($name); ?>" placeholder="<?php echo esc_attr__('Step name', 'save-json-content'); ?>" style="width:100%; margin-bottom:6px;" />
                <textarea name="savejson_howto[<?php echo esc_attr($n); ?>][text]" rows="2" style="width:100%;" placeholder="<?php echo esc_attr__('Step instructions', 'save-json-content'); ?>"><?php echo esc_textarea($text); ?></textarea>
            </div>
            <?php endforeach; ?>
        </div>
        <p><button type="button" class="button" id="savejson_add_howto"><?php echo esc_html__('Add Step', 'save-json-content'); ?></button></p>
        <p class="description"><?php echo esc_html__('Emits HowTo JSON-LD when steps are present. Keep steps concise.', 'save-json-content'); ?></p>
        <script>
        (function(){
            var wrap = document.getElementById('savejson_howto_wrap');
            var btn  = document.getElementById('savejson_add_howto');
            var idx  = wrap ? wrap.children.length : 0;
            if (!wrap || !btn) return;
            btn.addEventListener('click', function(){
                var div = document.createElement('div'); div.className = 'savejson-howto-step'; div.style.cssText = 'border:1px solid #ddd;padding:10px;margin-bottom:8px;background:#fafafa;';
                div.innerHTML = '<input type="text" name="savejson_howto['+idx+'][name]" placeholder="<?php echo esc_js(__('Step name', 'save-json-content')); ?>" style="width:100%;margin-bottom:6px;" />'
                              + '<textarea name="savejson_howto['+idx+'][text]" rows="2" style="width:100%;" placeholder="<?php echo esc_js(__('Step instructions', 'save-json-content')); ?>"></textarea>';
                wrap.appendChild(div);
                idx++;
            });
        })();
        </script>
        <?php
    }

    public function render_sharing_metabox(\WP_Post $post) {
        $tw_text = get_post_meta($post->ID, self::META_SHARE_TW_TEXT, true);
        $tw_tags = get_post_meta($post->ID, self::META_SHARE_TW_TAGS, true);
        $fb_text = get_post_meta($post->ID, self::META_SHARE_FB_TEXT, true);
        $li_text = get_post_meta($post->ID, self::META_SHARE_LI_TEXT, true);
        $permalink = get_permalink($post);
        ?>
        <p><strong><?php echo esc_html__('Twitter/X', 'save-json-content'); ?></strong></p>
        <textarea name="savejson_share_twitter_text" rows="3" style="width:100%;" placeholder="<?php echo esc_attr__('Short compelling copy for X (Twitter).', 'save-json-content'); ?>"><?php echo esc_textarea((string)$tw_text); ?></textarea>
        <p><label><?php echo esc_html__('Hashtags (comma separated, no #)', 'save-json-content'); ?></label>
        <input type="text" name="savejson_share_twitter_tags" value="<?php echo esc_attr((string)$tw_tags); ?>" style="width:100%;" placeholder="seo,wordpress,howto" /></p>
        <p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url('https://twitter.com/intent/tweet?text=' . rawurlencode((string)$tw_text) . '&url=' . rawurlencode($permalink) . '&hashtags=' . rawurlencode(str_replace('#','', (string)$tw_tags))); ?>"><?php echo esc_html__('Preview Tweet', 'save-json-content'); ?></a></p>

        <hr/>
        <p><strong><?php echo esc_html__('Facebook', 'save-json-content'); ?></strong></p>
        <textarea name="savejson_share_facebook_text" rows="3" style="width:100%;" placeholder="<?php echo esc_attr__('Post copy for Facebook.', 'save-json-content'); ?>"><?php echo esc_textarea((string)$fb_text); ?></textarea>
        <p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url('https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($permalink) . '&quote=' . rawurlencode((string)$fb_text)); ?>"><?php echo esc_html__('Preview Facebook Share', 'save-json-content'); ?></a></p>

        <hr/>
        <p><strong><?php echo esc_html__('LinkedIn', 'save-json-content'); ?></strong></p>
        <textarea name="savejson_share_linkedin_text" rows="3" style="width:100%;" placeholder="<?php echo esc_attr__('Post copy for LinkedIn.', 'save-json-content'); ?>"><?php echo esc_textarea((string)$li_text); ?></textarea>
        <p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url('https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($permalink)); ?>"><?php echo esc_html__('Open LinkedIn Share', 'save-json-content'); ?></a></p>
        <p class="description"><?php echo esc_html__('LinkedIn ignores prefilled text in URLs; copy the text above when sharing.', 'save-json-content'); ?></p>
        <?php
    }

    /* ===========================
     * Editor (Gutenberg) sidebar
     * =========================== */
    public function register_post_meta() : void {
        $types = apply_filters('savejson_post_types', ['post','page']);
        foreach ($types as $type) {
            register_post_meta($type, self::META_META_TITLE, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_DESC, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_TLDR, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_NOINDEX, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_ROBOTS_FOLLOW, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_ROBOTS_ADV, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_CANONICAL, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_VOICE, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            // Social/meta extras for REST editing
            register_post_meta($type, self::META_SOC_TITLE, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_SOC_DESC, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_SOC_IMAGE, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_TW_CARD, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_TW_SITE, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_TW_CREATOR, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_ANSWER, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);

            // FAQ (array of {question, answer}) for REST
            register_post_meta($type, self::META_FAQ, [
                'single' => true,
                'type'   => 'array',
                'show_in_rest' => [
                    'schema' => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'question' => ['type' => 'string'],
                                'answer'   => ['type' => 'string'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'sanitize_callback' => [self::class, 'sanitize_faq_value'],
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);

            // HowTo (array of {name, text}) for REST
            register_post_meta($type, self::META_HOWTO, [
                'single' => true,
                'type'   => 'array',
                'show_in_rest' => [
                    'schema' => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'sanitize_callback' => [self::class, 'sanitize_howto_value'],
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);

            // Head/Footer code — REST‑editable only for users with unfiltered_html
            register_post_meta($type, self::META_HEAD_CODE, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => function($v){ return (string) $v; },
                'auth_callback' => function() { return current_user_can('unfiltered_html'); },
            ]);
            register_post_meta($type, self::META_FOOT_CODE, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => function($v){ return (string) $v; },
                'auth_callback' => function() { return current_user_can('unfiltered_html'); },
            ]);

            // Sharing composer (REST)
            register_post_meta($type, self::META_SHARE_TW_TEXT, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_SHARE_TW_TAGS, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_SHARE_FB_TEXT, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_SHARE_LI_TEXT, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            // Image planning (REST)
            register_post_meta($type, self::META_IMG_PROMPT_GEMINI, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_ADOBE_QUERY, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
            register_post_meta($type, self::META_ADOBE_DESC, [
                'single' => true,
                'type'   => 'string',
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() { return current_user_can('edit_posts'); },
            ]);
        }
    }

    public function enqueue_editor_sidebar() : void {
        $asset_handle = 'savejson-editor-sidebar';
        wp_register_script(
            $asset_handle,
            SAVEJSON_PLUGIN_URL . 'assets/admin/editor-sidebar.js',
            ['wp-plugins','wp-edit-post','wp-element','wp-components','wp-data','wp-i18n'],
            SAVEJSON_VERSION,
            true
        );
        wp_enqueue_script($asset_handle);
    }

    public function save_metabox($post_id, $post) {
        if (!isset($_POST['savejson_meta_nonce']) || !wp_verify_nonce($_POST['savejson_meta_nonce'], 'savejson_meta_action')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

        $title = isset($_POST['savejson_meta_title']) ? sanitize_text_field($_POST['savejson_meta_title']) : '';
        $tldr  = isset($_POST['savejson_tldr']) ? sanitize_textarea_field($_POST['savejson_tldr']) : '';
        $desc  = isset($_POST['savejson_meta_desc']) ? sanitize_textarea_field($_POST['savejson_meta_desc']) : '';
        $noix  = isset($_POST['savejson_noindex']) ? '1' : '';
        $voice = isset($_POST['savejson_voice']) ? '1' : '';
        $canon = isset($_POST['savejson_canonical']) ? esc_url_raw($_POST['savejson_canonical']) : '';
        $nf    = isset($_POST['savejson_nofollow']) ? '0' : '1';
        $adv   = isset($_POST['savejson_robots_adv']) ? sanitize_text_field($_POST['savejson_robots_adv']) : '';

        if ($title !== '') { update_post_meta($post_id, self::META_META_TITLE, $title); } else { delete_post_meta($post_id, self::META_META_TITLE); }
        if ($tldr  !== '') { update_post_meta($post_id, self::META_TLDR,       $tldr);  } else { delete_post_meta($post_id, self::META_TLDR); }
        if ($desc  !== '') { update_post_meta($post_id, self::META_DESC,       $desc);  } else { delete_post_meta($post_id, self::META_DESC); }
        if ($noix  !== '') { update_post_meta($post_id, self::META_NOINDEX,    '1');    } else { delete_post_meta($post_id, self::META_NOINDEX); }
        if ($voice !== '') { update_post_meta($post_id, self::META_VOICE,      '1');    } else { delete_post_meta($post_id, self::META_VOICE); }
        if ($canon !== '') { update_post_meta($post_id, self::META_CANONICAL,  $canon); } else { delete_post_meta($post_id, self::META_CANONICAL); }

        if ($nf !== '1') { update_post_meta($post_id, self::META_ROBOTS_FOLLOW, '0'); } else { delete_post_meta($post_id, self::META_ROBOTS_FOLLOW); }
        if ($adv !== '') { update_post_meta($post_id, self::META_ROBOTS_ADV, $adv); } else { delete_post_meta($post_id, self::META_ROBOTS_ADV); }

        // FAQ
        $faq = isset($_POST['savejson_faq']) && is_array($_POST['savejson_faq']) ? $_POST['savejson_faq'] : [];
        $clean = [];
        foreach ($faq as $row) {
            $q = isset($row['question']) ? sanitize_text_field($row['question']) : '';
            $a = isset($row['answer']) ? wp_kses_post($row['answer']) : '';
            if ($q !== '' && $a !== '') {
                $clean[] = ['question' => $q, 'answer' => $a];
            }
        }
        if (!empty($clean)) {
            update_post_meta($post_id, self::META_FAQ, $clean);
        } else {
            delete_post_meta($post_id, self::META_FAQ);
        }

        // Main Answer
        $answer = isset($_POST['savejson_main_answer']) ? sanitize_textarea_field($_POST['savejson_main_answer']) : '';
        if ($answer !== '') { update_post_meta($post_id, self::META_ANSWER, $answer); } else { delete_post_meta($post_id, self::META_ANSWER); }

        // HowTo steps
        $howto = isset($_POST['savejson_howto']) && is_array($_POST['savejson_howto']) ? $_POST['savejson_howto'] : [];
        $steps = [];
        foreach ($howto as $row) {
            $n = isset($row['name']) ? sanitize_text_field($row['name']) : '';
            $t = isset($row['text']) ? sanitize_textarea_field($row['text']) : '';
            if ($n !== '' || $t !== '') { $steps[] = ['name' => $n, 'text' => $t]; }
        }
        if (!empty($steps)) { update_post_meta($post_id, self::META_HOWTO, $steps); } else { delete_post_meta($post_id, self::META_HOWTO); }

        // Head/Footer custom code (requires unfiltered_html)
        if (current_user_can('unfiltered_html')) {
            $head_code = isset($_POST['savejson_head_code']) ? (string) wp_unslash($_POST['savejson_head_code']) : '';
            $foot_code = isset($_POST['savejson_foot_code']) ? (string) wp_unslash($_POST['savejson_foot_code']) : '';
            if ($head_code !== '') { update_post_meta($post_id, self::META_HEAD_CODE, $head_code); } else { delete_post_meta($post_id, self::META_HEAD_CODE); }
            if ($foot_code !== '') { update_post_meta($post_id, self::META_FOOT_CODE, $foot_code); } else { delete_post_meta($post_id, self::META_FOOT_CODE); }
        }

        // Social overrides
        $soc_title = isset($_POST['savejson_soc_title']) ? sanitize_text_field($_POST['savejson_soc_title']) : '';
        $soc_desc  = isset($_POST['savejson_soc_desc']) ? sanitize_textarea_field($_POST['savejson_soc_desc']) : '';
        $soc_image = isset($_POST['savejson_soc_image']) ? esc_url_raw($_POST['savejson_soc_image']) : '';
        $tw_card   = isset($_POST['savejson_tw_card']) ? sanitize_text_field($_POST['savejson_tw_card']) : '';
        $tw_site   = isset($_POST['savejson_tw_site']) ? sanitize_text_field($_POST['savejson_tw_site']) : '';
        $tw_creator= isset($_POST['savejson_tw_creator']) ? sanitize_text_field($_POST['savejson_tw_creator']) : '';

        if ($soc_title !== '') { update_post_meta($post_id, self::META_SOC_TITLE, $soc_title); } else { delete_post_meta($post_id, self::META_SOC_TITLE); }
        if ($soc_desc  !== '') { update_post_meta($post_id, self::META_SOC_DESC,  $soc_desc);  } else { delete_post_meta($post_id, self::META_SOC_DESC); }
        if ($soc_image !== '') { update_post_meta($post_id, self::META_SOC_IMAGE, $soc_image);} else { delete_post_meta($post_id, self::META_SOC_IMAGE); }
        if ($tw_card   !== '') { update_post_meta($post_id, self::META_TW_CARD,   $tw_card);   } else { delete_post_meta($post_id, self::META_TW_CARD); }
        if ($tw_site   !== '') { update_post_meta($post_id, self::META_TW_SITE,   $tw_site);   } else { delete_post_meta($post_id, self::META_TW_SITE); }
        if ($tw_creator!== '') { update_post_meta($post_id, self::META_TW_CREATOR,$tw_creator);} else { delete_post_meta($post_id, self::META_TW_CREATOR); }

        // Social sharing composer
        $tw_text = isset($_POST['savejson_share_twitter_text']) ? sanitize_textarea_field($_POST['savejson_share_twitter_text']) : '';
        $tw_tags = isset($_POST['savejson_share_twitter_tags']) ? sanitize_text_field(str_replace('#','', (string) $_POST['savejson_share_twitter_tags'])) : '';
        $fb_text = isset($_POST['savejson_share_facebook_text']) ? sanitize_textarea_field($_POST['savejson_share_facebook_text']) : '';
        $li_text = isset($_POST['savejson_share_linkedin_text']) ? sanitize_textarea_field($_POST['savejson_share_linkedin_text']) : '';
        if ($tw_text !== '') { update_post_meta($post_id, self::META_SHARE_TW_TEXT, $tw_text); } else { delete_post_meta($post_id, self::META_SHARE_TW_TEXT); }
        if ($tw_tags !== '') { update_post_meta($post_id, self::META_SHARE_TW_TAGS, $tw_tags); } else { delete_post_meta($post_id, self::META_SHARE_TW_TAGS); }
        if ($fb_text !== '') { update_post_meta($post_id, self::META_SHARE_FB_TEXT, $fb_text); } else { delete_post_meta($post_id, self::META_SHARE_FB_TEXT); }
        if ($li_text !== '') { update_post_meta($post_id, self::META_SHARE_LI_TEXT, $li_text); } else { delete_post_meta($post_id, self::META_SHARE_LI_TEXT); }
    }

    /* ===========================
     * Frontend: Title, Meta & JSON-LD
     * =========================== */
    private function seo_suite_present() : bool {
        $detected = (
            defined('WPSEO_VERSION') ||
            defined('RANK_MATH_VERSION') ||
            defined('SEOPRESS_VERSION') ||
            defined('AIOSEO_VERSION') ||
            defined('THE_SEO_FRAMEWORK_VERSION')
        );
        return (bool) apply_filters('savejson_seo_present', $detected);
    }

    private function get_options() : array {
        $opts = get_option('savejson_options', []);
        return is_array($opts) ? $opts : [];
    }

    private function expand_vars(string $template, \WP_Post $post=null) : string {
        $sep  = (string) ($this->get_options()['templates']['separator'] ?? ' - ');
        $site = get_bloginfo('name');
        $tagline = get_bloginfo('description');

        // Pagination token (%%page%%)
        $page_token = '';
        $curr = 1;
        $max  = 0;
        if (is_singular()) {
            $curr = max(1, (int) get_query_var('page'));
            global $numpages; // set by WP for multi-page posts
            $max = is_numeric($numpages) ? (int) $numpages : 0;
        } else {
            $curr = max(1, (int) get_query_var('paged'));
            global $wp_query;
            if ($wp_query && isset($wp_query->max_num_pages)) { $max = (int) $wp_query->max_num_pages; }
        }
        if ($curr > 1) {
            $page_token = sprintf(__('Page %1$d of %2$d', 'save-json-content'), $curr, max($curr, $max));
        }

        $replacements = [
            '%%sep%%'          => $sep,
            '%%sitename%%'     => $site,
            '%%tagline%%'      => $tagline,
            '%%sitedesc%%'     => $tagline, // Yoast alias
            '%%page%%'         => $page_token,
            '%%searchphrase%%' => is_search() ? get_search_query() : '',
        ];

        if ($post instanceof \WP_Post) {
            $primary_cat_name = '';
            // Primary category from our meta if available
            $primary_id = (int) get_post_meta($post->ID, '_save_primary_category', true);
            if ($primary_id) {
                $term = get_term($primary_id, 'category');
                if ($term && !is_wp_error($term)) $primary_cat_name = $term->name;
            }
            if (!$primary_cat_name) {
                $cats = get_the_terms($post, 'category');
                if ($cats && !is_wp_error($cats) && !empty($cats)) $primary_cat_name = $cats[0]->name;
            }

            $excerpt = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags($post->post_content), 30);
            $author = get_the_author_meta('display_name', $post->post_author);

            $replacements += [
                '%%title%%'            => wp_strip_all_tags(get_the_title($post)),
                '%%excerpt%%'          => wp_strip_all_tags($excerpt),
                '%%category%%'         => $primary_cat_name,
                '%%primary_category%%' => $primary_cat_name,
                '%%author%%'           => $author,
                '%%date%%'             => get_the_date('', $post),
            ];

            // Custom field tokens %%cf_{field}%%
            if (preg_match_all('/%%cf_([a-zA-Z0-9_\-]+)%%/', $template, $m)) {
                foreach ($m[1] as $i => $key) {
                    $val = get_post_meta($post->ID, $key, true);
                    $template = str_replace($m[0][$i], is_scalar($val) ? (string)$val : '', $template);
                }
            }
        }

        // Taxonomy/archive variables
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term && isset($term->name)) {
                $replacements['%%term_title%%'] = $term->name;
                $desc = term_description($term);
                $replacements['%%term_description%%'] = $desc ? wp_strip_all_tags($desc) : '';
            }
        }

        return strtr($template, $replacements);
    }

    private function get_template_for_context(\WP_Post $post=null, string $what='title') : string {
        $opts = $this->get_options();
        $tpls = $opts['templates'] ?? [];
        if (is_home() || is_front_page()) {
            return (string) ($tpls['home'][$what] ?? '');
        }
        if ($post instanceof \WP_Post) {
            $type = get_post_type($post);
            return (string) ($tpls[$type][$what] ?? '');
        }
        if (is_category()) { return (string) ($tpls['category'][$what] ?? ''); }
        if (is_tag())      { return (string) ($tpls['post_tag'][$what] ?? ''); }
        return '';
    }

    public function filter_document_title_parts(array $parts) : array {
        if ($this->seo_suite_present()) { return $parts; }
        if (is_admin()) { return $parts; }

        $post = is_singular() ? get_queried_object() : null;
        $override = '';
        if ($post instanceof \WP_Post) {
            $override = (string) get_post_meta($post->ID, self::META_META_TITLE, true);
        }
        $template = $override !== '' ? $override : $this->get_template_for_context($post, 'title');
        if ($template === '') { return $parts; }

        $title = $this->expand_vars($template, $post);
        // Replace full title
        $parts['title'] = $title;
        // Remove site if our template already contains it to avoid duplication
        if (strpos($title, get_bloginfo('name')) !== false) {
            unset($parts['site']);
            unset($parts['tagline']);
        }
        return $parts;
    }

    public function filter_pre_get_document_title($title) {
        if ($this->seo_suite_present()) { return $title; }
        if (is_admin()) { return $title; }

        $post = is_singular() ? get_queried_object() : null;
        $override = '';
        if ($post instanceof \WP_Post) {
            $override = (string) get_post_meta($post->ID, self::META_META_TITLE, true);
        }
        $template = $override !== '' ? $override : $this->get_template_for_context($post, 'title');
        if ($template === '') { return $title; }

        $new_title = $this->expand_vars($template, $post);
        return $new_title !== '' ? $new_title : $title;
    }

    public function filter_wp_title($title, $sep, $seplocation) {
        // Fallback for themes not using title-tag support
        return $this->filter_pre_get_document_title($title);
    }

    private function get_meta_description(int $post_id) : string {
        $desc = (string) get_post_meta($post_id, self::META_DESC, true);
        if ($desc === '') {
            $tldr = (string) get_post_meta($post_id, self::META_TLDR, true);
            if ($tldr !== '') { $desc = $tldr; }
        }
        if ($desc === '') {
            if (has_excerpt($post_id)) {
                $desc = get_the_excerpt($post_id);
            }
        }
        if ($desc === '') {
            $desc = (string) get_bloginfo('description', 'display');
        }
        $desc = wp_strip_all_tags($desc);
        $desc = preg_replace('/\s+/', ' ', $desc);
        return wp_trim_words($desc, 50, '');
    }

    private function robots_content(int $post_id) : array {
        $directives = [];
        $noindex = (bool) get_post_meta($post_id, self::META_NOINDEX, true);
        $follow  = get_post_meta($post_id, self::META_ROBOTS_FOLLOW, true);
        $adv     = (string) get_post_meta($post_id, self::META_ROBOTS_ADV, true);

        $directives[] = $noindex ? 'noindex' : 'index';
        $directives[] = ($follow === '0') ? 'nofollow' : 'follow';
        if ($adv !== '') {
            foreach (array_map('trim', explode(',', $adv)) as $d) {
                if ($d !== '') $directives[] = $d;
            }
        }
        return array_unique($directives);
    }

    public function emit_head_meta() {
        if ($this->seo_suite_present()) { return; }
        if (is_feed()) { return; }

        $post_id = is_singular() ? (int) get_queried_object_id() : 0;
        $site  = get_bloginfo('name');
        $url   = $post_id ? get_permalink($post_id) : home_url(add_query_arg([]));
        $type  = $post_id ? (is_singular('post') ? 'article' : 'website') : 'website';
        $title = wp_get_document_title();
        $desc  = $post_id ? $this->get_meta_description($post_id) : $this->expand_vars($this->get_template_for_context(null, 'meta'));

        // Social overrides
        $soc_title = $post_id ? (string) get_post_meta($post_id, self::META_SOC_TITLE, true) : '';
        $soc_desc  = $post_id ? (string) get_post_meta($post_id, self::META_SOC_DESC, true) : '';
        $soc_image = $post_id ? (string) get_post_meta($post_id, self::META_SOC_IMAGE, true) : '';

        $opts = $this->get_options();
        if ($soc_title === '') $soc_title = $title;
        if ($soc_desc  === '') $soc_desc  = $desc;

        $tw_card  = $post_id ? (string) get_post_meta($post_id, self::META_TW_CARD, true) : '';
        if ($tw_card === '') $tw_card = (string) ($opts['social']['twitter']['card'] ?? 'summary_large_image');
        $tw_site   = $post_id ? (string) get_post_meta($post_id, self::META_TW_SITE, true) : '';
        if ($tw_site === '') $tw_site = (string) ($opts['social']['twitter']['site'] ?? '');
        $tw_creator= $post_id ? (string) get_post_meta($post_id, self::META_TW_CREATOR, true) : '';
        if ($tw_creator === '') $tw_creator = (string) ($opts['social']['twitter']['creator'] ?? '');

        // Canonical with pagination awareness
        $canonical = $post_id ? (string) get_post_meta($post_id, self::META_CANONICAL, true) : '';
        $paged = 1;
        if (is_singular()) {
            $paged = max(1, (int) get_query_var('page'));
        } else {
            $paged = max(1, (int) get_query_var('paged'));
        }
        if ($canonical === '') {
            $canonical = $paged > 1 ? get_pagenum_link($paged) : $url;
        }

        // Featured image (and possible dimensions / alt)
        $post_image = '';
        $img_w = 0; $img_h = 0; $img_alt = '';
        if ($post_id) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $post_image = (string) wp_get_attachment_image_url($thumb_id, 'full');
                $src = wp_get_attachment_image_src($thumb_id, 'full');
                if (is_array($src)) { $img_w = (int)$src[1]; $img_h = (int)$src[2]; }
                $meta_alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
                if ($meta_alt) { $img_alt = (string) $meta_alt; }
            }
        }

        // Filterable meta context
        $ctx = apply_filters('savejson_meta_context', [
            'site' => $site,
            'url'  => $url,
            'type' => $type,
            'title'=> $title,
            'desc' => $desc,
            'soc_title' => $soc_title,
            'soc_desc'  => $soc_desc,
            'soc_image' => $soc_image,
            'tw_card'   => $tw_card,
            'tw_site'   => $tw_site,
            'tw_creator'=> $tw_creator,
            'canonical' => $canonical,
            'post_image'=> $post_image,
        ], $post_id);
        $site       = (string) ($ctx['site'] ?? $site);
        $url        = (string) ($ctx['url'] ?? $url);
        $type       = (string) ($ctx['type'] ?? $type);
        $title      = (string) ($ctx['title'] ?? $title);
        $desc       = (string) ($ctx['desc'] ?? $desc);
        $soc_title  = (string) ($ctx['soc_title'] ?? $soc_title);
        $soc_desc   = (string) ($ctx['soc_desc'] ?? $soc_desc);
        $soc_image  = (string) ($ctx['soc_image'] ?? $soc_image);
        $tw_card    = (string) ($ctx['tw_card'] ?? $tw_card);
        $tw_site    = (string) ($ctx['tw_site'] ?? $tw_site);
        $tw_creator = (string) ($ctx['tw_creator'] ?? $tw_creator);
        $canonical  = (string) ($ctx['canonical'] ?? $canonical);
        $post_image = (string) ($ctx['post_image'] ?? $post_image);

        // Prefer featured image for social if none explicitly set; then fall back to global default
        if ($soc_image === '' && $post_image !== '') {
            $soc_image = $post_image;
        }
        if ($soc_image === '') {
            $soc_image = (string) ($opts['social']['default_image'] ?? '');
        }

        // Robots for non-singular contexts per settings
        $opts = $opts ?? $this->get_options();
        $archives = isset($opts['archives']) && is_array($opts['archives']) ? $opts['archives'] : [];

        // Basic & OG
        echo '<link rel="canonical" href="' . esc_url($canonical) . "\" />\n";
        $robots_line = '';
        if ($post_id) {
            $robots_line = implode(',', $this->robots_content($post_id));
        } else {
            // Archives and special pages
            $index = true;
            if (is_search() && empty($archives['index_search'])) { $index = false; }
            if (is_404()     && empty($archives['index_404']))    { $index = false; }
            if (is_author()  && empty($archives['index_author'])) { $index = false; }
            if ((is_date() || is_year() || is_month() || is_day()) && empty($archives['index_date'])) { $index = false; }
            if (is_attachment() && empty($archives['index_attachment'])) { $index = false; }
            $robots_line = $index ? 'index,follow' : 'noindex,follow';
        }
        if ($robots_line) {
            $robots_line = apply_filters('savejson_robots', $robots_line);
            echo '<meta name="robots" content="' . esc_attr($robots_line) . "\" />\n";
            echo '<meta name="googlebot" content="' . esc_attr($robots_line) . "\" />\n";
        }
        if ($desc) {
            echo '<meta name="description" content="' . esc_attr($desc) . "\" />\n";
        }
        // Locale
        $locale = str_replace('-', '_', get_locale());
        echo '<meta property="og:locale" content="' . esc_attr($locale) . "\" />\n";
        // hreflang / alternates
        $alts = $this->get_alternates($post_id);
        $og_alt_locales = [];
        foreach ($alts as $alt) {
            if (!empty($alt['hreflang']) && !empty($alt['url'])) {
                echo '<link rel="alternate" href="' . esc_url($alt['url']) . '" hreflang="' . esc_attr($alt['hreflang']) . "\" />\n";
                if (!empty($alt['locale'])) { $og_alt_locales[] = str_replace('-', '_', $alt['locale']); }
            }
        }
        foreach (array_unique($og_alt_locales) as $altLocale) {
            echo '<meta property="og:locale:alternate" content="' . esc_attr($altLocale) . "\" />\n";
        }

        echo '<meta property="og:title" content="' . esc_attr($soc_title ?: $title) . "\" />\n";
        echo '<meta property="og:description" content="' . esc_attr($soc_desc ?: $desc) . "\" />\n";
        echo '<meta property="og:url" content="' . esc_url($url) . "\" />\n";
        echo '<meta property="og:site_name" content="' . esc_attr($site) . "\" />\n";
        echo '<meta property="og:type" content="' . esc_attr($type) . "\" />\n";
        // Social image details
        if ($soc_image !== '') {
            echo '<meta property="og:image" content="' . esc_url($soc_image) . "\" />\n";
            if ($img_w && $img_h) {
                echo '<meta property="og:image:width" content="' . esc_attr((string)$img_w) . "\" />\n";
                echo '<meta property="og:image:height" content="' . esc_attr((string)$img_h) . "\" />\n";
            }
            if ($img_alt !== '') {
                echo '<meta name="twitter:image:alt" content="' . esc_attr($img_alt) . "\" />\n";
            }
        }
        echo '<meta name="twitter:card" content="' . esc_attr($tw_card) . "\" />\n";
        echo '<meta name="twitter:title" content="' . esc_attr($soc_title ?: $title) . "\" />\n";
        echo '<meta name="twitter:description" content="' . esc_attr($soc_desc ?: $desc) . "\" />\n";
        if ($soc_image !== '') { echo '<meta name="twitter:image" content="' . esc_url($soc_image) . "\" />\n"; }
        if ($tw_site !== '') { echo '<meta name="twitter:site" content="' . esc_attr($tw_site) . "\" />\n"; }
        if ($tw_creator !== '') { echo '<meta name="twitter:creator" content="' . esc_attr($tw_creator) . "\" />\n"; }

        // Article-specific meta
        if ($post_id && is_singular('post')) {
            echo '<meta property="article:published_time" content="' . esc_attr(get_post_time('c', true, $post_id)) . "\" />\n";
            echo '<meta property="article:modified_time" content="' . esc_attr(get_post_modified_time('c', true, $post_id)) . "\" />\n";
            echo '<meta property="og:updated_time" content="' . esc_attr(get_post_modified_time('c', true, $post_id)) . "\" />\n";
            $cats = get_the_category($post_id);
            if ($cats && !is_wp_error($cats) && isset($cats[0])) {
                echo '<meta property="article:section" content="' . esc_attr($cats[0]->name) . "\" />\n";
            }
            $tags = get_the_tags($post_id);
            if ($tags) {
                foreach ($tags as $tg) {
                    echo '<meta property="article:tag" content="' . esc_attr($tg->name) . "\" />\n";
                }
            }
        }

        // Pagination prev/next
        if ($paged > 1) {
            $prev_link = get_pagenum_link($paged - 1);
            echo '<link rel="prev" href="' . esc_url($prev_link) . "\" />\n";
        }
        // If we can detect more than current, add next (best-effort)
        global $wp_query, $numpages;
        $has_next = false;
        if (!is_singular()) {
            if ($wp_query && $wp_query->max_num_pages && $paged < $wp_query->max_num_pages) { $has_next = true; }
        } else {
            if (is_numeric($numpages) && $numpages && $paged < $numpages) { $has_next = true; }
        }
        if ($has_next) {
            $next_link = get_pagenum_link($paged + 1);
            echo '<link rel="next" href="' . esc_url($next_link) . "\" />\n";
        }

        // JSON-LD graph
        $graph = [];
        $website_id = home_url('#website');
        $org_id     = home_url('#identity');

        $entity = isset($opts['site']['entity']) ? $opts['site']['entity'] : 'organization';
        $logo   = isset($opts['site']['logo']) ? $opts['site']['logo'] : '';
        $sameAs = isset($opts['site']['sameAs']) && is_array($opts['site']['sameAs']) ? array_values(array_filter($opts['site']['sameAs'])) : [];
        $org_node = [
            '@context' => 'https://schema.org',
            '@type'    => $entity === 'person' ? 'Person' : 'Organization',
            '@id'      => $org_id,
            'name'     => (string) ($opts['site']['name'] ?? get_bloginfo('name')),
        ];
        if ($logo) { $org_node['logo'] = ['@type' => 'ImageObject','url' => $logo]; }
        $org_node['url'] = home_url('/');
        if (!empty($sameAs)) { $org_node['sameAs'] = $sameAs; }
        $graph[] = $org_node;

        $graph[] = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            '@id'      => $website_id,
            'url'      => home_url('/'),
            'name'     => get_bloginfo('name'),
            'publisher'=> ['@id' => $org_id],
            'potentialAction' => [ [
                '@type' => 'SearchAction',
                'target'=> home_url('/?s={search_term_string}'),
                'query-input' => 'required name=search_term_string'
            ]]
        ];

        if ($post_id) {
            $typeNode = is_singular('post') ? 'Article' : 'WebPage';
            $node = [
                '@context' => 'https://schema.org',
                '@type'    => $typeNode,
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id'   => $url,
                ],
                'headline'      => wp_strip_all_tags(get_the_title($post_id)),
                'description'   => $desc,
                'datePublished' => get_post_time('c', true, $post_id),
                'dateModified'  => get_post_modified_time('c', true, $post_id),
                'author'        => [],
                'publisher'     => ['@id' => $org_id],
                'isPartOf'      => ['@id' => $website_id],
                'inLanguage'    => get_bloginfo('language'),
            ];
            $ans = get_post_meta($post_id, self::META_ANSWER, true);
            if ($ans) { $node['abstract'] = $ans; }
            // Author entity with @id
            $author_id = (int) get_post_field('post_author', $post_id);
            if ($author_id) {
                $author_node_id = home_url('#/person/author-' . $author_id);
                $author_node = [
                    '@context' => 'https://schema.org',
                    '@type'    => 'Person',
                    '@id'      => $author_node_id,
                    'name'     => wp_strip_all_tags(get_the_author_meta('display_name', $author_id)),
                    'url'      => get_author_posts_url($author_id),
                ];
                $avatar = get_avatar_url($author_id, ['size' => 256]);
                if ($avatar) { $author_node['image'] = [ '@type' => 'ImageObject', 'url' => $avatar ]; }
                $bio = get_the_author_meta('description', $author_id);
                if ($bio) { $author_node['description'] = wp_strip_all_tags($bio); }
                $author_node = apply_filters('savejson_author_node', $author_node, $author_id);
                $graph[] = $author_node;
                $node['author'] = [ '@id' => $author_node_id ];
            }
            if ($post_image !== '') {
                $imageNode = [ '@type' => 'ImageObject', 'url' => $post_image ];
                if (!empty($img_w) && !empty($img_h)) { $imageNode['width'] = $img_w; $imageNode['height'] = $img_h; }
                $node['image'] = $imageNode;
                $node['primaryImageOfPage'] = $imageNode;
            }
            $graph[] = $node;
        }

        // Taxonomy/archive schema (CollectionPage) and SearchResultsPage
        if (!$post_id) {
            if (is_search()) {
                $graph[] = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'SearchResultsPage',
                    '@id'        => home_url(add_query_arg([])) . '#search',
                    'url'        => home_url(add_query_arg([])),
                    'isPartOf'   => [ '@id' => $website_id ],
                    'inLanguage' => get_bloginfo('language'),
                    'name'       => sprintf(__('Search results for "%s"', 'save-json-content'), get_search_query()),
                ];
            } elseif (is_category() || is_tag() || is_tax()) {
                $term = get_queried_object();
                if ($term && isset($term->term_id)) {
                    $link = get_term_link($term);
                    if (!is_wp_error($link)) {
                        $collection = [
                            '@context'   => 'https://schema.org',
                            '@type'      => 'CollectionPage',
                            '@id'        => trailingslashit($link) . '#collection',
                            'url'        => $link,
                            'isPartOf'   => [ '@id' => $website_id ],
                            'inLanguage' => get_bloginfo('language'),
                            'name'       => $term->name,
                        ];
                        $tdesc = term_description($term);
                        if ($tdesc) { $collection['description'] = wp_strip_all_tags($tdesc); }
                        // Optional ItemList of current page posts
                        $include = apply_filters('savejson_collectionpage_include_items', true, $term);
                        if ($include) {
                            global $wp_query;
                            if ($wp_query && !empty($wp_query->posts)) {
                                $items = [];
                                $pos = 1;
                                foreach ($wp_query->posts as $p) {
                                    $items[] = [
                                        '@type'    => 'ListItem',
                                        'position' => $pos++,
                                        'url'      => get_permalink($p),
                                        'name'     => wp_strip_all_tags(get_the_title($p)),
                                    ];
                                }
                                $collection['hasPart'] = [
                                    '@type' => 'ItemList',
                                    'itemListElement' => $items,
                                ];
                            }
                        }
                        $graph[] = $collection;
                    }
                }
            }
        }

        // FAQ JSON-LD
        if ($post_id) {
            $faq = get_post_meta($post_id, self::META_FAQ, true);
            if (is_array($faq) && !empty($faq)) {
                $items = [];
                foreach ($faq as $row) {
                    $q = isset($row['question']) ? wp_strip_all_tags($row['question']) : '';
                    $a = isset($row['answer']) ? wp_kses_post($row['answer']) : '';
                    if ($q !== '' && $a !== '') {
                        $items[] = [
                            '@type' => 'Question',
                            'name'  => $q,
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text'  => $a,
                            ],
                        ];
                    }
                }
                if (!empty($items)) {
                    $graph[] = [
                        '@context'   => 'https://schema.org',
                        '@type'      => 'FAQPage',
                        'mainEntity' => $items,
                    ];
                }
            }
        }

        // HowTo JSON-LD
        if ($post_id) {
            $howto = get_post_meta($post_id, self::META_HOWTO, true);
            if (is_array($howto) && !empty($howto)) {
                $steps = [];
                $pos = 1;
                foreach ($howto as $row) {
                    $name = isset($row['name']) ? wp_strip_all_tags($row['name']) : '';
                    $text = isset($row['text']) ? wp_strip_all_tags($row['text']) : '';
                    if ($name === '' && $text === '') continue;
                    $steps[] = [ '@type' => 'HowToStep', 'position' => $pos, 'name' => ($name ?: sprintf(__('Step %d', 'save-json-content'), $pos)), 'text' => $text ];
                    $pos++;
                }
                if (!empty($steps)) {
                    $graph[] = [
                        '@context' => 'https://schema.org',
                        '@type'    => 'HowTo',
                        'name'     => wp_strip_all_tags(get_the_title($post_id)),
                        'step'     => $steps,
                    ];
                }
            }
        }

        // Extensibility: filter the final graph
        $graph = apply_filters('savejson_graph', $graph, $post_id);
        echo "<script type=\"application/ld+json\">" . wp_json_encode(count($graph) === 1 ? $graph[0] : ['@graph' => $graph], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "</script>\n";
    }

    public function maybe_redirect_attachment() : void {
        if (!is_attachment()) { return; }
        $opts = $this->get_options();
        $ar = isset($opts['archives']) && is_array($opts['archives']) ? $opts['archives'] : [];
        if (empty($ar['redirect_attachment'])) { return; }
        $id = get_queried_object_id();
        if (!$id) { return; }
        $parent = (int) get_post_field('post_parent', $id);
        $target = '';
        if ($parent) {
            $target = get_permalink($parent);
        }
        if (!$target) {
            $target = wp_get_attachment_url($id);
        }
        if ($target) {
            wp_safe_redirect($target, 301);
            exit;
        }
    }

    private function get_alternates(int $post_id) : array {
        $alts = apply_filters('savejson_alternates', null, $post_id);
        if (is_array($alts)) { return $alts; }

        $out = [];
        // Polylang
        if (function_exists('pll_the_languages')) {
            $args = ['raw' => 1, 'hide_if_no_translation' => 0];
            if ($post_id) { $args['post_id'] = $post_id; }
            $langs = function_exists('pll_the_languages') ? pll_the_languages($args) : [];
            if (is_array($langs)) {
                foreach ($langs as $info) {
                    if (!empty($info['url'])) {
                        $hreflang = !empty($info['slug']) ? $info['slug'] : (!empty($info['locale']) ? $info['locale'] : '');
                        $out[] = [ 'hreflang' => $hreflang, 'url' => $info['url'], 'locale' => $info['locale'] ?? '' ];
                    }
                }
            }
        }
        // WPML
        if (empty($out) && function_exists('icl_object_id')) {
            $langs = apply_filters('wpml_active_languages', null, 'skip_missing=0');
            if (is_array($langs)) {
                foreach ($langs as $code => $lang) {
                    $url = '';
                    if ($post_id) {
                        $alt_id = apply_filters('wpml_object_id', $post_id, get_post_type($post_id), false, $code);
                        if ($alt_id) { $url = get_permalink($alt_id); }
                    } else {
                        $url = $lang['url'] ?? '';
                    }
                    if ($url) {
                        $out[] = [ 'hreflang' => $code, 'url' => $url, 'locale' => $lang['default_locale'] ?? '' ];
                    }
                }
            }
        }
        // x-default for home
        if (empty($post_id)) {
            $out[] = [ 'hreflang' => 'x-default', 'url' => home_url('/') ];
        }
        return $out;
    }

    public function emit_custom_head_code() {
        if (!is_singular() || is_feed() || is_admin()) { return; }
        $post_id = get_queried_object_id();
        if (!$post_id) { return; }
        $code = (string) get_post_meta($post_id, self::META_HEAD_CODE, true);
        if ($code !== '') {
            echo "\n<!-- SAVEJSON head code -->\n";
            echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "\n<!-- /SAVEJSON head code -->\n";
        }
    }

    public function emit_footer_code() {
        if (!is_singular() || is_admin()) { return; }
        $post_id = get_queried_object_id();
        if (!$post_id) { return; }
        $code = (string) get_post_meta($post_id, self::META_FOOT_CODE, true);
        if ($code !== '') {
            echo "\n<!-- SAVEJSON footer code -->\n";
            echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "\n<!-- /SAVEJSON footer code -->\n";
        }
    }

    /* ===========================
     * Frontend voice
     * =========================== */
    public function enqueue_front() {
        if (!is_singular() || is_admin()) { return; }
        $post_id = get_queried_object_id();
        if (!$post_id) { return; }
        if (!get_post_meta($post_id, self::META_VOICE, true)) { return; }

        wp_enqueue_script('savejson-voice', SAVEJSON_PLUGIN_URL . 'assets/voice.js', [], SAVEJSON_VERSION, true);
        $tldr = (string) get_post_meta($post_id, self::META_TLDR, true);
        wp_localize_script('savejson-voice', 'SAVEJSON', [
            'tldr'  => $tldr,
            'title' => get_the_title($post_id),
            'lang'  => get_bloginfo('language'),
            'labels'=> [
                'listen' => __('Listen to summary', 'save-json-content'),
                'stop'   => __('Stop', 'save-json-content'),
                'unavailable' => __('Speech synthesis is not available in this browser.', 'save-json-content'),
            ],
        ]);
    }

    /* ===========================
     * Sitemaps filter
     * =========================== */
    public function sitemaps_exclude_noindex(array $args, string $post_type) : array {
        $filter = [
            'relation' => 'OR',
            [
                'key'     => self::META_NOINDEX,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => self::META_NOINDEX,
                'value'   => '1',
                'compare' => '!=',
            ],
        ];

        if (empty($args['meta_query'])) {
            $args['meta_query'] = $filter;
        } else {
            $args['meta_query'] = [
                'relation' => 'AND',
                $args['meta_query'],
                $filter,
            ];
        }
        return $args;
    }

    public function sitemaps_enabled($enabled) : bool {
        $opts = $this->get_options();
        if (isset($opts['sitemaps']['enabled'])) {
            return (bool) $opts['sitemaps']['enabled'];
        }
        return (bool) $enabled;
    }

    public function sitemaps_post_types(array $post_types) : array {
        $opts = $this->get_options();
        $cfg  = isset($opts['sitemaps']['types']) && is_array($opts['sitemaps']['types']) ? $opts['sitemaps']['types'] : [];

        if (!empty($cfg)) {
            foreach ($post_types as $name => $obj) {
                if (isset($cfg[$name]) && empty($cfg[$name])) {
                    unset($post_types[$name]);
                }
            }
        }

        // Optional: include attachments as image entries when enabled
        $include_images = !empty($opts['sitemaps']['include_images']);
        if ($include_images) {
            $att = get_post_type_object('attachment');
            if ($att) {
                $post_types['attachment'] = $att;
            }
        } else {
            unset($post_types['attachment']);
        }

        return $post_types;
    }

    public function sitemaps_taxonomies(array $taxonomies) : array {
        $opts = $this->get_options();
        $cfg  = isset($opts['sitemaps']['taxonomies']) && is_array($opts['sitemaps']['taxonomies']) ? $opts['sitemaps']['taxonomies'] : [];
        if (!empty($cfg)) {
            foreach ($taxonomies as $name => $obj) {
                if (isset($cfg[$name]) && empty($cfg[$name])) {
                    unset($taxonomies[$name]);
                }
            }
        }
        return $taxonomies;
    }

    public function sitemaps_add_provider($provider, string $name) {
        $opts = $this->get_options();
        if ($name === 'users' && empty($opts['sitemaps']['users'])) {
            return null; // disable users sitemap provider
        }
        return $provider;
    }

    public function rss_inject($content) {
        if (is_admin()) return $content;
        $opts = $this->get_options();
        $rss  = isset($opts['rss']) && is_array($opts['rss']) ? $opts['rss'] : [];
        $before = (string) ($rss['before'] ?? '');
        $after  = (string) ($rss['after']  ?? '');
        if ($before === '' && $after === '') return $content;

        $post_id = get_the_ID();
        $replacements = [
            '%%sitename%%'  => get_bloginfo('name'),
            '%%site_link%%' => home_url('/'),
            '%%title%%'     => $post_id ? get_the_title($post_id) : '',
            '%%post_link%%' => $post_id ? get_permalink($post_id) : '',
            '%%author%%'    => $post_id ? get_the_author_meta('display_name', get_post_field('post_author', $post_id)) : '',
        ];
        $before = strtr($before, $replacements);
        $after  = strtr($after,  $replacements);
        return $before . $content . $after;
    }
}
