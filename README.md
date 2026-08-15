# Translate Notes — webtrees 2.2 custom module

A [webtrees](https://webtrees.net) 2.2 custom module that **automatically
translates note text into the language the visitor is viewing the site in**, and
replaces it in place. There is no button. Notes may be authored in mixed
languages (some German, some English): each note is sent to the translation
engine, which **detects the source language itself** and only returns a
translation when the note is not already in the page language. Notes that are
already in the page language are left untouched, and name/date-only notes are
never sent at all.

The module itself lives in [`translate-notes/`](translate-notes/) — that is the
folder you copy into webtrees.

## Translation engines

Pick one in the admin settings:

| Engine | Key needed | Free allowance | HTML formatting | Auto-detect |
| --- | --- | --- | --- | --- |
| **DeepL** *(default)* | Yes | ~500,000 chars/month (free key ends in `:fx`) | ✅ | ✅ |
| **Microsoft Translator** | Yes (Azure) | ~2,000,000 chars/month (F0 tier) | ✅ | ✅ |
| **LibreTranslate** | Usually no | Depends on instance (self-hostable) | ✅ | ✅ |
| **MyMemory** | No | ~50,000 chars/day with an email (per server IP) | ❌ (tags stripped) | ❌ |

Cloud engines (DeepL, Microsoft) send note text to a third party; self-hosted
LibreTranslate keeps genealogical data in-house.

When **DeepL** is selected, the admin settings page shows the **remaining
characters** in your DeepL allowance (used / limit, with a progress bar), read
live from DeepL's usage endpoint. The other engines do not report a live quota,
so the page says so instead.

For **every** engine (including Microsoft, which exposes no usage API at all),
the settings page also shows a **module-side character counter**: the number of
characters this install has sent to the engine in the current calendar month,
with previous months available underneath. It counts only real API calls — cache
hits and same-language notes are not counted — so it is a usage guide rather than
the provider's exact bill, but it gives visibility where the provider gives none.

## Finding out where the characters go

Heavy traffic in non-default languages can burn through an engine quota faster
than expected. **Control panel → Translate Notes → Usage → “Analyse usage”**
opens a read-only report built from the cache that shows exactly what is
consuming characters:

- **Detection-only characters** — notes that turned out to already be in the page
  language (for example an English note on an `EN-US` page, `EN → EN-US`). Without
  a browser-side language guess, the first view of each note costs one detection
  call even when it needs no translation; this is a one-time cost per note, and
  the report shows how much of your total it is.
- **Possible re-translation (churn)** — the same visible note translated more than
  once into the same language. This means the note's markup changes between page
  views (generated ids, “read more” wrappers), so the cache key never matches and
  it is re-translated every time. This is the usual cause of a run-away count, and
  the report lists the most-repeated notes.
- **By source → target language** — where each detection-only pair is flagged.
- **By selector (live counter)** — characters actually sent since the last reset,
  grouped by the CSS selector that matched, so an over-broad selector (an unscoped
  `.wt-fact-value` that catches every fact, or a large `.wt-ai-markdown` block)
  stands out immediately. Resettable, so you can measure a fresh browsing session.
- **Largest cached translations** — the biggest single entries.

Three safeguards also cut needless characters automatically:

- **Overlapping selectors no longer double-translate.** When one matched element
  is nested inside another, only the outer one is sent, so `.faq` together with
  `.faq_title` / `.faq_body` is billed once, not three times.
- **A maximum length** (setting; default 20000 characters, `0` = off). A block
  longer than this is almost never a single note — it is a whole page region
  caught by an over-broad selector — so it is skipped rather than translated.
  Raise it if you have genuinely long notes.
- **Menu labels** are only translated when the visitor's language differs from
  the site default.

The biggest lever, though, is the **selector list itself**: keep it to the notes
you actually want translated (scope `.wt-fact-value` to `.wt-tab-notes`, and drop
anything that matches whole blocks such as recent changes, billboards, the site
title or message lists). An unscoped or block-level selector translates entire
page regions on every view.

## Install

1. Copy the [`translate-notes`](translate-notes/) folder into your webtrees
   `modules_v4/` folder.
2. **Control panel → All modules** — tick **Enabled** for *Translate Notes*.
3. Open its config link, pick an **engine**, and fill in its fields (for DeepL:
   paste the API key; for Microsoft: key + region).
4. Set **Control panel → Website preferences → Default language** correctly —
   notes are assumed to be authored in that language and are only translated when
   a page is viewed in a different one.
5. View a record with a note in a non-default site language (use the language
   menu). The note is translated automatically.

## How it works

- `ModuleGlobalInterface::headContent()` injects a small script whenever the
  engine is configured, passing the current page language as the target.
- Detection is **engine-authoritative by default**. Every note with real text is
  sent to `/module/translate-notes/Translate` with the page language as the
  target, and the engine detects the real source language. If it turns out to be
  the page language after all, the original is kept (no redundant "translation",
  no edit controls). The only notes never sent are those with no real words —
  pure numbers, dates or ids — which nothing could translate. The translated
  markup is sanitized before it replaces the note. (An early version instead
  classified each note in the browser with a German-vs-English word-frequency
  guess; that was unreliable and sometimes left foreign notes untranslated, so it
  was removed.)
- **Optional free on-device detection** (setting, on by default). Sending a note
  just to learn it is already in the page language costs one engine call on its
  first view. When the visitor's browser has the built-in Language Detector API
  (Chrome/Edge), the module uses it first — free and entirely on-device — and
  skips the engine call for a note it *confidently* finds is already in the page
  language (with enough text to be sure). Browsers without the API fall back to
  sending the note to the engine, exactly as before, and an uncertain result also
  falls back. So it only ever saves calls; the only residual risk is that a very
  short foreign note is occasionally left untranslated, which is why it is a
  high-confidence, minimum-length check and can be switched off.
- **Region-insensitive.** webtrees' page language may carry a region (e.g.
  `EN-US`) while an engine reports only the base language (`EN`). Languages are
  compared by their primary subtag, so an English note on an `EN-US` page counts
  as already-in-language: the original is kept, not a reworded `EN → EN-US`
  version. And once a note's source language is known (from any earlier
  translation), later same-language views are served without any API call at all.
- The trade-off of removing the classifier is cost: the **first** view of each
  note in a given language now always costs one engine call (even for a note
  that turns out to already be in that language), where the classifier could
  sometimes skip it for free. Because results are cached per note and language,
  this is a one-time cost per note — later views are free.
- Results are cached in a `translate_notes_cache` table under an
  **engine-independent** key (`sha256(source | target | format | text)`), so the
  first view of a note in a given language costs one API call and later views are
  free. Because the engine is **not** part of the key, switching the translation
  engine reuses the existing translations instead of re-translating everything —
  only notes that have never been translated into that language use the new
  engine. (Upgrading to this scheme re-keys the existing cache in place, so the
  upgrade itself translates nothing.)

## Which text gets translated

The **Note CSS selectors** setting controls what the module translates — **one
selector per line**, so you can cover more than just the Notes tab. The default
is `.wt-tab-notes .wt-fact-value` (notes in the standard themes). To translate
other text (a different note type, a biography field, a custom theme's markup),
inspect the element in your browser's dev tools and add its selector on its own
line. Each selector is queried independently, so a mistake in one line does not
break the others, and per-note language detection still applies to every match.

## Glossary — words that must never be translated

Genealogy is full of surnames and place names that a translation engine will
happily turn into ordinary words: **Taube** becomes "pigeon", **Koch** becomes
"cook", **Jung** becomes "young". The **Glossary — do not translate** setting is
a list of terms (one per line; commas also separate) that are protected from
translation. Matching is whole-word and case-insensitive, so add each spelling
you actually use (e.g. both `Taube` and `Tauben`).

Protection is engine-agnostic: each occurrence is wrapped in
`<span translate="no">…</span>` before the text is sent, which DeepL, Microsoft
and Google all leave untouched in HTML mode; the wrapper is stripped again from
the result. If an engine ignores the marker the term is simply translated as
before — nothing breaks. Changing the glossary only re-translates the cached
notes that actually contain an affected term, so it costs no extra quota for
everything else.

The glossary can be edited two ways: in the admin settings, or **inline on the
front-end** — every translated note shows a small **glossary** button next to
its edit/delete controls, so when you spot a term being mistranslated you can
add it on the spot. The inline editor loads the current list fresh from the
server when it opens (so it is never stale and cannot overwrite a newer
version), is available to the same users as the edit/delete controls, and saving
it re-translates the affected notes right away.

## Turning translation off for a page

Sometimes a whole record should stay in its original language. While editing a
translation (see below), an editor can choose **Do not translate this page** —
from then on every note on that record shows its original text for all visitors.
The page is remembered by its tree + record id, so it survives the record being
renamed. On an excluded page, editors see a small **Enable translation** banner
to switch it back on, and administrators can clear the whole list at once from
the settings page (**Pages excluded from translation → Re-enable all pages**).

## Custom pages — a built-in “Simple Menu”

The module can host your own **menu pages** (an "About us", a chronicle, a
contact page), so you no longer need a separate menu module for them. The point
of building this in is translation: because a page's content is ordinary page
markup that the module already sees, **every custom page is translated
automatically into the visitor's language**, exactly like a note — glossary,
cache and "do not translate" all apply. (The popular *Simple Menu* module
explicitly offers "no different pages per language"; this closes that gap.)

Manage them in **Control panel → Translate Notes → Custom pages**:

- **Add a page** with a *menu label*, an optional *page title*, a *URL slug*
  (generated from the label if left blank, and always made unique), a
  *position*, a *visibility* (everyone / signed-in members / managers) and a
  rich-text *body* (the CKEditor webtrees already bundles).
- All pages appear together in **one main-menu dropdown** whose own label you
  set (default “Menu”). This is one module, so it contributes one top-level menu
  slot — reorder or rename it under **Control panel → Menus** like any other.

### Multiple languages (hybrid: authored + automatic)

Author each page in your **site's default language** on the *Default* tab.
Everything on it — the menu label, the page title and the body — is translated
automatically into whatever language the visitor is viewing the site in, and
cached (menu labels are translated in the visitor's browser without adding edit
controls; page titles and bodies use the same note-translation machinery).

Where you want **exact wording** for a particular language instead of a machine
translation, the page editor has **one tab per site language**: fill in that
language's menu label, title and/or body and it is shown verbatim to visitors in
that language. A language tab you leave blank simply keeps the automatic
translation. So each page is multilingual out of the box, and you upgrade any
language to a hand-written version whenever you want — the two models mix per
page and per field. (webtrees picks the closest match, so an `en-US` override
also serves an `en-GB` visitor.)

**Importing from Simple Menu.** If you already run the JustCarmen *Simple Menu*
module (one or several copies), press **Import from Simple Menu**. It reads each
installed instance's stored page (its menu label, title and body) straight from
the database and creates a matching custom page. It is safe to run more than once
(pages already present are skipped). Afterwards you can disable the Simple Menu
module(s).

## Managing the cache

**Inline, on the front-end:** each translated note shows small **edit** /
**delete** links. *Edit* opens the translation in a **rich-text editor** (it
reuses the CKEditor that webtrees already bundles, so there is no extra
dependency); *delete* removes that cached translation (the note reverts to its
original text and is re-translated on the next view).

Who sees these links is configurable. The **Who can edit or delete
translations** setting picks the minimum role — *Administrator* (the default),
*Manager*, *Moderator*, *Editor* or *Member* — and higher roles always include
the lower ones (choosing *Editor* also lets moderators, managers and
administrators edit). The permission is per family tree, and the endpoints
re-check it server-side, so the links are a real access control, not just hidden
UI. Administrators can always edit and delete regardless of the setting.

**In the admin settings**, the **Manage cached translations** button opens a
paged cache browser. For each entry you can:

- **Edit** the cached translation by hand and save it.
- **Re-translate** the entry, re-running the engine on the original text.
- **Delete** a single entry, so it is re-created the next time the note is viewed.

There is also a **Clear cache** button that empties the whole table. All cache
management actions require administrator rights.

## Formatting & privacy notes

- Formatting (headings, lists, links) is preserved by DeepL, Microsoft and
  LibreTranslate; MyMemory strips tags.
- The first view of each note in a new language makes one API call — heavy
  traffic in non-default languages consumes engine quota faster than an on-demand
  button would.

## Development

The module logic is exercised by a self-contained test harness that runs the
real module files against an in-memory SQLite database with stubbed webtrees
classes and a fake HTTP client — no webtrees install required.

## License

Copyright © 2026 Amos Engelking.

Licensed under the **GNU General Public License v3.0 or later**
(GPL-3.0-or-later), the same license as webtrees itself — see the
[`LICENSE`](LICENSE) file for the full text. As a webtrees plugin this is a
derivative work of webtrees, so it is distributed under GPL-compatible terms.
