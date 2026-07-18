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
    if (!cfg || !cfg.selector || !cfg.target) {
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
