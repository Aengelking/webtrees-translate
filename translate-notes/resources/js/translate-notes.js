/**
 * Translate Notes - front-end.
 *
 * Notes are authored in mixed languages. There is deliberately NO browser-side
 * language guessing: a word-frequency classifier proved unreliable and would
 * sometimes leave a foreign note untranslated. Instead every note that contains
 * real text is sent to the engine (config.target is the page language), and the
 * engine detects the source language itself. If it reports the source was the
 * page language after all, the original is kept - no redundant "translation" and
 * no edit controls. The only thing skipped without a call is a note with no
 * real words at all (pure numbers, dates or ids), which nothing can translate.
 * The translated markup replaces the note in place; if a request fails, the
 * original note is kept.
 */
(function () {
    'use strict';

    const cfg = window.wtTranslateNotes;
    const selectors = (cfg && Array.isArray(cfg.selectors) && cfg.selectors.length)
        ? cfg.selectors
        : (cfg && cfg.selector ? [cfg.selector] : []); // backward compatibility

    // "Plain" selectors are chrome elements (the site title, a tagline) that are
    // translated as TEXT only - no markup replacement and no edit controls.
    const plainSelectors = (cfg && Array.isArray(cfg.plainSelectors)) ? cfg.plainSelectors : [];

    if (!cfg || (!selectors.length && !plainSelectors.length) || !cfg.target) {
        return;
    }

    // Primary language subtag, lower-cased: "EN-US" -> "en", "DE" -> "de".
    function primary(tag) {
        return String(tag || '').toLowerCase().split('-')[0];
    }

    // A stable identifier for the current page, used by the "do not translate
    // this page" feature. Prefer the record's tree + XREF (survives slug/name
    // changes); fall back to the path for non-record pages.
    function pageKey() {
        let url = location.pathname + location.search;
        try {
            url = decodeURIComponent(url);
        } catch (e) {
            // malformed %-escape - fall back to the raw URL
        }
        const tree = (url.match(/\/tree\/([^/?#]+)/) || [])[1] || '';
        const rec = url.match(/\/(individual|family|source|repository|note|media|submitter|location)\/([^/?#]+)/);

        if (rec) {
            return 't:' + tree + '/' + rec[1] + '/' + rec[2];
        }
        return 'p:' + location.pathname;
    }

    const noTranslate = (Array.isArray(cfg.noTranslate) ? cfg.noTranslate : []);
    const currentPage = pageKey();
    const pageExcluded = noTranslate.indexOf(currentPage) !== -1;

    // Add (enable=false) or remove (enable=true) the current page from the
    // server's "do not translate" list, then reload so the change is visible.
    function setPageTranslation(enable) {
        return post(cfg.pageToggleEndpoint, { page: currentPage, translate: enable ? 1 : 0 })
            .then(function (d) {
                if (d && d.ok) {
                    location.reload();
                }
            })
            .catch(function () {});
    }

    // On an excluded page, editors still get a small fixed banner to switch
    // translation back on (nothing else on the page is touched).
    function showPageBanner() {
        const bar = document.createElement('div');
        bar.className = 'wt-tn-pagebar';

        const label = document.createElement('span');
        label.textContent = cfg.i18n.pageExcluded;

        const link = document.createElement('a');
        link.href = '#';
        link.textContent = cfg.i18n.enablePage;
        link.addEventListener('click', function (e) {
            e.preventDefault();
            setPageTranslation(true);
        });

        bar.appendChild(label);
        bar.appendChild(link);
        (document.body || document.documentElement).appendChild(bar);
    }

    // Does the note contain any real word worth sending to the engine, as
    // opposed to only numbers, dates, ids or punctuation (e.g. "1854-1888",
    // "23/5231!")? This is purely a cost gate to avoid calling the engine on a
    // note that nothing could translate - it is NOT language detection and makes
    // no guess about which language the text is in; that is the engine's job.
    // Script-agnostic: any run of >= 3 letters in any alphabet (Latin, Greek,
    // Cyrillic, ...) counts, so a single capitalised word such as a profession
    // ("Sekretärin") still qualifies and is translated.
    function hasTranslatableText(text) {
        return /\p{L}{3,}/u.test(text);
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

    function post(endpoint, params) {
        const body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.set(k, params[k]); });
        body.set('_csrf', cfg.csrf);

        return fetch(endpoint, {
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

    function getJson(endpoint) {
        return fetch(endpoint, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) { return response.json(); });
    }

    // Show the translated markup and, for admins, the edit/delete controls.
    function showTranslation(node, translationHtml, hash, original) {
        node.innerHTML = sanitizeHtml(translationHtml);
        node.setAttribute('lang', primary(cfg.target));
        node.classList.add('wt-tn-translated');

        if (cfg.canEdit && hash) {
            node.appendChild(adminBar(node, hash, translationHtml, original));
        }
    }

    // A single icon control. The icon markup comes from the server (the active
    // webtrees theme's own icon view), so assigning it with innerHTML is safe;
    // it falls back to the label text if no icon was provided. The label is shown
    // as a tooltip and exposed to assistive tech.
    function iconLink(className, iconHtml, label, onClick) {
        const a = document.createElement('a');
        a.href = '#';
        a.className = className;
        a.innerHTML = iconHtml || label;
        a.title = label;
        a.setAttribute('aria-label', label);
        a.addEventListener('click', function (e) {
            e.preventDefault();
            onClick();
        });
        return a;
    }

    function adminBar(node, hash, translationHtml, original) {
        const bar = document.createElement('div');
        bar.className = 'wt-tn-admin';

        const icons = cfg.icons || {};

        const edit = iconLink('wt-tn-edit', icons.edit, cfg.i18n.edit, function () {
            startEdit(node, hash, translationHtml, original);
        });

        const del = iconLink('wt-tn-delete', icons.del, cfg.i18n.del, function () {
            if (!window.confirm(cfg.i18n.confirm)) {
                return;
            }
            post(cfg.deleteEndpoint, { hash: hash }).then(function (d) {
                if (d && d.ok) {
                    node.innerHTML = original; // revert to the untranslated note
                }
            }).catch(function () {});
        });

        bar.appendChild(edit);
        bar.appendChild(del);

        // Third control: edit the (global) glossary of protected terms, right
        // where a bad translation is noticed. Available to the same users.
        if (cfg.glossaryEndpoint) {
            bar.appendChild(iconLink('wt-tn-glossary', icons.glossary, cfg.i18n.glossary, openGlossaryEditor));
        }

        return bar;
    }

    // A small fixed panel to edit the glossary inline. It is global (not tied to
    // this note), so on save we reload the page to re-translate with the new
    // terms. Only one panel at a time.
    let glossaryOpen = false;

    function openGlossaryEditor() {
        if (glossaryOpen) {
            return;
        }
        glossaryOpen = true;

        const panel = document.createElement('div');
        panel.className = 'wt-tn-glossary-panel';

        const title = document.createElement('div');
        title.className = 'wt-tn-glossary-title';
        title.textContent = cfg.i18n.glossary;

        const area = document.createElement('textarea');
        area.className = 'form-control form-control-sm';
        area.rows = 8;
        // Load the current glossary fresh from the server so it is never stale
        // and cannot overwrite a newer version. Disabled until it arrives.
        area.value = '';
        area.disabled = true;

        const hint = document.createElement('div');
        hint.className = 'form-text';
        hint.textContent = cfg.i18n.loading;

        const save = document.createElement('button');
        save.type = 'button';
        save.className = 'btn btn-primary btn-sm mt-2 me-2';
        save.textContent = cfg.i18n.save;
        save.disabled = true; // enabled only once the glossary has loaded

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-link btn-sm mt-2';
        cancel.textContent = cfg.i18n.cancel;

        function close() {
            glossaryOpen = false;
            panel.remove();
        }

        cancel.addEventListener('click', close);

        getJson(cfg.glossaryLoadEndpoint).then(function (d) {
            if (d && typeof d.glossary === 'string') {
                area.value = d.glossary;
                area.disabled = false;
                save.disabled = false;
                hint.textContent = cfg.i18n.glossaryHint;
                area.focus();
            } else {
                // Keep save disabled so a failed load can't clobber the glossary.
                hint.textContent = (d && d.error) ? d.error : cfg.i18n.loadError;
            }
        }).catch(function () {
            hint.textContent = cfg.i18n.loadError;
        });

        save.addEventListener('click', function () {
            save.disabled = true;
            post(cfg.glossaryEndpoint, { glossary: area.value }).then(function (d) {
                if (d && d.ok) {
                    location.reload(); // re-translate affected notes
                } else {
                    save.disabled = false;
                    if (d && d.error) { window.alert(d.error); }
                }
            }).catch(function () {
                save.disabled = false;
            });
        });

        panel.appendChild(title);
        panel.appendChild(area);
        panel.appendChild(hint);
        panel.appendChild(save);
        panel.appendChild(cancel);
        (document.body || document.documentElement).appendChild(panel);
    }

    // Turn a textarea into a rich-text editor by reusing the CKEditor that
    // webtrees already bundles (it auto-attaches to textarea.html-edit at page
    // load, but our textarea is created later, so we attach it manually - loading
    // CKEditor from webtrees' own path if the page hasn't loaded it yet). Falls
    // back to the plain textarea if CKEditor is unavailable.
    let editorSeq = 0;

    function makeEditor(container, initialHtml) {
        const id = 'wt-tn-edit-' + (++editorSeq);

        const area = document.createElement('textarea');
        area.id = id;
        area.className = 'form-control form-control-sm html-edit';
        area.rows = 6;
        area.value = initialHtml;
        container.appendChild(area);

        function attach() {
            if (window.CKEDITOR && !window.CKEDITOR.instances[id]) {
                window.CKEDITOR.replace(id);
            }
        }

        if (window.CKEDITOR) {
            attach();
        } else if (typeof CKEDITOR_BASEPATH !== 'undefined') {
            let script = document.getElementById('wt-tn-ckeditor-js');
            if (!script) {
                script = document.createElement('script');
                script.id = 'wt-tn-ckeditor-js';
                script.src = CKEDITOR_BASEPATH + 'ckeditor.js';
                document.head.appendChild(script);
            }
            script.addEventListener('load', attach);
            // In case it is already loading/loaded, poll briefly too.
            const timer = setInterval(function () {
                if (window.CKEDITOR) { clearInterval(timer); attach(); }
            }, 100);
            setTimeout(function () { clearInterval(timer); }, 5000);
        } else {
            area.classList.add('font-monospace'); // no editor - show raw HTML
        }

        return {
            getData: function () {
                const instance = window.CKEDITOR && window.CKEDITOR.instances[id];
                return instance ? instance.getData() : area.value;
            },
            destroy: function () {
                const instance = window.CKEDITOR && window.CKEDITOR.instances[id];
                if (instance) {
                    instance.destroy(true);
                }
            },
            focus: function () { area.focus(); }
        };
    }

    function startEdit(node, hash, translationHtml, original) {
        node.innerHTML = '';
        const editor = makeEditor(node, translationHtml);

        const save = document.createElement('button');
        save.type = 'button';
        save.className = 'btn btn-primary btn-sm mt-2 me-2';
        save.textContent = cfg.i18n.save;

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-link btn-sm mt-2';
        cancel.textContent = cfg.i18n.cancel;

        node.appendChild(save);
        node.appendChild(cancel);

        // "Do not translate this page" - turns translation off for the whole
        // record, not just this note (the note being edited is the natural place
        // to offer it). Reloads afterwards so every note reverts to its original.
        if (cfg.pageToggleEndpoint) {
            const stop = document.createElement('button');
            stop.type = 'button';
            stop.className = 'btn btn-link btn-sm mt-2 text-danger';
            stop.textContent = cfg.i18n.noTranslatePage;
            node.appendChild(stop);

            stop.addEventListener('click', function () {
                if (!window.confirm(cfg.i18n.pageConfirm)) {
                    return;
                }
                editor.destroy();
                setPageTranslation(false);
            });
        }

        editor.focus();

        cancel.addEventListener('click', function () {
            editor.destroy();
            showTranslation(node, translationHtml, hash, original);
        });

        save.addEventListener('click', function () {
            save.disabled = true;
            const value = editor.getData();
            post(cfg.saveEndpoint, { hash: hash, translation: value }).then(function (d) {
                editor.destroy();
                if (d && typeof d.translation === 'string') {
                    showTranslation(node, d.translation, hash, original);
                } else {
                    showTranslation(node, translationHtml, hash, original);
                    if (d && d.error) { window.alert(d.error); }
                }
            }).catch(function () {
                save.disabled = false;
            });
        });
    }

    // --- Optional free, on-device source-language detection ------------------
    // Uses the browser's built-in Language Detector API (Chrome/Edge) when it is
    // present. It is ONLY ever used to skip a paid engine call when we are highly
    // confident the note is already in the page language; when the API is missing
    // or unsure, the note is sent to the engine exactly as before. So it can only
    // SAVE calls. (A wrong "already translated" *display* is impossible: the
    // server still keeps the original for any same-language note it does receive.)
    const DETECT_MIN_CHARS = 40;       // too little text is unreliable
    const DETECT_MIN_CONFIDENCE = 0.75; // only trust a confident result

    let detectorPromise; // created lazily, reused

    function getDetector() {
        if (detectorPromise !== undefined) {
            return detectorPromise;
        }

        detectorPromise = (function () {
            try {
                if (self.LanguageDetector && typeof self.LanguageDetector.create === 'function') {
                    const LD = self.LanguageDetector;
                    if (typeof LD.availability === 'function') {
                        return Promise.resolve(LD.availability())
                            .then(function (state) { return state === 'unavailable' ? null : LD.create(); });
                    }
                    return Promise.resolve(LD.create());
                }
                // Older experimental shape.
                if (self.translation && typeof self.translation.createDetector === 'function') {
                    return Promise.resolve(self.translation.createDetector());
                }
            } catch (e) {
                // fall through
            }
            return Promise.resolve(null);
        })().catch(function () { return null; });

        return detectorPromise;
    }

    // Resolves to the confidently-detected primary language, or null when the
    // detector is unavailable, the text is too short, or the result is uncertain.
    function detectPageLanguageMatch(text, pageLang) {
        if (!cfg.localDetect || text.length < DETECT_MIN_CHARS) {
            return Promise.resolve(false);
        }

        return getDetector().then(function (detector) {
            if (!detector) {
                return false;
            }
            return Promise.resolve(detector.detect(text)).then(function (results) {
                if (!results || !results.length) {
                    return false;
                }
                const top = results[0];
                return primary(top.detectedLanguage) === pageLang
                    && (top.confidence || 0) >= DETECT_MIN_CONFIDENCE;
            });
        }).catch(function () {
            return false;
        });
    }

    // Send the note to the engine and, if it comes back translated, swap it in.
    function sendToEngine(node, selector, original, pageLang) {
        // Report which selector matched, so the admin usage analysis can show
        // where the characters are going.
        post(cfg.endpoint, { text: original, target: cfg.target, format: 'html', selector: selector || '' })
            .then(function (data) {
                if (!data || data.error || typeof data.translation !== 'string' || data.translation === '') {
                    return; // leave the original note untouched
                }
                // The engine reports the source language it detected. If it turns
                // out to be the page language after all, the note was already in
                // the right language - keep the original and show no redundant
                // "translation" (and no edit controls).
                if (data.source && primary(data.source) === pageLang) {
                    return;
                }
                showTranslation(node, data.translation, data.hash, original);
            })
            .catch(function () {
                // Network/parse error - keep the original note.
            });
    }

    function translateNode(node, selector) {
        if (node.dataset.wtTranslated) {
            return;
        }
        node.dataset.wtTranslated = '1';

        const text = node.textContent.trim();

        // Skip an empty note, or one with no real words (pure numbers, dates or
        // ids). Everything else is a candidate for translation.
        if (text === '' || !hasTranslatableText(text)) {
            return;
        }

        // Skip a block that is far too large to be a single note: it is almost
        // always a whole page region caught by an over-broad selector, and it
        // wastes a lot of characters. Configurable; 0 disables the limit.
        if (cfg.maxChars > 0 && text.length > cfg.maxChars) {
            return;
        }

        const pageLang = primary(cfg.target);
        const original = node.innerHTML;

        // Try the free on-device detector first; only when it is confident the
        // note is already in the page language do we skip the engine call.
        detectPageLanguageMatch(text, pageLang).then(function (alreadyInLanguage) {
            if (alreadyInLanguage) {
                return; // no paid call needed
            }
            sendToEngine(node, selector, original, pageLang);
        });
    }

    // The innermost element that actually holds the text, so we can replace the
    // text while keeping any wrapping link/span (and its href/attributes). E.g.
    // <div class="wt-site-title"><a href="/">Title</a></div> -> the <a>.
    function textHost(el) {
        let cur = el;
        while (cur.children.length === 1 && cur.childNodes.length === 1) {
            cur = cur.children[0];
        }
        return cur;
    }

    // Translate a "plain" element (site title, menu label, tagline) as TEXT only:
    // no markup replacement, no edit controls. Safe for site chrome and preserves
    // any inner link. Uses the same free on-device detection and caching as notes.
    function translatePlainNode(el, selector) {
        if (el.dataset.wtTranslated) {
            return;
        }
        el.dataset.wtTranslated = '1';

        const text = el.textContent.trim();

        if (text === '' || !hasTranslatableText(text)) {
            return;
        }
        if (cfg.maxChars > 0 && text.length > cfg.maxChars) {
            return;
        }

        const pageLang = primary(cfg.target);

        detectPageLanguageMatch(text, pageLang).then(function (alreadyInLanguage) {
            if (alreadyInLanguage) {
                return; // free skip - already in the page language
            }

            post(cfg.endpoint, { text: text, target: cfg.target, format: 'text', selector: selector })
                .then(function (data) {
                    if (!data || data.error || typeof data.translation !== 'string' || data.translation === '') {
                        return; // leave the original text
                    }
                    if (data.source && primary(data.source) === pageLang) {
                        return; // already in the page language after all
                    }
                    textHost(el).textContent = data.translation;
                })
                .catch(function () {});
        });
    }

    // Custom-page menu labels (see getMenu) plus any admin-configured chrome
    // selectors (e.g. the site title). Both are translated text-only. Menu labels
    // are only tagged by the server when the page language differs from the one
    // they were authored in. Runs for every visitor, not just editors.
    function translatePlain() {
        document.querySelectorAll('a.wt-tn-menu-label').forEach(function (a) {
            translatePlainNode(a, ':menu-label');
        });

        plainSelectors.forEach(function (selector) {
            let nodes;
            try {
                nodes = document.querySelectorAll(selector);
            } catch (e) {
                return; // invalid selector - skip it
            }
            nodes.forEach(function (el) { translatePlainNode(el, selector); });
        });
    }

    // Collect every matched node (deduped), remembering the first selector that
    // matched it. A syntax error in one selector does not stop the others.
    function scan() {
        translatePlain();

        const matched = new Map(); // node -> selector that first matched it

        selectors.forEach(function (selector) {
            let nodes;
            try {
                nodes = document.querySelectorAll(selector);
            } catch (e) {
                return; // invalid selector - skip it
            }
            nodes.forEach(function (node) {
                if (!matched.has(node)) {
                    matched.set(node, selector);
                }
            });
        });

        // Translate each matched node UNLESS an ancestor is also matched:
        // translating the outer node already covers this text, and sending both
        // would translate (and bill) the same characters twice or three times.
        // This is the usual cause of run-away character counts when selectors
        // overlap (e.g. ".faq" together with ".faq_title" and ".faq_body").
        matched.forEach(function (selector, node) {
            let parent = node.parentElement;
            while (parent) {
                if (matched.has(parent)) {
                    node.dataset.wtTranslated = '1'; // covered by the ancestor
                    return;
                }
                parent = parent.parentElement;
            }
            translateNode(node, selector);
        });
    }

    // Menu labels and the site title are part of the site chrome, not the record,
    // so they are translated even on a page whose notes are excluded.
    translatePlain();

    // This page is on the "do not translate" list: leave every note as authored.
    // Editors still get a banner to switch translation back on.
    if (pageExcluded) {
        if (cfg.canEdit && cfg.pageToggleEndpoint && cfg.i18n) {
            if (document.body) {
                showPageBanner();
            } else {
                document.addEventListener('DOMContentLoaded', showPageBanner);
            }
        }
        return;
    }

    scan();

    // webtrees builds some note markup (e.g. the .wt-fact-notes "read more"
    // blocks on the facts tab, and content in tabs loaded by AJAX) with
    // JavaScript AFTER this script has run, so a one-shot scan on load misses
    // it. Re-scan whenever the DOM changes. The wtTranslated guard means notes
    // already handled are skipped, and our own innerHTML writes do not cause
    // re-translation; a short debounce coalesces bursts of mutations.
    if (window.MutationObserver && document.body) {
        let scheduled = false;
        const observer = new MutationObserver(function () {
            if (scheduled) {
                return;
            }
            scheduled = true;
            window.setTimeout(function () {
                scheduled = false;
                scan();
            }, 200);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
