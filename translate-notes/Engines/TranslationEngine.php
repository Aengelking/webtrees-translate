<?php

declare(strict_types=1);

namespace TranslateNotes\Engines;

/**
 * A translation backend. Implementations are stateless apart from their
 * configuration, which is injected via the constructor by the module.
 */
interface TranslationEngine
{
    /** Machine key stored in module preferences (e.g. "deepl"). */
    public static function key(): string;

    /** Human-readable label shown in the admin dropdown. */
    public static function label(): string;

    /**
     * Translate $text into $target. $source may be a language code or "auto".
     *
     * $format is "html" to preserve markup (engines that cannot handle HTML
     * fall back to translating the stripped text) or "text" for plain text.
     *
     * @return array{translation: string, source: string}
     */
    public function translate(string $text, string $target, string $source, string $format = 'text'): array;
}
