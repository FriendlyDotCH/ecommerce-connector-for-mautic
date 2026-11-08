/**
 * Email builder (GrapesJS) block for product cards: drops a text block holding
 * {ecommerce_products source="..." limit="..." category="..."} (or {ecommerce_alert_products})
 * and edits it through settings instead of typing the token. The token stays the saved content,
 * so emails keep working without this script. The block is marked with the css-class
 * "ecommerce-products" (MJML) or class (HTML), which is how saved blocks are recognised.
 */
(function () {
    'use strict';

    var TYPE = 'ecommerce-products';
    var MARKER = 'ecommerce-products';
    var DEFAULT_LIMIT = 3;
    var MAX_LIMIT = 12;
    var SOURCES = ['recommended', 'cross_sell', 'best_sellers', 'viewed', 'last_purchased', 'alert'];
    var CATEGORY_SOURCES = ['recommended', 'best_sellers'];
    var TOKEN_RE = /\{ecommerce_(alert_products|products)((?:\s+[a-z_]+=(?:"[^"{}]*"|'[^'{}]*'))*)\s*\}/i;
    var ATTRIBUTE_RE = /([a-z_]+)=(?:"([^"]*)"|'([^']*)')/gi;

    var categoriesPromise = null;
    var previews = {};

    function translate(key, fallback) {
        var text = window.Mautic && typeof window.Mautic.translate === 'function' ? window.Mautic.translate(key) : key;

        return text && text !== key ? text : fallback;
    }

    function decodeEntities(value) {
        return String(value || '')
            .replace(/&quot;/g, '"')
            .replace(/&#0?39;|&apos;/g, "'")
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&');
    }

    /**
     * The block's settings from its token, or null when the content holds no product token.
     */
    function parseToken(content) {
        var match = TOKEN_RE.exec(decodeEntities(content));
        if (!match) {
            return null;
        }

        if ('alert_products' === match[1].toLowerCase()) {
            return {source: 'alert', limit: DEFAULT_LIMIT, category: ''};
        }

        var attributes = {};
        var attribute;
        ATTRIBUTE_RE.lastIndex = 0;
        while ((attribute = ATTRIBUTE_RE.exec(match[2])) !== null) {
            attributes[attribute[1].toLowerCase()] = undefined !== attribute[2] ? attribute[2] : attribute[3];
        }

        return {
            source: SOURCES.indexOf(attributes.source) !== -1 ? attributes.source : 'recommended',
            limit: normaliseLimit(attributes.limit),
            category: attributes.category || ''
        };
    }

    function normaliseLimit(value) {
        var limit = parseInt(value, 10);

        return isNaN(limit) ? DEFAULT_LIMIT : Math.max(1, Math.min(MAX_LIMIT, limit));
    }

    function buildToken(settings) {
        if ('alert' === settings.source) {
            return '{ecommerce_alert_products}';
        }

        var token = '{ecommerce_products source="' + settings.source + '" limit="' + normaliseLimit(settings.limit) + '"';
        // Quotes and braces would end the token.
        var category = String(settings.category || '').replace(/["'{}]/g, '').trim();
        if (category && CATEGORY_SOURCES.indexOf(settings.source) !== -1) {
            token += ' category="' + category + '"';
        }

        return token + '}';
    }

    function loadCategories() {
        if (!categoriesPromise) {
            categoriesPromise = ajax('productCategories').then(function (data) {
                return Array.isArray(data.categories) ? data.categories : [];
            });
        }

        return categoriesPromise;
    }

    function ajax(action, params) {
        if (!window.fetch || !window.mauticAjaxUrl) {
            return Promise.resolve({});
        }

        return window.fetch(window.mauticAjaxUrl + '?action=plugin:EcommerceConnector:' + action + (params ? '&' + params : ''), {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': window.mauticAjaxCsrf || ''}
        })
            .then(function (response) { return response.ok ? response.json() : {}; })
            .catch(function () { return {}; });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    /**
     * Example cards from the catalog, cached per setting.
     */
    function loadPreview(settings) {
        var params = 'source=' + encodeURIComponent(settings.source)
            + '&limit=' + normaliseLimit(settings.limit)
            + '&category=' + encodeURIComponent(CATEGORY_SOURCES.indexOf(settings.source) !== -1 ? (settings.category || '') : '');

        if (!previews[params]) {
            previews[params] = ajax('productBlockPreview', params).then(function (data) {
                return {html: typeof data.html === 'string' ? data.html : '', example: !!data.example};
            });
        }

        return previews[params];
    }

    function settingsOf(model) {
        return {source: model.get('ecSource'), limit: model.get('ecLimit'), category: model.get('ecCategory')};
    }

    function categoryOptions(categories, current) {
        var options = [{id: '', label: translate('mautic.plugin.ecommerce.builder.category.any', 'Any category')}];
        var names = categories.slice();
        if (current && names.indexOf(current) === -1) {
            names.unshift(current);
        }
        names.forEach(function (name) {
            options.push({id: name, label: name});
        });

        return options;
    }

    function sourceOptions() {
        return SOURCES.map(function (source) {
            return {id: source, label: translate('mautic.plugin.ecommerce.builder.source.' + source, source)};
        });
    }

    function hasMarker(el, attribute) {
        var value = el && el.getAttribute ? el.getAttribute(attribute) : null;

        return !!value && (' ' + value + ' ').indexOf(' ' + MARKER + ' ') !== -1;
    }

    function plugin(editor) {
        var components = editor.DomComponents;
        var isMjml = !!components.getType('mj-text');
        var baseType = isMjml ? 'mj-text' : 'text';
        var label = translate('mautic.plugin.ecommerce.builder.block', 'Products');

        components.addType(TYPE, {
            extend: baseType,

            isComponent: function (el) {
                if (!el || !el.tagName) {
                    return false;
                }

                var tag = el.tagName.toUpperCase();
                if (isMjml ? ('MJ-TEXT' === tag && hasMarker(el, 'css-class')) : hasMarker(el, 'class')) {
                    return {type: TYPE};
                }

                return false;
            },

            model: {
                defaults: {
                    name: label,
                    editable: false,
                    droppable: false,
                    ecSource: 'recommended',
                    ecLimit: DEFAULT_LIMIT,
                    ecCategory: '',
                    traits: [
                        {
                            type: 'select',
                            name: 'ecSource',
                            label: translate('mautic.plugin.ecommerce.builder.source', 'Products'),
                            changeProp: true,
                            options: sourceOptions()
                        },
                        {
                            type: 'number',
                            name: 'ecLimit',
                            label: translate('mautic.plugin.ecommerce.builder.limit', 'Number of products'),
                            changeProp: true,
                            min: 1,
                            max: MAX_LIMIT,
                            step: 1
                        },
                        {
                            type: 'select',
                            name: 'ecCategory',
                            label: translate('mautic.plugin.ecommerce.builder.category', 'Category (recommended, best sellers)'),
                            changeProp: true,
                            options: categoryOptions([], '')
                        }
                    ]
                },

                init: function () {
                    var settings = parseToken(this.getInnerHTML()) || {source: 'recommended', limit: DEFAULT_LIMIT, category: ''};
                    this.set({ecSource: settings.source, ecLimit: settings.limit, ecCategory: settings.category}, {silent: true});

                    var cssClass = this.getAttributes()['css-class'] || '';
                    if (isMjml) {
                        if ((' ' + cssClass + ' ').indexOf(' ' + MARKER + ' ') === -1) {
                            this.addAttributes({'css-class': (cssClass + ' ' + MARKER).trim()});
                        }
                    } else if (!this.getClasses().includes(MARKER)) {
                        this.addClass(MARKER);
                    }

                    this.writeToken();
                    this.on('change:ecSource change:ecLimit change:ecCategory', this.writeToken);

                    var model = this;
                    loadCategories().then(function (categories) {
                        var trait = model.getTrait('ecCategory');
                        if (trait) {
                            trait.set('options', categoryOptions(categories, model.get('ecCategory')));
                        }
                    });
                },

                writeToken: function () {
                    var token = buildToken(settingsOf(this));

                    if (decodeEntities(this.getInnerHTML()).trim() !== token) {
                        this.components(token);
                    }
                }
            },

            // The canvas shows example cards in place of the token; only the canvas DOM changes,
            // the saved content stays the token.
            extendFnView: ['init', 'onRender'],
            view: {
                init: function () {
                    this.listenTo(this.model, 'change:ecSource change:ecLimit change:ecCategory', this.schedulePreview);
                },

                onRender: function () {
                    this.schedulePreview();
                },

                schedulePreview: function () {
                    clearTimeout(this.ecPreviewTimer);
                    this.ecPreviewTimer = setTimeout(this.applyPreview.bind(this), 100);
                },

                applyPreview: function () {
                    var view = this;
                    var settings = settingsOf(this.model);

                    loadPreview(settings).then(function (preview) {
                        // Settings changed meanwhile: a newer preview is on its way.
                        if (buildToken(settingsOf(view.model)) !== buildToken(settings)) {
                            return;
                        }

                        var container = view.getChildrenContainer ? view.getChildrenContainer() : view.el;
                        if (!container || !container.ownerDocument) {
                            return;
                        }

                        var caption = translate('mautic.plugin.ecommerce.builder.source.' + settings.source, settings.source);
                        if ('alert' !== settings.source) {
                            caption += ' · ' + normaliseLimit(settings.limit);
                        }
                        if (settings.category && CATEGORY_SOURCES.indexOf(settings.source) !== -1) {
                            caption += ' · ' + settings.category;
                        }
                        if (preview.example) {
                            caption += ' · ' + translate('mautic.plugin.ecommerce.builder.example', 'example: each contact sees their own products');
                        }

                        var box = container.ownerDocument.createElement('div');
                        box.setAttribute('data-ecommerce-preview', '1');
                        box.style.pointerEvents = 'none';
                        box.innerHTML = '<div style="font:12px/1.4 Arial,sans-serif;color:#6b7280;margin:0 0 6px;">' + escapeHtml(caption) + '</div>'
                            + (preview.html || '<div style="padding:16px;border:1px dashed #cbd5e1;text-align:center;color:#6b7280;font:13px/1.4 Arial,sans-serif;">'
                                + escapeHtml(translate('mautic.plugin.ecommerce.builder.empty', 'No products to show yet. The email shows matching products from the catalog.')) + '</div>');

                        while (container.firstChild) {
                            container.removeChild(container.firstChild);
                        }
                        container.appendChild(box);
                    });
                }
            }
        });

        editor.BlockManager.add(TYPE, {
            label: label,
            category: translate('mautic.plugin.ecommerce.builder.category_name', 'Ecommerce'),
            media: '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 4h2l2.6 10.4A2 2 0 0 0 9.5 16H18v-2H9.5l-.5-2H18l3-7H6.2L5.6 3H3zm6.5 13a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3m8 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3"/></svg>',
            content: isMjml
                ? '<mj-text css-class="' + MARKER + '">' + buildToken({source: 'recommended', limit: DEFAULT_LIMIT}) + '</mj-text>'
                : '<div class="' + MARKER + '">' + buildToken({source: 'recommended', limit: DEFAULT_LIMIT}) + '</div>',
            activate: true,
            select: true
        });
    }

    window.MauticGrapesJsPlugins = window.MauticGrapesJsPlugins || [];
    window.MauticGrapesJsPlugins.push({
        name: 'ecommerce-products',
        context: ['email-mjml', 'email-html'],
        plugin: plugin
    });

    // For tests.
    window.MauticEcommerceBuilder = {parseToken: parseToken, buildToken: buildToken, plugin: plugin};
})();
