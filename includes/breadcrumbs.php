<?php
namespace SaveJSON;

if (!defined('ABSPATH')) { exit; }

// Register shortcode
add_action('init', function(){
    add_shortcode('savejson_breadcrumbs', __NAMESPACE__ . '\\render_breadcrumbs');
    if (function_exists('register_block_type')) {
        register_block_type('savejson/breadcrumbs', [
            'api_version' => 2,
            'render_callback' => __NAMESPACE__ . '\\render_breadcrumbs',
            'attributes' => [],
            'supports' => ['html' => false],
        ]);
    }
});

function render_breadcrumbs($atts=[], $content='') {
    $trail = [];
    $trail[] = ['label'=>__('Home','save-json-content'),'url'=>home_url('/')];
    if (is_singular()) {
        $post = get_queried_object();
        if ($post && $post->post_type === 'post') {
            $primary_id = (int) get_post_meta($post->ID, '_save_primary_category', true);
            if ($primary_id) {
                $term = get_term($primary_id, 'category');
                if ($term && !is_wp_error($term)) {
                    $trail[] = ['label'=>$term->name, 'url'=>get_term_link($term)];
                }
            } else {
                $cats = get_the_category($post->ID);
                if ($cats) {
                    $trail[] = ['label'=>$cats[0]->name, 'url'=>get_category_link($cats[0]->term_id)];
                }
            }
        }
        $custom = get_post_meta($post->ID, Plugin::META_BREADCRUMB_T, true);
        $trail[] = ['label'=> $custom ?: get_the_title($post), 'url'=> get_permalink($post)];
    } elseif (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if ($term && isset($term->name)) {
            $trail[] = ['label'=>$term->name, 'url'=>get_term_link($term)];
        }
    } elseif (is_search()) {
        $trail[] = ['label'=> sprintf(__('Search results for “%s”','save-json-content'), get_search_query()), 'url'=>''];
    } elseif (is_home()) {
        $trail[] = ['label'=> get_the_title(get_option('page_for_posts')) ?: __('Blog','save-json-content'), 'url'=> ''];
    }

    // Render HTML
    $html = '<nav class="savejson-breadcrumbs" aria-label="'.esc_attr__('Breadcrumb','save-json-content').'"><ol>';
    foreach ($trail as $i=>$item) {
        $label = esc_html($item['label']);
        if ($item['url'] && $i < (count($trail)-1)) {
            $html .= '<li><a href="'.esc_url($item['url']).'">'.$label.'</a></li>';
        } else {
            $html .= '<li><span>'.$label.'</span></li>';
        }
    }
    $html .= '</ol></nav>';

    // JSON-LD
    $items = [];
    foreach ($trail as $i=>$item) {
        $items[] = [
            '@type'=>'ListItem',
            'position'=>$i+1,
            'name'=> wp_strip_all_tags($item['label']),
            'item'=> $item['url'] ?: null,
        ];
    }
    $jsonld = [
        '@context'=>'https://schema.org',
        '@type'=>'BreadcrumbList',
        'itemListElement'=> $items,
    ];
    $html .= "\n<script type=\"application/ld+json\">".wp_json_encode($jsonld, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."</script>\n";
    return $html;
}
