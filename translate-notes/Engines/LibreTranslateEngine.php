<?php

declare(strict_types=1);

namespace TranslateNotes\Engines;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * LibreTranslate - free and open-source. Point it at your own self-hosted
 * instance (recommended for privacy) or any instance URL. Some instances
 * require an API key. Auto-detects the source language when $source is "auto".
 */
class LibreTranslateEngine implements TranslationEngine
{
    private string $url;
    private string $api_key;

    public function __construct(string $url, string $api_key = '')
    {
        $this->url     = rtrim($url, '/');
        $this->api_key = $api_key;
    }

    public static function key(): string
    {
        return 'libretranslate';
    }

    public static function label(): string
    {
        return 'LibreTranslate (self-hosted)';
    }

    public function translate(string $text, string $target, string $source, string $format = 'text'): array
    {
        if ($this->url === '') {
            throw new RuntimeException('LibreTranslate URL is not configured.');
        }

        $src = ($source === '' || strtolower($source) === 'auto') ? 'auto' : strtolower($source);

        $payload = [
            'q'      => $text,
            'source' => $src,
            'target' => strtolower($target),
            'format' => $format === 'html' ? 'html' : 'text',
        ];

        if ($this->api_key !== '') {
            $payload['api_key'] = $this->api_key;
        }

        $client   = new Client(['timeout' => 20]);
        $response = $client->post($this->url . '/translate', ['json' => $payload]);

        $body = json_decode((string) $response->getBody(), true);

        return [
            'translation' => $body['translatedText'] ?? '',
            'source'      => $body['detectedLanguage']['language'] ?? ($src === 'auto' ? '' : $src),
        ];
    }
}
