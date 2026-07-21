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
- Detection is **fully engine-authoritative** — there is no browser-side
  language guessing at all. An earlier version tried to classify each note in
  the browser (German vs. English word frequencies) and skip notes it thought
  were already in the page language, but that classifier was unreliable and
  sometimes left foreign notes untranslated. Now every note with real text is
  sent to `/module/translate-notes/Translate` with the page language as the
  target, and the engine detects the real source language. If it turns out to be
  the page language after all, the original is kept (no redundant "translation",
  no edit controls). The only notes never sent are those with no real words —
  pure numbers, dates or ids — which nothing could translate. The translated
  markup is sanitized before it replaces the note.
- The trade-off of removing the classifier is cost: the **first** view of each
  note in a given language now always costs one engine call (even for a note
  that turns out to already be in that language), where the classifier could
  sometimes skip it for free. Because results are cached per note and language,
  this is a one-time cost per note — later views are free.
- Results are cached in a `translate_notes_cache` table
  (`sha256(engine | source | target | format | text)`), so the first view of a
  note in a given language costs one API call and later views are free.

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
