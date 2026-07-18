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

## Managing the cache

**Inline, on the front-end (administrators only):** each translated note shows
small **edit** / **delete** links. *Edit* opens the translation in a **rich-text
editor** (it reuses the CKEditor that webtrees already bundles, so there is no
extra dependency); *delete* removes that cached translation (the note reverts to
its original text and is re-translated on the next view). These are visible only
to site administrators, and the endpoints re-check admin rights server-side.

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
