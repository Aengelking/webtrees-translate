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
use Fisharebest\Webtrees\Http\RequestHandlers\ControlPanel;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
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
 */
class TranslateNotesModule extends AbstractModule implements
    ModuleCustomInterface,
    ModuleConfigInterface,
    ModuleGlobalInterface
{
    use ModuleCustomTrait;
    use ModuleConfigTrait;
    use ModuleGlobalTrait;

    // webtrees 2.2 renders note bodies inside <div class="wt-fact-value"> within
    // the Notes tab (.wt-tab-notes). Scoping to the tab keeps auto-translation off
    // every other fact value on the page. Override in settings if your theme differs.
    private const DEFAULT_SELECTOR = '.wt-tab-notes .wt-fact-value';

    // Cache table (webtrees applies its table prefix automatically).
    private const CACHE_TABLE = 'translate_notes_cache';

    // Entries per page in the admin cache manager.
    private const CACHE_PER_PAGE = 25;

    // Bump when the cache table layout changes.
    private const SCHEMA_VERSION = 3;

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
        return '0.19.0';
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

        $schema     = DB::schema();
        $connection = $schema->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        try {
            // The cache key now folds in engine + source + target, so any older
            // table layout is obsolete. The cache is disposable - recreate it.
            $schema->dropIfExists(self::CACHE_TABLE);

            $schema->create(self::CACHE_TABLE, static function (Blueprint $table): void {
                $table->string('hash', 64)->primary();  // sha256(engine|source|target|format|text)
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
        } finally {
            // Re-open a transaction for webtrees' middleware to commit, even if
            // the DDL above failed.
            $connection->beginTransaction();
        }

        // Preserve the DeepL setup from earlier (0.1/0.2) installs.
        if ($this->getPreference('engine', '') === '' && $this->getPreference('deepl_api_key', '') !== '') {
            $this->setPreference('engine', 'deepl');
        }

        $this->setPreference('schema_version', (string) self::SCHEMA_VERSION);
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
        $config = [
            'endpoint'    => route('module', ['module' => $this->name(), 'action' => 'Translate']),
            'target'      => strtoupper(I18N::languageTag()),
            'selectors'   => $this->noteSelectors(),
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
            // The controls show the active theme's own edit/delete icons; the text
            // is only a tooltip/aria-label. "Edit" and "Delete" are core webtrees
            // strings, so they are already translated without the module shipping
            // any translation files.
            $config['icons'] = [
                'edit' => $this->iconHtml('icons/edit', '&#9998;'),   // ✎ fallback
                'del'  => $this->iconHtml('icons/delete', '&#128465;'), // 🗑 fallback
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
            'edit_levels'      => $this->editLevelOptions(),
            'edit_access_level' => $this->getPreference('edit_access_level', self::DEFAULT_EDIT_LEVEL),
            'glossary_terms'   => $this->getPreference('glossary_terms', ''),
            'no_translate_count' => count($this->noTranslatePages()),
            'usage'            => $this->engineUsage(),
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
    // Translation endpoint - server-side DeepL proxy (keeps the key secret).
    // Reached via POST /module/<name>/Translate
    // ---------------------------------------------------------------------

    public function postTranslateAction(ServerRequestInterface $request): ResponseInterface
    {
        $text   = Validator::parsedBody($request)->string('text', '');
        $target = strtoupper(Validator::parsedBody($request)->string('target', 'EN'));

        // "html" preserves the note's markup (headings, lists, links); anything
        // else is treated as plain text.
        $format = Validator::parsedBody($request)->string('format', 'text') === 'html' ? 'html' : 'text';

        if ($text === '') {
            return response(['error' => I18N::translate('No text to translate.')], 422);
        }

        if (!$this->isConfigured()) {
            return response(['error' => I18N::translate('This module has not been configured.')], 500);
        }

        $engine_key = $this->getPreference('engine', self::DEFAULT_ENGINE);
        $source     = $this->getPreference('source_lang', 'auto');

        // Cache key folds in engine + source + target + format so switching any of
        // them produces a fresh translation rather than a stale hit.
        $hash = hash('sha256', $engine_key . '|' . $source . '|' . $target . '|' . $format . '|' . $text);

        $cached = DB::table(self::CACHE_TABLE)->where('hash', '=', $hash)->first();

        if ($cached !== null) {
            return response([
                'translation' => $cached->translation,
                'source'      => $cached->source_lang ?? '',
                'cached'      => true,
                'hash'        => $hash,
            ]);
        }

        try {
            // Protect glossary terms (HTML only) so the engine leaves them as-is,
            // then strip the markers from what it returns.
            $terms       = $format === 'html' ? $this->glossaryTerms() : [];
            $send_text   = $this->protectTerms($text, $terms);
            $result      = $this->buildEngine($engine_key)->translate($send_text, $target, $source, $format);
            $translation = $terms === [] ? $result['translation'] : $this->unwrapProtected($result['translation']);

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
