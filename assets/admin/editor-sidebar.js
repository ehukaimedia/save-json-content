/* global wp */
(function() {
    const { __ } = wp.i18n;
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost || {};
    const { PanelBody, TextControl, TextareaControl, ToggleControl } = wp.components;
    const { useSelect, useDispatch } = wp.data;
    const el = wp.element.createElement;

    if (!PluginDocumentSettingPanel) { return; }

    const META = {
        title: '_save_meta_title',
        desc: '_save_meta_desc',
        tldr: '_save_tldr',
        noindex: '_save_noindex',
        voice: '_save_voice_enabled',
        canonical: '_save_canonical',
        robots_follow: '_save_robots_follow',
        robots_adv: '_save_robots_advanced',
    };

    function useMeta() {
        const meta = useSelect( (select) => select('core/editor').getEditedPostAttribute('meta') || {}, [] );
        const { editPost } = useDispatch('core/editor');
        const setMeta = (key, value) => {
            const next = Object.assign({}, meta, { [key]: value });
            editPost({ meta: next });
        };
        return [meta, setMeta];
    }

    const Panel = () => {
        const [meta, setMeta] = useMeta();
        const noindex = !!(meta[META.noindex]);
        const nofollow = (meta[META.robots_follow] === '0');
        const voice = !!(meta[META.voice]);
        return el(PluginDocumentSettingPanel, {
            name: 'savejson-seo-panel',
            title: __('SAVE JSON — SEO', 'save-json-content'),
            className: 'savejson-seo-panel',
        },
            el(TextControl, {
                label: __('SEO Title (override)', 'save-json-content'),
                value: meta[META.title] || '',
                onChange: (v) => setMeta(META.title, v)
            }),
            el(TextareaControl, {
                label: __('Meta Description', 'save-json-content'),
                value: meta[META.desc] || '',
                onChange: (v) => setMeta(META.desc, v),
                rows: 3,
            }),
            el(TextareaControl, {
                label: __('TL;DR (Short Summary)', 'save-json-content'),
                value: meta[META.tldr] || '',
                onChange: (v) => setMeta(META.tldr, v),
                rows: 3,
                help: __('Used for meta fallback and voice playback.', 'save-json-content')
            }),
            el(ToggleControl, {
                label: __('Noindex (exclude from sitemaps)', 'save-json-content'),
                checked: noindex,
                onChange: (val) => setMeta(META.noindex, val ? '1' : '')
            }),
            el(ToggleControl, {
                label: __('Nofollow (otherwise Follow)', 'save-json-content'),
                checked: nofollow,
                onChange: (val) => setMeta(META.robots_follow, val ? '0' : '')
            }),
            el(TextControl, {
                label: __('Robots advanced (CSV)', 'save-json-content'),
                value: meta[META.robots_adv] || '',
                onChange: (v) => setMeta(META.robots_adv, v),
                help: __('e.g., nosnippet,noarchive,max-snippet:-1', 'save-json-content')
            }),
            el(TextControl, {
                label: __('Canonical URL', 'save-json-content'),
                type: 'url',
                value: meta[META.canonical] || '',
                onChange: (v) => setMeta(META.canonical, v)
            }),
            el(ToggleControl, {
                label: __('Enable “Listen to summary” button', 'save-json-content'),
                checked: voice,
                onChange: (val) => setMeta(META.voice, val ? '1' : '')
            })
        );
    };

    registerPlugin('savejson-seo', {
        render: Panel,
        icon: 'analytics',
    });
})();

