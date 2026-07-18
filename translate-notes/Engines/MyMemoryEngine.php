<?php

declare(strict_types=1);

namespace TranslateNotes\Engines;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * MyMemory - free, no API key required.
 *
 * Anonymous quota ~5,000 chars/day; add an email to raise it to ~50,000.
 * No source-language auto-detection, so a real source code is required
 * (defaults to "en" when the module is set to "auto").
 * Anonymous calls are limited to ~500 chars, so long notes are chunked.
 */
class MyMemoryEngine implements TranslationEngine
{
    private string $email;

    public function __construct(string $email = '')
    {
        $this->email = $email;
    }

    public static function key(): string
    {
        return 'mymemory';
    }

    public static function label(): string
    {
        return 'MyMemory (free, no key)';
    }

    public function translate(string $text, string $target, string $source, string $format = 'text'): array
    {
        // MyMemory has no HTML mode; translate the plain text so tag names are not
        // mangled. Formatting is lost - use DeepL or LibreTranslate to keep it.
        if ($format === 'html') {
            $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $src = ($source === '' || strtolower($source) === 'auto') ? 'en' : strtolower($source);
        $tgt = strtolower($target);

        $client      = new Client(['timeout' => 20]);
        $translation = '';

        foreach ($this->split($text, 480) as $chunk) {
            $query = ['q' => $chunk, 'langpair' => $src . '|' . $tgt];

            if ($this->email !== '') {
                $query['de'] = $this->email;
            }

            $response = $client->get('https://api.mymemory.translated.net/get', ['query' => $query]);
            $body     = json_decode((string) $response->getBody(), true);

            if ((int) ($body['responseStatus'] ?? 0) !== 200) {
                throw new RuntimeException((string) ($body['responseDetails'] ?? 'MyMemory error'));
            }

            $translation .= $body['responseData']['translatedText'] ?? '';
        }

        return ['translation' => $translation, 'source' => $src];
    }

    /**
     * Split text into chunks not exceeding $limit bytes, breaking on whitespace.
     *
     * @return array<string>
     */
    private function split(string $text, int $limit): array
    {
        if (strlen($text) <= $limit) {
            return [$text];
        }

        $parts   = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $chunks  = [];
        $current = '';

        foreach ($parts as $part) {
            if ($current !== '' && strlen($current) + strlen($part) > $limit) {
                $chunks[] = $current;
                $current  = '';
            }
            $current .= $part;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
