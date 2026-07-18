/**
 * Translate Notes - front-end.
 *
 * Notes are authored in mixed languages (some German, some English). For each
 * note (config.selector) this detects its language and, only if it differs from
 * the visitor's page language (config.target), translates it and replaces it in
 * place. Notes already in the page language - and language-neutral ones such as
 * names/dates - are left untouched, so they cost no API call. There is no button;
 * the source is auto-detected. If a request fails, the original note is kept.
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

    // Lightweight German-vs-English classifier for note-length prose. Returns
    // 'de', 'en', or '' (unknown / language-neutral, e.g. only names and dates).
    // Used to skip notes that are already in the page language.
    const DE_WORDS = [' der ', ' die ', ' das ', ' und ', ' ist ', ' war ', ' den ', ' dem ',
        ' ein ', ' eine ', ' nicht ', ' mit ', ' von ', ' auch ', ' auf ', ' als ', ' aus ',
        ' bei ', ' nach ', ' wurde ', ' wurden ', ' sich ', ' im ', ' zum ', ' zur ', ' sie ', ' er '];
    const EN_WORDS = [' the ', ' and ', ' of ', ' to ', ' in ', ' is ', ' was ', ' were ', ' a ',
        ' an ', ' for ', ' with ', ' on ', ' at ', ' by ', ' from ', ' as ', ' that ', ' this ',
        ' his ', ' her ', ' their ', ' which ', ' he ', ' she '];

    function countWords(haystack, words) {
        return words.reduce(function (sum, w) {
            let n = 0;
            let i = haystack.indexOf(w);
            while (i !== -1) {
                n++;
                i = haystack.indexOf(w, i + 1);
            }
            return sum + n;
        }, 0);
    }

    function detectLang(text) {
        const t = ' ' + text.toLowerCase().replace(/\s+/g, ' ') + ' ';
        if (t.trim() === '') {
            return '';
        }

        let de = countWords(t, DE_WORDS);
        let en = countWords(t, EN_WORDS);

        // Umlauts / eszett are a strong German signal.
        if (/[äöüß]/.test(t)) {
            de += 3;
        }

        if (de >= 2 && de >= en + 2) {
            return 'de';
        }
        if (en >= 2 && en >= de + 2) {
            return 'en';
        }
        return '';
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

    function adminBar(node, hash, translationHtml, original) {
        const bar = document.createElement('div');
        bar.className = 'wt-tn-admin small text-muted mt-1';

        const edit = document.createElement('a');
        edit.href = '#';
        edit.className = 'me-2 wt-tn-edit';
        edit.textContent = cfg.i18n.edit;
        edit.addEventListener('click', function (e) {
            e.preventDefault();
            startEdit(node, hash, translationHtml, original);
        });

        const del = document.createElement('a');
        del.href = '#';
        del.className = 'text-danger wt-tn-delete';
        del.textContent = cfg.i18n.del;
        del.addEventListener('click', function (e) {
            e.preventDefault();
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

        // Nothing to translate for an empty note.
        if (text === '') {
            return;
        }

        // Notes are in mixed languages. Only translate a note that is confidently
        // in a language OTHER than the page language; leave same-language and
        // language-neutral (name/date-only) notes untouched - no API call.
        const lang = detectLang(text);
        if (lang === '' || lang === primary(cfg.target)) {
            return;
        }

        const original = node.innerHTML;

        post(cfg.endpoint, { text: original, target: cfg.target, format: 'html' })
            .then(function (data) {
                if (!data || data.error || typeof data.translation !== 'string' || data.translation === '') {
                    return; // leave the original note untouched
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
