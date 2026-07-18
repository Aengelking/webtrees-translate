/**
 * Translate Notes - front-end.
 *
 * Automatically translates each note (config.selector) into the visitor's page
 * language and replaces it in place. There is no button: the module only injects
 * this script when the page language differs from the site's default language,
 * and config.target already holds the page language. Source is auto-detected.
 * If a request fails, the original note is left untouched.
 */
(function () {
    'use strict';

    const cfg = window.wtTranslateNotes;
    if (!cfg || !cfg.selector || !cfg.target) {
        return;
    }

    // Strip anything that could execute when we assign the translated markup with
    // innerHTML. Parsing into a <template> keeps images/scripts inert while we clean.
    function sanitizeHtml(html) {
        const tpl = document.createElement('template');
        tpl.innerHTML = html;

        tpl.content.querySelectorAll('script, style, iframe, object, embed').forEach(function (el) {
            el.remove();
        });

        tpl.content.querySelectorAll('*').forEach(function (el) {
            Array.prototype.slice.call(el.attributes).forEach(function (attr) {
                const name = attr.name.toLowerCase();
                const value = attr.value.replace(/\s+/g, '').toLowerCase();
                const isUrl = name === 'href' || name === 'src' || name === 'xlink:href';

                if (name.startsWith('on') || (isUrl && value.startsWith('javascript:'))) {
                    el.removeAttribute(attr.name);
                }
            });
        });

        return tpl.innerHTML;
    }

    function requestTranslation(html) {
        const body = new URLSearchParams();
        body.set('text', html);
        body.set('target', cfg.target);
        body.set('format', 'html');
        body.set('_csrf', cfg.csrf);

        return fetch(cfg.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': cfg.csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (response) { return response.json(); });
    }

    function translateNode(node) {
        if (node.dataset.wtTranslated) {
            return;
        }
        node.dataset.wtTranslated = '1';

        // Nothing to translate for an empty note.
        if (node.textContent.trim() === '') {
            return;
        }

        const original = node.innerHTML;

        requestTranslation(original)
            .then(function (data) {
                if (!data || data.error || typeof data.translation !== 'string' || data.translation === '') {
                    return; // leave the original note untouched
                }

                node.innerHTML = sanitizeHtml(data.translation);
                node.setAttribute('lang', cfg.target.toLowerCase());
                node.classList.add('wt-tn-translated');
            })
            .catch(function () {
                // Network/parse error - keep the original note.
            });
    }

    document.querySelectorAll(cfg.selector).forEach(translateNode);
})();
