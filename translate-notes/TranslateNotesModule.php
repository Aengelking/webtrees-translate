<?php

/**
 * Translate Notes - webtrees 2.2 custom module.
 *
 * Copyright (C) 2026 Amos Engelking
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace TranslateNotes;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\RequestHandlers\ControlPanel;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleLanguageInterface;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Database\Schema\Blueprint;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TranslateNotes\Engines\DeepLEngine;
use TranslateNotes\Engines\LibreTranslateEngine;
use TranslateNotes\Engines\MicrosoftEngine;
use TranslateNotes\Engines\MyMemoryEngine;
use TranslateNotes\Engines\TranslationEngine;

use function e;
use function redirect;
use function response;
use function route;

/**
 * Automatically translate note text into the visitor's page language.
 *
 * On every genealogy page the module injects a small script that translates each
 * note into the current page language and replaces it in place - unless the page
 * is shown in the site's default language, in which case notes are left as
 * authored. Supports multiple translation engines (DeepL, LibreTranslate,
 * MyMemory), selectable in the admin settings.
 *
 * Implements:
 *   - ModuleCustomInterface  (required for all custom modules)
 *   - ModuleConfigInterface  (adds the admin settings page)
 *   - ModuleGlobalInterface  (injects the front-end JS into every genealogy page)
 *   - ModuleMenuInterface    (adds a main-menu dropdown of custom pages, a
 *                             built-in replacement for the "Simple Menu" module,
 *                             whose page content is auto-translated like notes)
 */
class TranslateNotesModule extends AbstractModule implements
    ModuleCustomInterface,
    ModuleConfigInterface,
    ModuleGlobalInterface,
    ModuleMenuInterface
{
    use ModuleCustomTrait;
    use ModuleConfigTrait;
    use ModuleGlobalTrait;
    use ModuleMenuTrait;

    // webtrees 2.2 renders note bodies inside <div class="wt-fact-value"> within
    // the Notes tab (.wt-tab-notes). Scoping to the tab keeps auto-translation off
    // every other fact value on the page. Override in settings if your theme differs.
    private const DEFAULT_SELECTOR = '.wt-tab-notes .wt-fact-value';

    // A matched element whose visible text is longer than this is almost never a
    // single note - it is a whole page region caught by an over-broad selector
    // (a recent-changes block, a full facts panel, a message list). Sending those
    // wastes a lot of characters, so they are skipped. 0 disables the limit.
    private const DEFAULT_MAX_CHARS = 20000;

    // Cache table (webtrees applies its table prefix automatically).
    private const CACHE_TABLE = 'translate_notes_cache';

    // Entries per page in the admin cache manager.
    private const CACHE_PER_PAGE = 25;

    // Bump when the cache table layout OR the cache-key formula changes.
    private const SCHEMA_VERSION = 4;

    // Available engines, in the order shown in the admin dropdown.
    private const ENGINES = [
        DeepLEngine::class,
        MicrosoftEngine::class,
        LibreTranslateEngine::class,
        MyMemoryEngine::class,
    ];

    // DeepL by default: it is the only free engine with a usable quota for
    // genealogy-length notes and the only one that preserves HTML formatting well.
    // A fresh install must add a DeepL key before notes are translated.
    private const DEFAULT_ENGINE = 'deepl';

    // Minimum user role allowed to edit/delete translations from the front-end.
    // Defaults to administrators, preserving the original behaviour. Each key maps
    // to the matching webtrees Auth::is*() role check in mayEditTranslations().
    private const DEFAULT_EDIT_LEVEL = 'admin';
    private const EDIT_LEVELS        = ['admin', 'manager', 'moderator', 'editor', 'member'];

    public function title(): string
    {
        return I18N::translate('Translate Notes');
    }

    public function description(): string
    {
        return I18N::translate('Automatically translates note text into the visitor’s page language, using a translation engine of your choice.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Engelking';
    }

    public function customModuleVersion(): string
    {
        return '0.28.0';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/Aengelking/Webtrees-translate';
    }

    /**
     * Register the module's private view namespace so viewResponse() can find
     * resources/views/settings.phtml.
     */
    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
        $this->updateSchema();
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    /**
     * Create/upgrade the translation cache table. A schema_version preference
     * avoids running hasTable() on every page load.
     *
     * DDL statements (CREATE/DROP TABLE) trigger an implicit COMMIT in
     * MySQL/MariaDB. That silently ends webtrees' per-request transaction and
     * later causes "There is no active transaction" when the framework commits.
     * We work around it by closing the current transaction and opening a fresh
     * one AROUND the DDL - going through the Illuminate connection so its
     * internal transaction counter stays consistent (manipulating the raw PDO
     * would not).
     */
    private function updateSchema(): void
    {
        if ((int) $this->getPreference('schema_version', '0') >= self::SCHEMA_VERSION) {
            return;
        }

        $schema    = DB::schema();
        $installed = (int) $this->getPreference('schema_version', '0');

        if ($installed >= 3 && $schema->hasTable(self::CACHE_TABLE)) {
            // v3 -> v4: only the cache-KEY formula changed (engine dropped); the
            // columns are unchanged. So re-key the existing rows in place with
            // plain DELETE + INSERT - no CREATE/DROP TABLE. This is transaction-
            // safe (no DDL implicit-commit), keeps every translation, and means
            // this upgrade (and every future engine switch) re-translates nothing.
            $this->rekeyCacheEngineIndependent();
        } else {
            // Fresh install, or a pre-v3 layout we cannot migrate: (re)create the
            // table. CREATE/DROP TABLE implicitly COMMIT on MySQL/MariaDB, which
            // would silently end webtrees' per-request transaction and later cause
            // "There is no active transaction". Guard it by closing the current
            // transaction and opening a fresh one around the DDL, through the
            // Illuminate connection so its transaction counter stays consistent.
            $connection = $schema->getConnection();

            if ($connection->transactionLevel() > 0) {
                $connection->commit();
            }

            try {
                $schema->dropIfExists(self::CACHE_TABLE);
                $this->createCacheTable($schema);
            } finally {
                $connection->beginTransaction();
            }
        }

        // Preserve the DeepL setup from earlier (0.1/0.2) installs.
        if ($this->getPreference('engine', '') === '' && $this->getPreference('deepl_api_key', '') !== '') {
            $this->setPreference('engine', 'deepl');
        }

        $this->setPreference('schema_version', (string) self::SCHEMA_VERSION);
    }

    /** Create the cache table with the current layout. */
    private function createCacheTable($schema): void
    {
        $schema->create(self::CACHE_TABLE, static function (Blueprint $table): void {
            $table->string('hash', 64)->primary();  // sha256(source|target|format|text) - engine-independent
            // Denormalised so the admin cache manager can show, edit and
            // re-translate each entry without re-deriving it from the hash.
            $table->string('engine', 20)->nullable();
            $table->string('target_lang', 12)->nullable();
            $table->string('format', 8)->nullable();
            $table->text('source_text')->nullable();
            $table->text('translation');
            $table->string('source_lang', 12)->nullable();
            $table->timestamp('translated_at')->nullable();
        });
    }

    /**
     * Re-key the cache with engine-independent keys, preserving every stored
     * translation. Each row is re-keyed as sha256(source|target|format|text);
     * where two rows (e.g. one per engine) collapse to the same key, the most
     * recently translated one wins. Rows without their source text cannot be
     * re-keyed and are dropped (they re-translate on demand). Uses DELETE +
     * INSERT only - the table structure is unchanged, so no DDL is needed.
     */
    private function rekeyCacheEngineIndependent(): void
    {
        $source = $this->getPreference('source_lang', 'auto');
        $rows   = DB::table(self::CACHE_TABLE)->get();

        $deduped = [];

        foreach ($rows as $row) {
            $text = (string) ($row->source_text ?? '');

            if ($text === '') {
                continue;
            }

            $format = (string) ($row->format ?? 'text') === 'html' ? 'html' : 'text';
            $target = (string) ($row->target_lang ?? '');
            $key    = hash('sha256', $source . '|' . $target . '|' . $format . '|' . $text);

            $prev = $deduped[$key] ?? null;

            if ($prev === null || (string) ($row->translated_at ?? '') >= (string) ($prev['translated_at'] ?? '')) {
                $deduped[$key] = [
                    'hash'          => $key,
                    'engine'        => $row->engine ?? null,
                    'target_lang'   => $target,
                    'format'        => $format,
                    'source_text'   => $text,
                    'translation'   => (string) ($row->translation ?? ''),
                    'source_lang'   => $row->source_lang ?? null,
                    'translated_at' => $row->translated_at ?? null,
                ];
            }
        }

        // Replace the contents in place (no DDL): empty the table, then re-insert
        // the rows under their new keys.
        DB::table(self::CACHE_TABLE)->delete();

        foreach (array_chunk(array_values($deduped), 200) as $chunk) {
            DB::table(self::CACHE_TABLE)->insert($chunk);
        }
    }

    /**
     * @return array<string,string> engine key => label, for the admin dropdown
     */
    private function engineOptions(): array
    {
        $options = [];

        foreach (self::ENGINES as $class) {
            $options[$class::key()] = $class::label();
        }

        return $options;
    }

    private function buildEngine(string $key): TranslationEngine
    {
        switch ($key) {
            case 'deepl':
                return new DeepLEngine(
                    $this->getPreference('deepl_api_key', ''),
                    $this->getPreference('deepl_plan', 'free')
                );

            case 'libretranslate':
                return new LibreTranslateEngine(
                    $this->getPreference('libretranslate_url', ''),
                    $this->getPreference('libretranslate_api_key', '')
                );

            case 'microsoft':
                return new MicrosoftEngine(
                    $this->getPreference('microsoft_api_key', ''),
                    $this->getPreference('microsoft_region', '')
                );

            case 'mymemory':
            default:
                return new MyMemoryEngine($this->getPreference('mymemory_email', ''));
        }
    }

    /**
     * Live character usage for the selected engine, for the admin page. Only
     * DeepL exposes a usage endpoint; the others return a reason instead. Any
     * network/parse failure is reported rather than thrown, so the settings page
     * always renders.
     *
     * @return array{supported:bool,count?:int,limit?:int,remaining?:int,error?:string,reason?:string}
     */
    private function engineUsage(): array
    {
        $engine_key = $this->getPreference('engine', self::DEFAULT_ENGINE);

        if ($engine_key !== 'deepl') {
            return [
                'supported' => false,
                'reason'    => I18N::translate('Remaining characters can only be shown for DeepL. Other engines do not report usage.'),
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'supported' => false,
                'reason'    => I18N::translate('Add a DeepL API key to see the remaining characters.'),
            ];
        }

        try {
            $engine = $this->buildEngine('deepl');
            $usage  = $engine instanceof DeepLEngine ? $engine->usage() : ['count' => 0, 'limit' => 0];
            $limit  = $usage['limit'];
            $count  = $usage['count'];

            return [
                'supported' => true,
                'count'     => $count,
                'limit'     => $limit,
                'remaining' => $limit > 0 ? max(0, $limit - $count) : 0,
            ];
        } catch (\Throwable $exception) {
            return [
                'supported' => false,
                'error'     => $exception->getMessage(),
            ];
        }
    }

    /** Is the currently selected engine ready to use? */
    private function isConfigured(): bool
    {
        switch ($this->getPreference('engine', self::DEFAULT_ENGINE)) {
            case 'deepl':
                return $this->getPreference('deepl_api_key', '') !== '';

            case 'libretranslate':
                return $this->getPreference('libretranslate_url', '') !== '';

            case 'microsoft':
                return $this->getPreference('microsoft_api_key', '') !== '';

            case 'mymemory':
            default:
                return true; // works with no configuration
        }
    }

    /**
     * The configured note selectors as a list. Admins may enter several, one per
     * line (each line may itself be a comma-separated selector list). Blank lines
     * are ignored; falls back to the default when nothing is configured.
     *
     * @return array<string>
     */
    private function noteSelectors(): array
    {
        $raw = trim($this->getPreference('note_selector', self::DEFAULT_SELECTOR));

        if ($raw === '') {
            $raw = self::DEFAULT_SELECTOR;
        }

        $selectors = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $selectors[] = $line;
            }
        }

        return $selectors === [] ? [self::DEFAULT_SELECTOR] : $selectors;
    }

    /**
     * Roles that may be granted edit/delete rights, as key => label. The order
     * runs from most to least privileged; the labels match webtrees' own role
     * names so administrators recognise them.
     *
     * @return array<string,string>
     */
    private function editLevelOptions(): array
    {
        return [
            'admin'     => I18N::translate('Administrator'),
            'manager'   => I18N::translate('Manager'),
            'moderator' => I18N::translate('Moderator'),
            'editor'    => I18N::translate('Editor'),
            'member'    => I18N::translate('Member'),
        ];
    }

    /**
     * The current tree, needed for the per-tree role checks. In an action handler
     * the request is passed in; in headContent() there is none, so we pull the
     * active request from the container. Returns null when there is no tree in
     * context (e.g. a control-panel page), in which case callers fall back to
     * administrators only.
     */
    private function currentTree(?ServerRequestInterface $request = null): ?Tree
    {
        if ($request === null) {
            try {
                $request = Registry::container()->get(ServerRequestInterface::class);
            } catch (\Throwable $exception) {
                return null;
            }
        }

        $tree = $request->getAttribute('tree');

        return $tree instanceof Tree ? $tree : null;
    }

    /**
     * May the current user edit/delete translations? Governed by the configured
     * minimum role. 'admin' needs no tree; the other roles are per-tree, so if no
     * tree is in context we conservatively require an administrator.
     */
    private function mayEditTranslations(?ServerRequestInterface $request = null): bool
    {
        // Administrators may always edit/delete, whatever the configured level.
        if (Auth::isAdmin()) {
            return true;
        }

        $level = $this->getPreference('edit_access_level', self::DEFAULT_EDIT_LEVEL);

        if ($level === 'admin') {
            return false;
        }

        $tree = $this->currentTree($request);

        if ($tree === null) {
            return false; // per-tree role but no tree in context - deny
        }

        switch ($level) {
            case 'manager':
                return Auth::isManager($tree);
            case 'moderator':
                return Auth::isModerator($tree);
            case 'editor':
                return Auth::isEditor($tree);
            case 'member':
                return Auth::isMember($tree);
            default:
                return Auth::isAdmin();
        }
    }

    // ---------------------------------------------------------------------
    // Glossary - terms that must never be translated (e.g. surnames such as
    // "Taube", "Koch", "Jung" that would otherwise become common English words).
    // ---------------------------------------------------------------------

    /**
     * The glossary as a list of distinct terms. Authored one per line (commas
     * also separate), blank entries dropped.
     *
     * @return array<string>
     */
    private function glossaryTerms(): array
    {
        return $this->parseTerms($this->getPreference('glossary_terms', ''));
    }

    /**
     * @return array<string>
     */
    private function parseTerms(string $raw): array
    {
        $terms = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $part) {
            $part = trim($part);

            if ($part !== '') {
                $terms[$part] = true; // key => dedupe, case-sensitively
            }
        }

        return array_keys($terms);
    }

    /**
     * Wrap each glossary term, wherever it appears in the note's TEXT (never
     * inside a tag or attribute), in <span translate="no">…</span>. DeepL,
     * Microsoft and Google all leave translate="no" content unchanged in HTML
     * mode; if an engine ignores it the term is simply translated as before, so
     * nothing breaks. unwrapProtected() removes the markers after translation.
     * Only meaningful for HTML format (plain-text mode would show the tags), so
     * callers pass HTML only.
     *
     * @param array<string> $terms
     */
    private function protectTerms(string $html, array $terms): string
    {
        if ($terms === []) {
            return $html;
        }

        // Longest first so a multi-word term wins over a substring of it.
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $alternation = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $terms));
        // Whole-word match (letters/digits either side block it), case-insensitive,
        // Unicode-aware so umlauts count as letters.
        $regex = '/(?<![\p{L}\p{N}])(' . $alternation . ')(?![\p{L}\p{N}])/iu';

        // Walk the markup: skip <tags>, substitute only in the text between them.
        $result = preg_replace_callback('/<[^>]*>|[^<]+/u', static function (array $match) use ($regex): string {
            $segment = $match[0];

            if ($segment !== '' && $segment[0] === '<') {
                return $segment; // a tag - leave untouched
            }

            return preg_replace($regex, '<span translate="no">$1</span>', $segment) ?? $segment;
        }, $html);

        return $result ?? $html;
    }

    /** Remove the translate="no" wrappers added by protectTerms(), keeping the term. */
    private function unwrapProtected(string $html): string
    {
        return preg_replace('#<span[^>]*\btranslate\s*=\s*(["\']?)no\1[^>]*>(.*?)</span>#isu', '$2', $html) ?? $html;
    }

    /**
     * Drop cached translations whose source text contains any term that was just
     * added to or removed from the glossary, so the change is reflected on the
     * next view. Only affected entries are cleared - untouched notes keep their
     * cached translation (and cost no fresh API call).
     */
    private function invalidateGlossaryCache(string $old, string $new): void
    {
        $terms = array_unique(array_merge($this->parseTerms($old), $this->parseTerms($new)));

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $like = '%' . addcslashes($term, '\\%_') . '%';
            DB::table(self::CACHE_TABLE)->where('source_text', 'like', $like)->delete();
        }
    }

    // ---------------------------------------------------------------------
    // Per-page "do not translate" list. Stored as a JSON array of page keys
    // (see pageKey() in translate-notes.js); the front-end decides whether the
    // page it is on is excluded, so no record lookup is needed server-side.
    // ---------------------------------------------------------------------

    /**
     * @return array<string>
     */
    private function noTranslatePages(): array
    {
        $decoded = json_decode($this->getPreference('no_translate_pages', ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => (string) $v, $decoded),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /** @param array<string> $keys */
    private function setNoTranslatePages(array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $s): bool => $s !== '')));
        $this->setPreference('no_translate_pages', json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ---------------------------------------------------------------------
    // Custom pages - a built-in replacement for the "Simple Menu" module.
    //
    // Pages are shown in a single main-menu dropdown. Because a page body is
    // ordinary page content carrying the wt-tn-page-body class, it is translated
    // automatically by this very module - so, unlike Simple Menu, each page is
    // shown in the visitor's own language (glossary, cache and "do not translate"
    // all apply). Page metadata is kept in one small JSON preference; each page's
    // BODY is stored in its own preference so a long page cannot overflow the
    // shared setting, exactly as Simple Menu stored one body per module.
    // ---------------------------------------------------------------------

    private const PAGE_ACCESS_LEVELS  = ['public', 'members', 'managers'];
    private const DEFAULT_PAGE_ACCESS = 'public';

    /**
     * Page metadata (without the body), sorted by position then menu label.
     *
     * @return array<int,array{id:string,menu:string,slug:string,title:string,position:int,access:string}>
     */
    private function pageIndex(): array
    {
        $decoded = json_decode($this->getPreference('custom_pages', ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        $pages = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) {
                continue;
            }

            $access = (string) ($entry['access'] ?? self::DEFAULT_PAGE_ACCESS);

            $pages[] = [
                'id'       => (string) $entry['id'],
                'menu'     => (string) ($entry['menu'] ?? ''),
                'slug'     => (string) ($entry['slug'] ?? ''),
                'title'    => (string) ($entry['title'] ?? ''),
                'position' => (int) ($entry['position'] ?? 0),
                'access'   => in_array($access, self::PAGE_ACCESS_LEVELS, true) ? $access : self::DEFAULT_PAGE_ACCESS,
                // Per-language overrides for the (short) menu label and title.
                // Bodies are kept in their own per-language preferences.
                'i18n'     => $this->cleanI18n($entry['i18n'] ?? []),
            ];
        }

        usort($pages, static fn (array $a, array $b): int => [$a['position'], $a['menu']] <=> [$b['position'], $b['menu']]);

        return $pages;
    }

    /**
     * Normalise a per-language override map to [langTag => ['menu'=>…,'title'=>…]].
     *
     * @return array<string,array{menu:string,title:string}>
     */
    private function cleanI18n($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $tag => $vals) {
            if (!is_array($vals)) {
                continue;
            }

            $out[(string) $tag] = [
                'menu'  => (string) ($vals['menu'] ?? ''),
                'title' => (string) ($vals['title'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param array<int,array<string,mixed>> $pages */
    private function savePageIndex(array $pages): void
    {
        $clean = [];

        foreach ($pages as $p) {
            $clean[] = [
                'id'       => (string) $p['id'],
                'menu'     => (string) $p['menu'],
                'slug'     => (string) $p['slug'],
                'title'    => (string) $p['title'],
                'position' => (int) $p['position'],
                'access'   => (string) $p['access'],
                'i18n'     => $this->cleanI18n($p['i18n'] ?? []),
            ];
        }

        $this->setPreference('custom_pages', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The HTML body of a page, stored in its own preference. */
    private function pageBody(string $id): string
    {
        return $this->getPreference('page_body_' . $id, '');
    }

    /** @return array<string,mixed>|null */
    private function findPage(string $id): ?array
    {
        foreach ($this->pageIndex() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findPageBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        foreach ($this->pageIndex() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }

        return null;
    }

    /** Turn a label into a URL-safe slug (ASCII words, hyphen-separated). */
    private function slugify(string $text): string
    {
        $text  = (string) preg_replace('/[\'"]+/u', '', $text);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $text); // é -> e, ü -> u, etc.

        if ($ascii !== false) {
            $text = $ascii;
        }

        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text === '' ? 'page' : $text;
    }

    /**
     * A slug not already used by another page (appends -2, -3, ... on collision).
     *
     * @param array<int,array<string,mixed>> $pages
     */
    private function uniqueSlug(string $slug, string $exceptId, array $pages): string
    {
        $slug = $this->slugify($slug);
        $base = $slug;
        $n    = 2;

        $used = static function (string $candidate) use ($pages, $exceptId): bool {
            foreach ($pages as $p) {
                if ($p['id'] !== $exceptId && $p['slug'] === $candidate) {
                    return true;
                }
            }

            return false;
        };

        while ($used($slug)) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /** @param array<int,array<string,mixed>> $pages */
    private function newPageId(array $pages): string
    {
        $max = 0;

        foreach ($pages as $p) {
            $max = max($max, (int) $p['id']);
        }

        return (string) ($max + 1);
    }

    /** May the given page be seen by the current user in this tree? */
    private function pageVisible(array $page, ?Tree $tree): bool
    {
        switch ($page['access']) {
            case 'managers':
                return $tree !== null && Auth::isManager($tree);
            case 'members':
                return $tree !== null && Auth::isMember($tree);
            case 'public':
            default:
                return true;
        }
    }

    /**
     * @return array<int,array<string,mixed>> the pages visible to the current user
     */
    private function visiblePages(?Tree $tree): array
    {
        return array_values(array_filter(
            $this->pageIndex(),
            fn (array $p): bool => $this->pageVisible($p, $tree)
        ));
    }

    /** Label of the main-menu dropdown that lists the custom pages. */
    private function pagesMenuTitle(): string
    {
        $title = trim($this->getPreference('pages_menu_title', ''));

        return $title !== '' ? $title : I18N::translate('Menu');
    }

    private function pageUrl(Tree $tree, string $slug): string
    {
        return route('module', [
            'module' => $this->name(),
            'action' => 'Page',
            'tree'   => $tree->name(),
            'slug'   => $slug,
        ]);
    }

    /**
     * @return array<string,string> access key => label, for the admin dropdown
     */
    private function pageAccessOptions(): array
    {
        return [
            'public'   => I18N::translate('Visible to everyone'),
            'members'  => I18N::translate('Signed-in members'),
            'managers' => I18N::translate('Managers'),
        ];
    }

    /**
     * The site's active languages, as [languageTag => native name], for the
     * per-language page editor. Defensive: if the module service is unavailable
     * (or in a non-web context) it returns an empty list rather than throwing.
     *
     * @return array<string,string>
     */
    private function activeLanguages(): array
    {
        try {
            $service = Registry::container()->get(ModuleService::class);
            $out     = [];

            foreach ($service->findByInterface(ModuleLanguageInterface::class) as $language) {
                $locale = $language->locale();
                $out[$locale->languageTag()] = $locale->endonym();
            }

            asort($out);

            return $out;
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /**
     * Which of a page's authored languages best matches the given page language:
     * an exact tag match first, then a primary-subtag match (so "en-GB" can use
     * an "en-US" override). Returns '' when the page has no override for it.
     */
    private function overrideLangKey(array $page, string $lang): string
    {
        $i18n = $page['i18n'] ?? [];

        if (!is_array($i18n) || $i18n === []) {
            return '';
        }

        $lower = strtolower($lang);

        foreach (array_keys($i18n) as $tag) {
            if (strtolower((string) $tag) === $lower) {
                return (string) $tag;
            }
        }

        $primary = $this->primaryLang($lang);

        foreach (array_keys($i18n) as $tag) {
            if ($this->primaryLang((string) $tag) === $primary) {
                return (string) $tag;
            }
        }

        return '';
    }

    /** The site's default language tag (what the base page content is authored in). */
    private function defaultLanguage(): string
    {
        return Site::getPreference('LANGUAGE', '');
    }

    // ---------------------------------------------------------------------
    // ModuleMenuInterface - one main-menu dropdown listing the custom pages.
    // ---------------------------------------------------------------------

    public function defaultMenuOrder(): int
    {
        return 99; // after the built-in menus; reorder in Control panel > Menus
    }

    public function getMenu(Tree $tree): ?Menu
    {
        $pages = $this->visiblePages($tree);

        if ($pages === []) {
            return null; // no menu at all until at least one page exists
        }

        $page_lang = I18N::languageTag();
        // Only auto-translate labels client-side when the visitor's language
        // differs from the language the labels are authored in (the site default).
        $needs_translation = $this->primaryLang($page_lang) !== $this->primaryLang($this->defaultLanguage());

        $submenus = [];

        foreach ($pages as $page) {
            $key      = $this->overrideLangKey($page, $page_lang);
            $authored = $key !== '' ? (string) ($page['i18n'][$key]['menu'] ?? '') : '';

            if ($authored !== '') {
                // A hand-authored label for this language: use it as-is.
                $label = $authored;
                $class = 'menu-' . $this->name() . '-' . $page['slug'];
            } else {
                // Fall back to the default label; tag it so the front-end script
                // translates it into the visitor's language (unless it already is).
                $label = $page['menu'] !== ''
                    ? $page['menu']
                    : ($page['title'] !== '' ? $page['title'] : $page['slug']);
                $class = 'menu-' . $this->name() . '-' . $page['slug'] . ($needs_translation ? ' wt-tn-menu-label' : '');
            }

            $submenus[] = new Menu($label, $this->pageUrl($tree, $page['slug']), $class);
        }

        return new Menu(
            $this->pagesMenuTitle(),
            '#',
            'menu-' . $this->name(),
            ['rel' => 'nofollow'],
            $submenus
        );
    }

    // ---------------------------------------------------------------------
    // Front-end page view. GET /module/<name>/Page?slug=...&tree=...
    // The body is authored HTML rendered as-is; because it carries the
    // wt-tn-page-body class, this module's own script translates it into the
    // visitor's language just like a note.
    // ---------------------------------------------------------------------

    public function getPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->currentTree($request);
        $slug = Validator::queryParams($request)->string('slug', '');
        $page = $this->findPageBySlug($slug);

        if ($page === null) {
            throw new HttpNotFoundException(I18N::translate('This page does not exist.'));
        }

        if (!$this->pageVisible($page, $tree)) {
            throw new HttpAccessDeniedException();
        }

        $this->layout = 'layouts/default';

        // Prefer a hand-authored version for the visitor's language; otherwise
        // render the base (default-language) content and let the front-end script
        // translate it automatically (the auto-fallback of the hybrid model).
        $page_lang = I18N::languageTag();
        $key       = $this->overrideLangKey($page, $page_lang);

        $title_override = $key !== '' ? (string) ($page['i18n'][$key]['title'] ?? '') : '';
        $body_override  = $key !== '' ? $this->getPreference('page_body_' . $page['id'] . '_' . $key, '') : '';

        $default_title = $page['title'] !== '' ? $page['title'] : $page['menu'];

        return $this->viewResponse($this->name() . '::page', [
            'tree'            => $tree,
            'title'           => $title_override !== '' ? $title_override : $default_title,
            'body'            => $body_override !== '' ? $body_override : $this->pageBody($page['id']),
            // An authored version is already in the visitor's language, so it must
            // NOT be sent to the engine; only the auto-fallback content is.
            'title_translate' => $title_override === '',
            'body_translate'  => $body_override === '',
        ]);
    }

    // ---------------------------------------------------------------------
    // Custom-page management (admin only). Editing/listing lives in the admin
    // settings; each page has its own edit form with a rich-text body.
    // ---------------------------------------------------------------------

    /** Show the add/edit form for a single page. */
    public function getPageEditAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $this->layout = 'layouts/administration';

        $id   = Validator::queryParams($request)->string('id', '');
        $page = $id === '' ? null : $this->findPage($id);

        // Per-language bodies for the editor's prefill (one preference each).
        $lang_bodies = [];
        if ($page !== null) {
            foreach (array_keys($this->activeLanguages()) as $tag) {
                $lang_bodies[$tag] = $this->getPreference('page_body_' . $page['id'] . '_' . $tag, '');
            }
        }

        return $this->viewResponse($this->name() . '::page-edit', [
            'title'         => ($page === null ? I18N::translate('Add a page') : I18N::translate('Edit page')) . ' — ' . $this->title(),
            'module'        => $this->name(),
            'page'          => $page,
            'body'          => $page === null ? '' : $this->pageBody($page['id']),
            'languages'     => $this->activeLanguages(),
            'i18n'          => $page['i18n'] ?? [],
            'lang_bodies'   => $lang_bodies,
            'default_lang'  => $this->defaultLanguage(),
            'access_levels' => $this->pageAccessOptions(),
            'settings_link' => $this->getConfigLink(),
            'control_panel' => route(ControlPanel::class),
        ]);
    }

    /** Create or update a page. */
    public function postPageSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $data     = Validator::parsedBody($request);
        $id       = trim($data->string('id', ''));
        $menu     = trim($data->string('menu', ''));
        $title    = trim($data->string('title', ''));
        $html     = $data->string('body', '');
        $slug_in  = trim($data->string('slug', ''));
        $position = $data->integer('position', 0);
        $access   = $data->string('access', self::DEFAULT_PAGE_ACCESS);

        if (!in_array($access, self::PAGE_ACCESS_LEVELS, true)) {
            $access = self::DEFAULT_PAGE_ACCESS;
        }

        if ($menu === '' && $title === '') {
            FlashMessages::addMessage(I18N::translate('A page needs at least a menu label or a title.'), 'danger');

            return redirect(route('module', ['module' => $this->name(), 'action' => 'PageEdit', 'id' => $id]));
        }

        $pages = $this->pageIndex();

        if ($id === '' || $this->findPage($id) === null) {
            $id = $this->newPageId($pages);
        }

        // Derive the slug from the supplied slug, else the menu label, else title.
        $slug = $this->uniqueSlug($slug_in !== '' ? $slug_in : ($menu !== '' ? $menu : $title), $id, $pages);

        // Per-language overrides. The form lists which languages it offered in a
        // hidden "languages" field; for each we read menu_<tag>/title_<tag> (short,
        // kept in the index) and body_<tag> (stored in its own preference). A
        // language with nothing filled in is dropped, so it auto-translates.
        $i18n      = [];
        $languages = array_filter(array_map('trim', explode(',', $data->string('languages', ''))));

        foreach ($languages as $tag) {
            $l_menu  = trim($data->string('menu_' . $tag, ''));
            $l_title = trim($data->string('title_' . $tag, ''));
            $l_body  = $data->string('body_' . $tag, '');

            if ($l_menu !== '' || $l_title !== '' || $l_body !== '') {
                $i18n[$tag] = ['menu' => $l_menu, 'title' => $l_title];
                $this->setPreference('page_body_' . $id . '_' . $tag, $l_body);
            } else {
                // Nothing authored for this language: clear any previous override.
                $this->setPreference('page_body_' . $id . '_' . $tag, '');
            }
        }

        $entry = [
            'id'       => $id,
            'menu'     => $menu,
            'slug'     => $slug,
            'title'    => $title,
            'position' => $position > 0 ? $position : count($pages) + 1,
            'access'   => $access,
            'i18n'     => $i18n,
        ];

        $found = false;
        foreach ($pages as &$p) {
            if ($p['id'] === $id) {
                $p     = $entry;
                $found = true;
                break;
            }
        }
        unset($p);

        if (!$found) {
            $pages[] = $entry;
        }

        $this->savePageIndex($pages);
        $this->setPreference('page_body_' . $id, $html);

        FlashMessages::addMessage(I18N::translate('The page has been saved.'), 'success');

        return redirect($this->getConfigLink());
    }

    /** Delete a page (metadata + its body preference). */
    public function postPageDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $id    = Validator::parsedBody($request)->string('id', '');
        $pages = array_values(array_filter($this->pageIndex(), static fn (array $p): bool => $p['id'] !== $id));

        $this->savePageIndex($pages);
        $this->setPreference('page_body_' . $id, ''); // clear the (possibly large) body

        FlashMessages::addMessage(I18N::translate('The page has been deleted.'), 'success');

        return redirect($this->getConfigLink());
    }

    /** Import pages from any installed "Simple Menu" module. */
    public function postPagesImportAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $count = $this->importSimpleMenu();

        if ($count > 0) {
            FlashMessages::addMessage(
                I18N::translate('%s pages were imported from Simple Menu. You can now disable the Simple Menu module(s).', I18N::number($count)),
                'success'
            );
        } else {
            FlashMessages::addMessage(I18N::translate('No new Simple Menu pages were found to import.'), 'info');
        }

        return redirect($this->getConfigLink());
    }

    /**
     * Read each installed "Simple Menu" instance straight from the module_setting
     * table (it stores one page as menu-title / page-title / page-body) and create
     * a matching custom page. Pages whose menu label already exists are skipped,
     * so this is safe to run more than once.
     */
    private function importSimpleMenu(): int
    {
        $pages = $this->pageIndex();

        $existing = [];
        foreach ($pages as $p) {
            $existing[mb_strtolower($p['menu'])] = true;
        }

        $bodies   = DB::table('module_setting')->where('setting_name', '=', 'page-body')->get();
        $imported = 0;

        foreach ($bodies as $row) {
            $module = (string) ($row->module_name ?? '');

            if (stripos($module, 'simple-menu') === false) {
                continue; // only Simple Menu instances use these settings
            }

            $body  = (string) ($row->setting_value ?? '');
            $menu  = (string) (DB::table('module_setting')->where('module_name', '=', $module)->where('setting_name', '=', 'menu-title')->first()?->setting_value ?? '');
            $title = (string) (DB::table('module_setting')->where('module_name', '=', $module)->where('setting_name', '=', 'page-title')->first()?->setting_value ?? '');

            if ($menu === '' && $title === '' && $body === '') {
                continue;
            }

            $label = $menu !== '' ? $menu : ($title !== '' ? $title : $module);

            if (isset($existing[mb_strtolower($label)])) {
                continue; // already present
            }

            $id   = $this->newPageId($pages);
            $slug = $this->uniqueSlug($label, '', $pages);

            $pages[] = [
                'id'       => $id,
                'menu'     => $label,
                'slug'     => $slug,
                'title'    => $title !== '' ? $title : $label,
                'position' => count($pages) + 1,
                'access'   => self::DEFAULT_PAGE_ACCESS,
            ];

            $existing[mb_strtolower($label)] = true;
            $this->setPreference('page_body_' . $id, $body);
            $imported++;
        }

        if ($imported > 0) {
            $this->savePageIndex($pages);
        }

        return $imported;
    }

    // ---------------------------------------------------------------------
    // ModuleGlobalInterface - inject front-end assets into every page.
    // ---------------------------------------------------------------------

    public function headContent(): string
    {
        // Nothing to do until the selected engine is ready.
        if (!$this->isConfigured()) {
            return '';
        }

        // Notes are authored in mixed languages (some German, some English). The
        // front-end detects each note's language and only translates the ones that
        // are NOT already in the visitor's page language, so same-language notes
        // cost nothing. The engine still auto-detects the source of what it sends.
        // The configured note selectors, plus the custom-page classes so a custom
        // page's title and body are always translated regardless of that setting.
        $selectors   = $this->noteSelectors();
        $selectors[] = '.wt-tn-page-title';
        $selectors[] = '.wt-tn-page-body';

        $config = [
            'endpoint'    => route('module', ['module' => $this->name(), 'action' => 'Translate']),
            'target'      => strtoupper(I18N::languageTag()),
            'selectors'   => $selectors,
            // Skip an over-large block (a whole page region caught by a broad
            // selector) rather than paying to "translate" it.
            'maxChars'    => (int) $this->getPreference('note_max_chars', (string) self::DEFAULT_MAX_CHARS),
            'csrf'        => Session::getCsrfToken(),
            // Pages the visitor should see untranslated (applies to everyone).
            'noTranslate' => $this->noTranslatePages(),
        ];

        // Users at or above the configured role get inline edit/delete controls on
        // each translated note. The endpoints re-check the same permission
        // server-side, so this flag only decides whether to show the UI.
        if ($this->mayEditTranslations()) {
            $config['canEdit']            = true;
            $config['saveEndpoint']       = route('module', ['module' => $this->name(), 'action' => 'InlineSave']);
            $config['deleteEndpoint']     = route('module', ['module' => $this->name(), 'action' => 'InlineDelete']);
            $config['pageToggleEndpoint'] = route('module', ['module' => $this->name(), 'action' => 'PageToggle']);
            $config['glossaryEndpoint']     = route('module', ['module' => $this->name(), 'action' => 'GlossarySave']);
            // The editor loads the glossary text fresh when it opens (see
            // GlossaryLoad), so the value is never stale and is not embedded in
            // every page.
            $config['glossaryLoadEndpoint'] = route('module', ['module' => $this->name(), 'action' => 'GlossaryLoad']);
            // The controls show the active theme's own edit/delete icons; the text
            // is only a tooltip/aria-label. "Edit" and "Delete" are core webtrees
            // strings, so they are already translated without the module shipping
            // any translation files.
            $config['icons'] = [
                'edit'     => $this->iconHtml('icons/edit', '&#9998;'),       // ✎ fallback
                'del'      => $this->iconHtml('icons/delete', '&#128465;'),   // 🗑 fallback
                'glossary' => $this->iconHtml('icons/preferences', '&#128214;'), // 📖 fallback
            ];
            $config['i18n'] = [
                'edit'    => I18N::translate('Edit'),
                'del'     => I18N::translate('Delete'),
                'save'    => I18N::translate('save'),
                'cancel'  => I18N::translate('cancel'),
                'confirm' => I18N::translate('Remove this cached translation? It will be re-created the next time the note is viewed.'),
                // Per-page "do not translate" controls.
                'noTranslatePage' => I18N::translate('Do not translate this page'),
                'pageConfirm'     => I18N::translate('Turn off translation for this whole page? Every note on it will show its original text.'),
                'pageExcluded'    => I18N::translate('Translation is turned off for this page.'),
                'enablePage'      => I18N::translate('Enable translation'),
                // Inline glossary editor.
                'glossary'     => I18N::translate('Edit glossary'),
                'glossaryHint' => I18N::translate('Words that must never be translated — one per line (for example a surname like “Taube” that would otherwise become “pigeon”).'),
                'loading'      => I18N::translate('Loading…'),
                'loadError'    => I18N::translate('Could not load the glossary.'),
            ];
        }

        return
            self::CONTROL_STYLES .
            '<script>window.wtTranslateNotes = ' . json_encode($config, JSON_UNESCAPED_UNICODE) . ';</script>' .
            '<script src="' . e($this->assetUrl('js/translate-notes.js')) . '" defer></script>';
    }

    // Positions the admin edit/delete controls in the top-right corner of a
    // translated note and keeps them faint until hovered. Injected once per page.
    private const CONTROL_STYLES =
        '<style>' .
        '.wt-tn-translated{position:relative;}' .
        '.wt-tn-admin{position:absolute;top:.15rem;right:.15rem;display:inline-flex;gap:.35rem;' .
        'line-height:1;background:var(--bs-body-bg,#fff);border-radius:.2rem;padding:.1rem .2rem;' .
        'opacity:.45;transition:opacity .15s ease;z-index:2;}' .
        '.wt-tn-translated:hover .wt-tn-admin,.wt-tn-admin:focus-within{opacity:1;}' .
        '.wt-tn-admin a{color:inherit;text-decoration:none;}' .
        '.wt-tn-admin a.wt-tn-delete{color:var(--bs-danger,#dc3545);}' .
        '.wt-tn-admin svg,.wt-tn-admin i{width:1em;height:1em;vertical-align:-.125em;}' .
        '.wt-tn-pagebar{position:fixed;bottom:1rem;right:1rem;z-index:1050;max-width:22rem;' .
        'background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529);' .
        'border:1px solid var(--bs-border-color,#ced4da);border-radius:.3rem;' .
        'padding:.4rem .6rem;font-size:.85rem;box-shadow:0 .2rem .5rem rgba(0,0,0,.15);}' .
        '.wt-tn-pagebar a{margin-left:.4rem;white-space:nowrap;}' .
        '.wt-tn-glossary-panel{position:fixed;bottom:1rem;right:1rem;z-index:1060;width:24rem;' .
        'max-width:calc(100vw - 2rem);background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529);' .
        'border:1px solid var(--bs-border-color,#ced4da);border-radius:.3rem;padding:.6rem .7rem;' .
        'box-shadow:0 .3rem .8rem rgba(0,0,0,.2);}' .
        '.wt-tn-glossary-panel .wt-tn-glossary-title{font-weight:600;margin-bottom:.3rem;}' .
        '</style>';

    /**
     * Render a webtrees icon view (which follows the active theme). Falls back to
     * a plain glyph if the view is unavailable, so a missing/renamed icon view can
     * never break the page <head>.
     */
    private function iconHtml(string $view, string $fallback): string
    {
        try {
            return trim(view($view));
        } catch (\Throwable $exception) {
            return $fallback;
        }
    }

    // ---------------------------------------------------------------------
    // ModuleConfigInterface - admin settings page.
    // Linked automatically from Control panel > Modules.
    // ---------------------------------------------------------------------

    public function getConfigLink(): string
    {
        return route('module', ['module' => $this->name(), 'action' => 'Admin']);
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::settings', [
            'title'            => $this->title(),
            'module'           => $this->name(),
            'engines'          => $this->engineOptions(),
            'engine'           => $this->getPreference('engine', self::DEFAULT_ENGINE),
            'source_lang'      => $this->getPreference('source_lang', 'auto'),
            'deepl_api_key'    => $this->getPreference('deepl_api_key', ''),
            'deepl_plan'       => $this->getPreference('deepl_plan', 'free'),
            'lt_url'           => $this->getPreference('libretranslate_url', ''),
            'lt_key'           => $this->getPreference('libretranslate_api_key', ''),
            'ms_key'           => $this->getPreference('microsoft_api_key', ''),
            'ms_region'        => $this->getPreference('microsoft_region', ''),
            'mm_email'         => $this->getPreference('mymemory_email', ''),
            'note_selector'    => $this->getPreference('note_selector', self::DEFAULT_SELECTOR),
            'note_max_chars'   => (int) $this->getPreference('note_max_chars', (string) self::DEFAULT_MAX_CHARS),
            'edit_levels'      => $this->editLevelOptions(),
            'edit_access_level' => $this->getPreference('edit_access_level', self::DEFAULT_EDIT_LEVEL),
            'glossary_terms'   => $this->getPreference('glossary_terms', ''),
            'pages'            => $this->pageIndex(),
            'pages_menu_title' => $this->getPreference('pages_menu_title', ''),
            'page_access_levels' => $this->pageAccessOptions(),
            'no_translate_count' => count($this->noTranslatePages()),
            'usage'            => $this->engineUsage(),
            'usage_month'      => $this->usageByMonth(),
            'cache_count'      => DB::table(self::CACHE_TABLE)->count(),
            'control_panel'    => route(ControlPanel::class),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        $this->setPreference('engine', $body->string('engine', self::DEFAULT_ENGINE));
        $this->setPreference('source_lang', trim($body->string('source_lang', 'auto')));
        $this->setPreference('deepl_api_key', trim($body->string('deepl_api_key', '')));
        $this->setPreference('deepl_plan', $body->string('deepl_plan', 'free'));
        $this->setPreference('libretranslate_url', trim($body->string('libretranslate_url', '')));
        $this->setPreference('libretranslate_api_key', trim($body->string('libretranslate_api_key', '')));
        $this->setPreference('microsoft_api_key', trim($body->string('microsoft_api_key', '')));
        $this->setPreference('microsoft_region', trim($body->string('microsoft_region', '')));
        $this->setPreference('mymemory_email', trim($body->string('mymemory_email', '')));
        $this->setPreference('note_selector', trim($body->string('note_selector', self::DEFAULT_SELECTOR)));
        $this->setPreference('note_max_chars', (string) max(0, $body->integer('note_max_chars', self::DEFAULT_MAX_CHARS)));
        $this->setPreference('pages_menu_title', trim($body->string('pages_menu_title', '')));

        // Glossary: on change, clear only the cached translations that contain an
        // affected term so the new protection takes effect without a full re-run.
        $old_glossary = $this->getPreference('glossary_terms', '');
        $new_glossary = trim($body->string('glossary_terms', ''));
        $this->setPreference('glossary_terms', $new_glossary);
        if ($new_glossary !== $old_glossary) {
            $this->invalidateGlossaryCache($old_glossary, $new_glossary);
        }

        // Only accept a known role key; fall back to the default otherwise.
        $edit_level = $body->string('edit_access_level', self::DEFAULT_EDIT_LEVEL);
        if (!in_array($edit_level, self::EDIT_LEVELS, true)) {
            $edit_level = self::DEFAULT_EDIT_LEVEL;
        }
        $this->setPreference('edit_access_level', $edit_level);

        FlashMessages::addMessage(
            I18N::translate('The preferences for the module “%s” have been updated.', $this->title()),
            'success'
        );

        return redirect($this->getConfigLink());
    }

    /**
     * Empty the translation cache. Uses delete() rather than truncate() so it
     * works inside webtrees' request transaction.
     */
    public function postClearCacheAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $rows = DB::table(self::CACHE_TABLE)->delete();

        FlashMessages::addMessage(
            I18N::translate('The translation cache has been cleared. %s entries removed.', I18N::number($rows)),
            'success'
        );

        return redirect($this->getConfigLink());
    }

    // ---------------------------------------------------------------------
    // ModuleConfigInterface - cache manager (browse / edit / re-translate).
    // ---------------------------------------------------------------------

    private function cacheLink(int $page = 1): string
    {
        return route('module', ['module' => $this->name(), 'action' => 'Cache', 'page' => $page]);
    }

    /** Browse cached translations, paged, newest first. */
    public function getCacheAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $this->layout = 'layouts/administration';

        $total = DB::table(self::CACHE_TABLE)->count();
        $pages = max(1, (int) ceil($total / self::CACHE_PER_PAGE));
        $page  = min($pages, max(1, Validator::queryParams($request)->integer('page', 1)));

        $rows = DB::table(self::CACHE_TABLE)
            ->orderBy('translated_at', 'desc')
            ->offset(($page - 1) * self::CACHE_PER_PAGE)
            ->limit(self::CACHE_PER_PAGE)
            ->get();

        return $this->viewResponse($this->name() . '::cache', [
            'title'         => I18N::translate('Cached translations') . ' — ' . $this->title(),
            'module'        => $this->name(),
            'rows'          => $rows,
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'settings_link' => $this->getConfigLink(),
            'control_panel' => route(ControlPanel::class),
        ]);
    }

    /** Save an admin-edited translation for a single cache entry. */
    public function postCacheSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $body = Validator::parsedBody($request);
        $hash = $body->string('hash', '');
        $page = max(1, (int) $body->integer('page', 1));

        if ($hash !== '') {
            DB::table(self::CACHE_TABLE)
                ->where('hash', '=', $hash)
                ->update([
                    'translation'   => $body->string('translation', ''),
                    'translated_at' => date('Y-m-d H:i:s'),
                ]);

            FlashMessages::addMessage(I18N::translate('The cached translation has been updated.'), 'success');
        }

        return redirect($this->cacheLink($page));
    }

    /** Re-run the engine for a single cache entry, overwriting its translation. */
    public function postCacheRetranslateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $body = Validator::parsedBody($request);
        $hash = $body->string('hash', '');
        $page = max(1, (int) $body->integer('page', 1));

        $row = $hash === '' ? null : DB::table(self::CACHE_TABLE)->where('hash', '=', $hash)->first();

        if ($row === null || (string) ($row->source_text ?? '') === '') {
            FlashMessages::addMessage(I18N::translate('This entry cannot be re-translated.'), 'danger');

            return redirect($this->cacheLink($page));
        }

        $engine_key = (string) ($row->engine ?? '') ?: $this->getPreference('engine', self::DEFAULT_ENGINE);
        $format     = (string) ($row->format ?? 'text') === 'html' ? 'html' : 'text';
        $source     = $this->getPreference('source_lang', 'auto');

        try {
            $terms       = $format === 'html' ? $this->glossaryTerms() : [];
            $result      = $this->buildEngine($engine_key)->translate(
                $this->protectTerms((string) $row->source_text, $terms),
                (string) ($row->target_lang ?? 'EN'),
                $source,
                $format
            );
            $translation = $terms === [] ? $result['translation'] : $this->unwrapProtected($result['translation']);

            DB::table(self::CACHE_TABLE)
                ->where('hash', '=', $hash)
                ->update([
                    'translation'   => $translation,
                    'source_lang'   => $result['source'],
                    'translated_at' => date('Y-m-d H:i:s'),
                ]);

            $this->recordUsage(mb_strlen((string) $row->source_text), '', (string) ($row->target_lang ?? ''));

            FlashMessages::addMessage(I18N::translate('The entry has been re-translated.'), 'success');
        } catch (\Throwable $exception) {
            FlashMessages::addMessage($exception->getMessage(), 'danger');
        }

        return redirect($this->cacheLink($page));
    }

    /** Delete a single cache entry; it is re-translated on the next page view. */
    public function postCacheDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $body = Validator::parsedBody($request);
        $hash = $body->string('hash', '');
        $page = max(1, (int) $body->integer('page', 1));

        if ($hash !== '') {
            DB::table(self::CACHE_TABLE)->where('hash', '=', $hash)->delete();

            FlashMessages::addMessage(
                I18N::translate('The cached translation has been removed and will be re-created on the next view.'),
                'success'
            );
        }

        return redirect($this->cacheLink($page));
    }

    // ---------------------------------------------------------------------
    // Usage analysis - "where are all the characters going?"
    // ---------------------------------------------------------------------

    /**
     * Aggregate the translation cache to reveal what is driving the character
     * usage: totals, a breakdown by target language, by source→target language
     * pair (flagging "detection-only" pairs whose source is already the page
     * language, e.g. EN → EN-US), likely re-translation churn (the same note
     * translated repeatedly because its markup varies between page loads), and
     * the largest individual entries.
     *
     * @return array<string,mixed>
     */
    private function analyzeCache(): array
    {
        $rows = DB::table(self::CACHE_TABLE)->get();

        $total_entries = 0;
        $total_chars   = 0;
        $by_target     = [];
        $by_pair       = [];
        $same_lang     = ['count' => 0, 'chars' => 0];
        $norm_count    = [];
        $norm_info     = [];
        $churn         = ['rows' => 0, 'chars' => 0];
        $largest       = [];

        foreach ($rows as $row) {
            $src_text = (string) ($row->source_text ?? '');
            $len      = mb_strlen($src_text);
            $target   = (string) ($row->target_lang ?? '');
            $src_lang = (string) ($row->source_lang ?? '');
            $format   = (string) ($row->format ?? 'text');

            $total_entries++;
            $total_chars += $len;

            $by_target[$target] ??= ['count' => 0, 'chars' => 0];
            $by_target[$target]['count']++;
            $by_target[$target]['chars'] += $len;

            $same = $src_lang !== '' && $this->primaryLang($src_lang) === $this->primaryLang($target);

            if ($same) {
                $same_lang['count']++;
                $same_lang['chars'] += $len;
            }

            $pair = ($src_lang !== '' ? $src_lang : '?') . ' → ' . $target;
            $by_pair[$pair] ??= ['count' => 0, 'chars' => 0, 'same' => $same];
            $by_pair[$pair]['count']++;
            $by_pair[$pair]['chars'] += $len;

            // Churn: the same visible text (markup/ids stripped) translated into
            // the same language more than once means the cache is not being
            // reused - usually volatile note markup changing the exact text.
            $normal = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', strip_tags($src_text))));
            $key    = $target . '|' . $format . '|' . $normal;

            if (isset($norm_count[$key])) {
                $churn['rows']++;
                $churn['chars'] += $len;
            } else {
                $norm_info[$key] = mb_substr($normal, 0, 100);
            }
            $norm_count[$key] = ($norm_count[$key] ?? 0) + 1;

            $largest[] = [
                'len'     => $len,
                'target'  => $target,
                'source'  => $src_lang,
                'snippet' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($src_text))), 0, 120),
            ];
        }

        uasort($by_target, static fn (array $a, array $b): int => $b['chars'] <=> $a['chars']);
        uasort($by_pair, static fn (array $a, array $b): int => $b['chars'] <=> $a['chars']);

        // Top churn offenders (same note translated the most times).
        $offenders = [];
        foreach ($norm_count as $key => $count) {
            if ($count > 1) {
                $offenders[] = ['count' => $count, 'snippet' => $norm_info[$key] ?? ''];
            }
        }
        usort($offenders, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $offenders = array_slice($offenders, 0, 10);

        usort($largest, static fn (array $a, array $b): int => $b['len'] <=> $a['len']);
        $largest = array_slice($largest, 0, 20);

        return [
            'total_entries' => $total_entries,
            'total_chars'   => $total_chars,
            'by_target'     => $by_target,
            'by_pair'       => $by_pair,
            'same_lang'     => $same_lang,
            'churn'         => $churn,
            'offenders'     => $offenders,
            'largest'       => $largest,
        ];
    }

    public function getUsageAnalysisAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::usage-analysis', [
            'title'         => I18N::translate('Usage analysis') . ' — ' . $this->title(),
            'module'        => $this->name(),
            'analysis'      => $this->analyzeCache(),
            'by_selector'   => $this->usageBySelector(),
            'by_target_live' => $this->usageByTarget(),
            'settings_link' => $this->getConfigLink(),
            'control_panel' => route(ControlPanel::class),
        ]);
    }

    /** Reset the live per-selector / per-language breakdown counters. */
    public function postResetBreakdownAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $this->setPreference('usage_by_selector', '');
        $this->setPreference('usage_by_target', '');

        FlashMessages::addMessage(I18N::translate('The usage breakdown has been reset.'), 'success');

        return redirect(route('module', ['module' => $this->name(), 'action' => 'UsageAnalysis']));
    }

    // ---------------------------------------------------------------------
    // Translation endpoint - server-side DeepL proxy (keeps the key secret).
    // Reached via POST /module/<name>/Translate
    // ---------------------------------------------------------------------

    /**
     * Add to this server's own running estimate of characters sent to the engine,
     * bucketed by calendar month. Works for every engine (unlike the live DeepL
     * usage endpoint). Only real API calls are recorded here - cache hits and
     * same-language skips make no call and are not counted.
     */
    private function recordUsage(int $chars, string $selector = '', string $target = ''): void
    {
        if ($chars <= 0) {
            return;
        }

        $by_month          = $this->usageByMonth();
        $month             = date('Y-m');
        $by_month[$month]  = ($by_month[$month] ?? 0) + $chars;

        krsort($by_month);
        $by_month = array_slice($by_month, 0, 12, true); // keep the last 12 months

        $this->setPreference('usage_by_month', json_encode($by_month));

        // Breakdown by selector - which CSS selector's content is costing the
        // most characters. Resettable, so an admin can measure a fresh period.
        if ($selector !== '') {
            $by_selector            = $this->usageMap('usage_by_selector');
            $key                    = mb_substr($selector, 0, 200);
            $by_selector[$key]      = ($by_selector[$key] ?? 0) + $chars;
            arsort($by_selector);
            $by_selector            = array_slice($by_selector, 0, 100, true);
            $this->setPreference('usage_by_selector', json_encode($by_selector, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Breakdown by target language.
        if ($target !== '') {
            $by_target          = $this->usageMap('usage_by_target');
            $by_target[$target] = ($by_target[$target] ?? 0) + $chars;
            arsort($by_target);
            $this->setPreference('usage_by_target', json_encode($by_target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Decode a "key => characters" usage preference into an int-valued map.
     *
     * @return array<string,int>
     */
    private function usageMap(string $preference): array
    {
        $decoded = json_decode($this->getPreference($preference, ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $count) {
            $out[(string) $key] = (int) $count;
        }

        return $out;
    }

    /** @return array<string,int> selector => characters sent, biggest first */
    private function usageBySelector(): array
    {
        $map = $this->usageMap('usage_by_selector');
        arsort($map);

        return $map;
    }

    /** @return array<string,int> target language => characters sent, biggest first */
    private function usageByTarget(): array
    {
        $map = $this->usageMap('usage_by_target');
        arsort($map);

        return $map;
    }

    /**
     * @return array<string,int> month "YYYY-MM" => characters sent, newest first
     */
    private function usageByMonth(): array
    {
        $decoded = json_decode($this->getPreference('usage_by_month', ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $month => $count) {
            $out[(string) $month] = (int) $count;
        }
        krsort($out);

        return $out;
    }

    /** Primary language subtag, lower-cased and region-stripped: "EN-US" -> "en". */
    private function primaryLang(string $tag): string
    {
        $tag = strtolower(trim($tag));

        foreach (['-', '_'] as $separator) {
            $pos = strpos($tag, $separator);
            if ($pos !== false) {
                $tag = substr($tag, 0, $pos);
            }
        }

        return $tag;
    }

    public function postTranslateAction(ServerRequestInterface $request): ResponseInterface
    {
        $text   = Validator::parsedBody($request)->string('text', '');
        $target = strtoupper(Validator::parsedBody($request)->string('target', 'EN'));

        // "html" preserves the note's markup (headings, lists, links); anything
        // else is treated as plain text.
        $format = Validator::parsedBody($request)->string('format', 'text') === 'html' ? 'html' : 'text';

        // Which selector matched this text on the page (for the usage analysis).
        $selector = Validator::parsedBody($request)->string('selector', '');

        if ($text === '') {
            return response(['error' => I18N::translate('No text to translate.')], 422);
        }

        if (!$this->isConfigured()) {
            return response(['error' => I18N::translate('This module has not been configured.')], 500);
        }

        $engine_key = $this->getPreference('engine', self::DEFAULT_ENGINE);
        $source     = $this->getPreference('source_lang', 'auto');

        // Cache key is ENGINE-INDEPENDENT: a translation of the same text into the
        // same language is reused whatever engine is selected, so switching the
        // engine does not re-translate everything. It still folds in
        // source + target + format so those changes produce a fresh entry.
        $hash = hash('sha256', $source . '|' . $target . '|' . $format . '|' . $text);

        $cached = DB::table(self::CACHE_TABLE)->where('hash', '=', $hash)->first();

        if ($cached !== null) {
            return response([
                'translation' => $cached->translation,
                'source'      => $cached->source_lang ?? '',
                'cached'      => true,
                'hash'        => $hash,
            ]);
        }

        $primary_target = $this->primaryLang($target);

        // Same-language short-circuit. webtrees' page language can carry a region
        // (e.g. "EN-US") while the engine detects only the base language ("EN"),
        // so an English note on the English site would be pointlessly "translated"
        // EN -> EN-US. If we have translated this exact text before we already know
        // its source language; when that matches the page language (ignoring
        // region) the note is already in the page's language - keep the original
        // and make NO API call. A no-op entry is cached so later views are free.
        $knownSource = '';
        foreach (DB::table(self::CACHE_TABLE)->where('source_text', '=', $text)->get() as $seen) {
            if ((string) ($seen->source_lang ?? '') !== '') {
                $knownSource = (string) $seen->source_lang;
                break;
            }
        }

        if ($knownSource !== '' && $this->primaryLang($knownSource) === $primary_target) {
            DB::table(self::CACHE_TABLE)->updateOrInsert(
                ['hash' => $hash],
                [
                    'engine'        => $engine_key,
                    'target_lang'   => $target,
                    'format'        => $format,
                    'source_text'   => $text,
                    'translation'   => $text,
                    'source_lang'   => $knownSource,
                    'translated_at' => date('Y-m-d H:i:s'),
                ]
            );

            return response([
                'translation' => $text,
                'source'      => $knownSource,
                'cached'      => false,
                'hash'        => $hash,
            ]);
        }

        // Backstop for the client-side size guard: never send an over-large block
        // (a whole page region caught by a broad selector) to the engine. Cached
        // entries were already served above; this only blocks fresh calls.
        $max_chars = (int) $this->getPreference('note_max_chars', (string) self::DEFAULT_MAX_CHARS);

        if ($max_chars > 0 && mb_strlen(trim(strip_tags($text))) > $max_chars) {
            return response(['skipped' => true, 'reason' => 'too-long']);
        }

        try {
            // Protect glossary terms (HTML only) so the engine leaves them as-is,
            // then strip the markers from what it returns.
            $terms       = $format === 'html' ? $this->glossaryTerms() : [];
            $send_text   = $this->protectTerms($text, $terms);
            $result      = $this->buildEngine($engine_key)->translate($send_text, $target, $source, $format);
            $translation = $terms === [] ? $result['translation'] : $this->unwrapProtected($result['translation']);

            // The engine detected the note is already in the page's language (only
            // the region differs, e.g. EN vs EN-US): keep the ORIGINAL rather than
            // a reworded same-language "translation".
            if ($this->primaryLang((string) $result['source']) === $primary_target) {
                $translation = $text;
            }

            // Store the result. updateOrInsert avoids duplicate-key races.
            DB::table(self::CACHE_TABLE)->updateOrInsert(
                ['hash' => $hash],
                [
                    'engine'        => $engine_key,
                    'target_lang'   => $target,
                    'format'        => $format,
                    'source_text'   => $text,
                    'translation'   => $translation,
                    'source_lang'   => $result['source'],
                    'translated_at' => date('Y-m-d H:i:s'),
                ]
            );

            // Count this call toward the module's own usage estimates (monthly
            // total plus the per-selector and per-language breakdowns).
            $this->recordUsage(mb_strlen($text), $selector, $target);

            return response([
                'translation' => $translation,
                'source'      => $result['source'],
                'cached'      => false,
                'hash'        => $hash,
            ]);
        } catch (\Throwable $exception) {
            return response(['error' => $exception->getMessage()], 502);
        }
    }

    // ---------------------------------------------------------------------
    // Inline admin editing from the front-end. JSON responses. Admin only.
    // Reached via POST /module/<name>/InlineSave and /InlineDelete.
    // ---------------------------------------------------------------------

    /** Save an admin-edited translation (from the front-end), by hash. */
    public function postInlineSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mayEditTranslations($request)) {
            return response(['error' => I18N::translate('Access denied.')], 403);
        }

        $body = Validator::parsedBody($request);
        $hash = $body->string('hash', '');

        if ($hash === '') {
            return response(['error' => I18N::translate('No text to translate.')], 422);
        }

        $translation = $body->string('translation', '');

        DB::table(self::CACHE_TABLE)
            ->where('hash', '=', $hash)
            ->update([
                'translation'   => $translation,
                'translated_at' => date('Y-m-d H:i:s'),
            ]);

        return response(['translation' => $translation]);
    }

    /** Delete a single cached translation (from the front-end), by hash. */
    public function postInlineDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mayEditTranslations($request)) {
            return response(['error' => I18N::translate('Access denied.')], 403);
        }

        $hash = Validator::parsedBody($request)->string('hash', '');

        if ($hash !== '') {
            DB::table(self::CACHE_TABLE)->where('hash', '=', $hash)->delete();
        }

        return response(['ok' => true]);
    }

    /**
     * Add or remove the current page from the "do not translate" list. The
     * front-end supplies the page key (see pageKey() in translate-notes.js) and
     * translate=1 to re-enable or translate=0 to exclude. Same permission as the
     * inline editing, re-checked server-side.
     */
    public function postPageToggleAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mayEditTranslations($request)) {
            return response(['error' => I18N::translate('Access denied.')], 403);
        }

        $body = Validator::parsedBody($request);
        $page = trim($body->string('page', ''));

        if ($page === '') {
            return response(['error' => I18N::translate('No text to translate.')], 422);
        }

        $enable = $body->integer('translate', 0) === 1;
        $pages  = $this->noTranslatePages();

        if ($enable) {
            $pages = array_values(array_filter($pages, static fn (string $p): bool => $p !== $page));
        } elseif (!in_array($page, $pages, true)) {
            $pages[] = $page;
        }

        $this->setNoTranslatePages($pages);

        return response(['ok' => true, 'excluded' => !$enable]);
    }

    /** Return the current glossary text for the front-end inline editor. */
    public function getGlossaryLoadAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mayEditTranslations($request)) {
            return response(['error' => I18N::translate('Access denied.')], 403);
        }

        return response(['glossary' => $this->getPreference('glossary_terms', '')]);
    }

    /**
     * Save the glossary from the front-end inline editor. Same permission as the
     * other inline actions; on change it re-translates only the affected cached
     * notes, exactly like the admin settings form.
     */
    public function postGlossarySaveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mayEditTranslations($request)) {
            return response(['error' => I18N::translate('Access denied.')], 403);
        }

        $old = $this->getPreference('glossary_terms', '');
        $new = trim(Validator::parsedBody($request)->string('glossary', ''));

        $this->setPreference('glossary_terms', $new);

        if ($new !== $old) {
            $this->invalidateGlossaryCache($old, $new);
        }

        return response(['ok' => true, 'glossary' => $new]);
    }

    /** Clear the whole "do not translate" list. Admin only. */
    public function postClearNoTranslateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('', 403);
        }

        $this->setNoTranslatePages([]);

        FlashMessages::addMessage(
            I18N::translate('Every page has been re-enabled for translation.'),
            'success'
        );

        return redirect($this->getConfigLink());
    }
}
