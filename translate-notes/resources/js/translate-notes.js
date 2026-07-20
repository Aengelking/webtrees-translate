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

    if (!cfg || !selectors.length || !cfg.target) {
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
        return bar;
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

    function translateNode(node) {
        if (node.dataset.wtTranslated) {
            return;
        }
        node.dataset.wtTranslated = '1';

        const text = node.textContent.trim();

        // Skip an empty note, or one with no real words (pure numbers, dates or
        // ids). Everything else goes to the engine - we do NOT try to guess the
        // language in the browser.
        if (text === '' || !hasTranslatableText(text)) {
            return;
        }

        const pageLang = primary(cfg.target);
        const original = node.innerHTML;

        post(cfg.endpoint, { text: original, target: cfg.target, format: 'html' })
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

    // Query each selector independently so a syntax error in one does not stop
    // the others; the wtTranslated guard de-duplicates any overlapping matches.
    function scan() {
        selectors.forEach(function (selector) {
            let nodes;
            try {
                nodes = document.querySelectorAll(selector);
            } catch (e) {
                return; // invalid selector - skip it
            }
            nodes.forEach(translateNode);
        });
    }

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
