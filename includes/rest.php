<?php
namespace SaveJSON;

if (!defined('ABSPATH')) { exit; }

class Rest {
    // Avoid typed property for broader PHP compatibility
    private static $authed_via_token = false;
    public static function init() : void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() : void {
        register_rest_route('savejson/v1', '/upsert', [
            'methods'  => ['POST'],
            'callback' => [__CLASS__, 'handle_upsert'],
            'permission_callback' => [__CLASS__, 'can_upsert'],
            'args' => [
                'type' => [ 'type' => 'string', 'required' => false, 'default' => 'post' ],
                'id'   => [ 'type' => 'integer', 'required' => false ],
                'slug' => [ 'type' => 'string', 'required' => false ],
                'status' => [ 'type' => 'string', 'required' => false, 'default' => 'draft' ],
                'title'  => [ 'type' => 'string', 'required' => false ],
                'slug_new' => [ 'type' => 'string', 'required' => false ],
                'content'=> [ 'type' => 'string', 'required' => false ],
                'excerpt'=> [ 'type' => 'string', 'required' => false ],
                'categories' => [ 'type' => 'array', 'required' => false ],
                'tags'       => [ 'type' => 'array', 'required' => false ],
                'meta'       => [ 'type' => 'object', 'required' => false ],
                'featured_image_url' => [ 'type' => 'string', 'required' => false ],
                'create_terms' => [ 'type' => 'boolean', 'required' => false, 'default' => true ],
            ],
        ]);

        // OpenAPI schema (public) for Custom GPT import
        register_rest_route('savejson/v1', '/openapi', [
            'methods'  => ['GET'],
            'callback' => [__CLASS__, 'get_openapi'],
            'permission_callback' => '__return_true',
        ]);

        // Simple auth probe to debug 401s
        register_rest_route('savejson/v1', '/whoami', [
            'methods'  => ['GET'],
            'callback' => function() {
                $uid = get_current_user_id();
                return new \WP_REST_Response([
                    'user_id' => $uid,
                    'can_edit_posts' => current_user_can('edit_posts'),
                ], 200);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    public static function can_upsert() : bool {
        if (current_user_can('edit_posts')) { self::$authed_via_token = false; return true; }
        // Token-based auth: Accept X-SaveJSON-Token header or Authorization: Bearer <token>
        $opts = get_option('savejson_options', []);
        $api  = is_array($opts) && isset($opts['api']) && is_array($opts['api']) ? $opts['api'] : [];
        $token = isset($api['token']) ? (string) $api['token'] : '';
        $user  = isset($api['user']) ? (int) $api['user'] : 0;
        $hdr   = isset($_SERVER['HTTP_X_SAVEJSON_TOKEN']) ? (string) $_SERVER['HTTP_X_SAVEJSON_TOKEN'] : '';
        $auth  = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : '';
        $param = isset($_GET['savejson_token']) ? sanitize_text_field((string) $_GET['savejson_token']) : '';
        $given = '';
        if ($hdr !== '') { $given = $hdr; }
        elseif (stripos($auth, 'Bearer ') === 0) { $given = trim(substr($auth, 7)); }
        elseif ($param !== '') { $given = $param; }
        if ($token !== '' && $given !== '' && hash_equals($token, $given)) {
            if ($user <= 0) {
                $candidates = get_users(['role__in' => ['administrator','editor'], 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
                if (!empty($candidates)) { $user = (int) $candidates[0]->ID; }
            }
            if ($user > 0) { wp_set_current_user($user); }
            self::$authed_via_token = true;
            return current_user_can('edit_posts');
        }
        self::$authed_via_token = false;
        return false;
    }

    /**
     * POST savejson/v1/upsert
     * Creates or updates a post/page with SAVE JSON meta.
     */
    public static function handle_upsert(\WP_REST_Request $req) : \WP_REST_Response {
        $type  = sanitize_key($req->get_param('type') ?: 'post');
        $status= sanitize_key($req->get_param('status') ?: 'draft');
        // If authenticated via token and publishing is disallowed, force draft
        $opts = get_option('savejson_options', []);
        $api  = is_array($opts) && isset($opts['api']) && is_array($opts['api']) ? $opts['api'] : [];
        $allow_publish = !empty($api['allow_publish']);
        if (self::$authed_via_token && !$allow_publish) { $status = 'draft'; }
        $id    = (int) ($req->get_param('id') ?: 0);
        $slug  = sanitize_title($req->get_param('slug') ?: '');
        $slug_new = sanitize_title($req->get_param('slug_new') ?: '');
        $title = (string) ($req->get_param('title') ?: '');
        $content = (string) ($req->get_param('content') ?: '');
        $content_html = (string) ($req->get_param('content_html') ?: '');
        if ($content === '' && $content_html !== '') { $content = $content_html; }
        $excerpt = (string) ($req->get_param('excerpt') ?: '');
        $meta    = (array) ($req->get_param('meta') ?: []);
        // Fallback: some clients nest body under meta; accept common aliases
        if ($content === '') {
            foreach (['content_html','content','body_html','body'] as $k) {
                if (!empty($meta[$k]) && is_string($meta[$k])) { $content = (string) $meta[$k]; unset($meta[$k]); break; }
            }
        }
        $cats    = $req->get_param('categories');
        $tags    = $req->get_param('tags');
        $feat_url= (string) ($req->get_param('featured_image_url') ?: '');
        $create_terms = (bool) ($req->get_param('create_terms') ?? true);

        // Validate post type against allowed list
        $allowed_types = apply_filters('savejson_post_types', ['post','page']);
        if (!in_array($type, (array) $allowed_types, true)) {
            return new \WP_REST_Response([ 'error' => 'invalid_post_type' ], 400);
        }

        // Resolve target post for update
        $existing = null;
        if ($id > 0) {
            $existing = get_post($id);
            if (!$existing) { return new \WP_REST_Response([ 'error' => 'not_found' ], 404); }
            if (!current_user_can('edit_post', $existing->ID)) { return new \WP_REST_Response([ 'error' => 'forbidden' ], 403); }
        } elseif ($slug !== '') {
            $existing = get_page_by_path($slug, OBJECT, $type);
        }

        $is_update = ($existing instanceof \WP_Post);
        $postarr = [
            'post_type'   => $type,
            'post_status' => $status,
        ];
        if ($title !== '')  { $postarr['post_title'] = $title; }
        if ($content !== ''){ $postarr['post_content'] = $content; }
        if ($excerpt !== ''){ $postarr['post_excerpt'] = $excerpt; }
        if ($slug_new !== ''){ $postarr['post_name'] = $slug_new; }

        if ($is_update) {
            $postarr['ID'] = $existing->ID;
            $result_id = wp_update_post($postarr, true);
            if (is_wp_error($result_id)) {
                return new \WP_REST_Response([ 'error' => $result_id->get_error_message() ], 400);
            }
            $post_id = (int) $result_id;
        } else {
            if ($slug !== '') { $postarr['post_name'] = $slug; }
            $result_id = wp_insert_post($postarr, true);
            if (is_wp_error($result_id)) {
                return new \WP_REST_Response([ 'error' => $result_id->get_error_message() ], 400);
            }
            $post_id = (int) $result_id;
        }

        // Terms (IDs or slugs). For pages, categories/tags are ignored.
        if ($type === 'post') {
            if (is_array($cats)) {
                $ids = self::resolve_terms('category', $cats, $create_terms);
                if (!empty($ids)) { wp_set_post_terms($post_id, $ids, 'category', false); }
            }
            if (is_array($tags)) {
                $ids = self::resolve_terms('post_tag', $tags, $create_terms);
                if (!empty($ids)) { wp_set_post_terms($post_id, $ids, 'post_tag', false); }
            }
        }

        // SAVE JSON meta mapping (sanitize like UI)
        self::apply_savejson_meta($post_id, $meta);

        // Featured image by URL (optional)
        if ($feat_url !== '') {
            $att_id = self::sideload_image($feat_url, $post_id);
            if ($att_id) { set_post_thumbnail($post_id, $att_id); }
        }

        $resp = [
            'id'     => $post_id,
            'type'   => get_post_type($post_id),
            'status' => get_post_status($post_id),
            'slug'   => get_post_field('post_name', $post_id),
            'link'   => get_permalink($post_id),
            'updated'=> $is_update,
            'created'=> !$is_update,
        ];
        $code = $is_update ? 200 : 201;
        return new \WP_REST_Response($resp, $code);
    }

    private static function resolve_terms(string $taxonomy, array $items, bool $create) : array {
        $ids = [];
        foreach ($items as $item) {
            if (is_numeric($item)) {
                $ids[] = (int) $item; continue;
            }
            $slug = sanitize_title((string) $item);
            $term = get_term_by('slug', $slug, $taxonomy);
            if (!$term && $create) {
                $r = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), $taxonomy, ['slug' => $slug]);
                if (!is_wp_error($r) && !empty($r['term_id'])) { $ids[] = (int) $r['term_id']; }
            } elseif ($term) {
                $ids[] = (int) $term->term_id;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function apply_savejson_meta(int $post_id, array $meta) : void {
        // Simple fields
        $pairs = [
            Plugin::META_META_TITLE    => 'string',
            Plugin::META_DESC          => 'textarea',
            Plugin::META_TLDR          => 'textarea',
            Plugin::META_CANONICAL     => 'url',
            Plugin::META_NOINDEX       => 'flag',
            Plugin::META_ROBOTS_FOLLOW => 'robots_follow',
            Plugin::META_ROBOTS_ADV    => 'string',
            Plugin::META_SOC_TITLE     => 'string',
            Plugin::META_SOC_DESC      => 'textarea',
            Plugin::META_SOC_IMAGE     => 'url',
            Plugin::META_TW_CARD       => 'string',
            Plugin::META_TW_SITE       => 'string',
            Plugin::META_TW_CREATOR    => 'string',
            Plugin::META_ANSWER        => 'textarea',
            Plugin::META_SHARE_TW_TEXT => 'textarea',
            Plugin::META_SHARE_TW_TAGS => 'string',
            Plugin::META_SHARE_FB_TEXT => 'textarea',
            Plugin::META_SHARE_LI_TEXT => 'textarea',
            Plugin::META_IMG_PROMPT_GEMINI => 'textarea',
            Plugin::META_ADOBE_QUERY       => 'string',
            Plugin::META_ADOBE_DESC        => 'textarea',
        ];

        foreach ($pairs as $key => $kind) {
            if (!array_key_exists($key, $meta)) { continue; }
            $val = $meta[$key];
            switch ($kind) {
                case 'url':      $val = esc_url_raw((string) $val); break;
                case 'textarea': $val = sanitize_textarea_field((string) $val); break;
                case 'flag':     $val = $val ? '1' : ''; break;
                case 'robots_follow': $val = $val ? '0' : '1'; break; // true means nofollow
                default:         $val = sanitize_text_field((string) $val); break;
            }
            if ($val === '' && $kind !== 'flag') { delete_post_meta($post_id, $key); }
            else {
                if ($kind === 'flag') {
                    if ($val !== '') update_post_meta($post_id, $key, '1'); else delete_post_meta($post_id, $key);
                } else {
                    update_post_meta($post_id, $key, $val);
                }
            }
        }

        // FAQ
        if (array_key_exists(Plugin::META_FAQ, $meta)) {
            $clean = Plugin::sanitize_faq_value($meta[Plugin::META_FAQ]);
            if (!empty($clean)) update_post_meta($post_id, Plugin::META_FAQ, $clean); else delete_post_meta($post_id, Plugin::META_FAQ);
        }
        // HowTo
        if (array_key_exists(Plugin::META_HOWTO, $meta)) {
            $clean = Plugin::sanitize_howto_value($meta[Plugin::META_HOWTO]);
            if (!empty($clean)) update_post_meta($post_id, Plugin::META_HOWTO, $clean); else delete_post_meta($post_id, Plugin::META_HOWTO);
        }
        // Head/Foot scripts (requires unfiltered_html)
        if (current_user_can('unfiltered_html')) {
            if (array_key_exists(Plugin::META_HEAD_CODE, $meta)) {
                $v = (string) ($meta[Plugin::META_HEAD_CODE] ?? '');
                if ($v !== '') update_post_meta($post_id, Plugin::META_HEAD_CODE, $v); else delete_post_meta($post_id, Plugin::META_HEAD_CODE);
            }
            if (array_key_exists(Plugin::META_FOOT_CODE, $meta)) {
                $v = (string) ($meta[Plugin::META_FOOT_CODE] ?? '');
                if ($v !== '') update_post_meta($post_id, Plugin::META_FOOT_CODE, $v); else delete_post_meta($post_id, Plugin::META_FOOT_CODE);
            }
        }
    }

    private static function sideload_image(string $url, int $post_id) : int {
        if (!filter_var($url, FILTER_VALIDATE_URL)) { return 0; }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($url);
        if (is_wp_error($tmp)) { return 0; }
        $file_array = [
            'name' => basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg'),
            'tmp_name' => $tmp,
        ];
        $att_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($att_id)) { @unlink($tmp); return 0; }
        return (int) $att_id;
    }

    public static function get_openapi() : \WP_REST_Response {
        $server = untrailingslashit(rest_url()); // e.g., https://site.com/wp-json
        $spec = [
            'openapi' => '3.1.1',
            'info' => [ 'title' => 'WordPress SaveJSON', 'version' => defined('SAVEJSON_VERSION') ? SAVEJSON_VERSION : '1.0.0' ],
            'servers' => [ [ 'url' => $server ] ],
            'components' => [
                'schemas' => (object) [],
                'securitySchemes' => [
                    'bearerAuth' => [ 'type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'SAVEJSON-Token' ],
                ],
            ],
            'security' => [ [ 'bearerAuth' => [] ] ],
            'paths' => [
                '/savejson/v1/upsert' => [
                    'post' => [
                        'operationId' => 'upsertContent',
                        'summary' => 'Create or update a post/page with SAVE JSON meta',
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'properties' => [
                                            'type' => [ 'type' => 'string', 'enum' => ['post','page'], 'default' => 'post' ],
                                            'status' => [ 'type' => 'string', 'enum' => ['draft','publish'], 'default' => 'draft' ],
                                            'id' => [ 'type' => 'integer' ],
                                            'slug' => [ 'type' => 'string' ],
                                            'slug_new' => [ 'type' => 'string' ],
                                            'title' => [ 'type' => 'string' ],
                                            'content' => [ 'type' => 'string' ],
                                            'content_html' => [ 'type' => 'string', 'description' => 'Alias of content; HTML body' ],
                                            'excerpt' => [ 'type' => 'string' ],
                                            'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'IDs or slugs' ],
                                            'tags' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'IDs or slugs' ],
                                            'featured_image_url' => [ 'type' => 'string' ],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    Plugin::META_META_TITLE => [ 'type' => 'string' ],
                                                    Plugin::META_DESC       => [ 'type' => 'string' ],
                                                    Plugin::META_TLDR       => [ 'type' => 'string' ],
                                                    Plugin::META_CANONICAL  => [ 'type' => 'string' ],
                                                    Plugin::META_NOINDEX    => [ 'type' => 'string' ],
                                                    Plugin::META_ROBOTS_FOLLOW => [ 'type' => 'string' ],
                                                    Plugin::META_ROBOTS_ADV => [ 'type' => 'string' ],
                                                    Plugin::META_SOC_TITLE  => [ 'type' => 'string' ],
                                                    Plugin::META_SOC_DESC   => [ 'type' => 'string' ],
                                                    Plugin::META_SOC_IMAGE  => [ 'type' => 'string' ],
                                                    Plugin::META_TW_CARD    => [ 'type' => 'string' ],
                                                    Plugin::META_TW_SITE    => [ 'type' => 'string' ],
                                                    Plugin::META_TW_CREATOR => [ 'type' => 'string' ],
                                                    Plugin::META_ANSWER     => [ 'type' => 'string' ],
                                                    Plugin::META_SHARE_TW_TEXT => [ 'type' => 'string' ],
                                                    Plugin::META_SHARE_TW_TAGS => [ 'type' => 'string' ],
                                                    Plugin::META_SHARE_FB_TEXT => [ 'type' => 'string' ],
                                                    Plugin::META_SHARE_LI_TEXT => [ 'type' => 'string' ],
                                                    Plugin::META_IMG_PROMPT_GEMINI => [ 'type' => 'string' ],
                                                    Plugin::META_ADOBE_QUERY       => [ 'type' => 'string' ],
                                                    Plugin::META_ADOBE_DESC        => [ 'type' => 'string' ],
                                                    Plugin::META_FAQ        => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'question' => [ 'type' => 'string' ], 'answer' => [ 'type' => 'string' ] ] ] ],
                                                    Plugin::META_HOWTO      => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'text' => [ 'type' => 'string' ] ] ] ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [ 'description' => 'Updated' ],
                            '201' => [ 'description' => 'Created' ],
                        ],
                    ],
                ],
            ],
        ];
        return new \WP_REST_Response($spec, 200);
    }
}
