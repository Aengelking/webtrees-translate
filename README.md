# Translate Notes — webtrees 2.2 custom module

A [webtrees](https://webtrees.net) 2.2 custom module that **automatically
translates note text into the language the visitor is viewing the site in**, and
replaces it in place. There is no button. Notes may be authored in mixed
languages (some German, some English): each note's language is detected in the
browser, and **only notes that are not already in the page language are
translated** — same-language and name/date-only notes are left untouched and cost
no API call.

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
- The front-end detects each note's language (German vs English) and skips notes
  already in the page language. For the rest it sends the note's markup to
  `/module/translate-notes/Translate` with the page language as the target
  (source auto-detected), then replaces the note with the translated markup,
  sanitized before insertion.
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
