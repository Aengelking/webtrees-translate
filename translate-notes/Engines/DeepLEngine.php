<?php

declare(strict_types=1);

namespace TranslateNotes\Engines;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * DeepL - high quality. Requires an API key (Free or Pro).
 * Auto-detects the source language when $source is "auto".
 */
class DeepLEngine implements TranslationEngine
{
    private string $api_key;
    private string $plan;

    public function __construct(string $api_key, string $plan = 'free')
    {
        $this->api_key = $api_key;
        $this->plan    = $plan;
    }

    public static function key(): string
    {
        return 'deepl';
    }

    public static function label(): string
    {
        return 'DeepL';
    }

    /**
     * DeepL Free keys end in ":fx" and only work against the free host; Pro keys
     * only work against api.deepl.com. Detect from the key so a mismatched "plan"
     * setting can't cause a 403 "Wrong endpoint" error.
     */
    private function host(): string
    {
        return str_ends_with($this->api_key, ':fx')
            ? 'https://api-free.deepl.com'
            : 'https://api.deepl.com';
    }

    /**
     * Current character usage for the account, via DeepL's /v2/usage endpoint.
     *
     * @return array{count:int,limit:int} characters used and the period limit
     */
    public function usage(): array
    {
        if ($this->api_key === '') {
            throw new RuntimeException('DeepL API key is not configured.');
        }

        $client   = new Client(['timeout' => 15]);
        $response = $client->get($this->host() . '/v2/usage', [
            'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $this->api_key],
        ]);

        $body = json_decode((string) $response->getBody(), true);

        return [
            'count' => (int) ($body['character_count'] ?? 0),
            'limit' => (int) ($body['character_limit'] ?? 0),
        ];
    }

    public function translate(string $text, string $target, string $source, string $format = 'text'): array
    {
        if ($this->api_key === '') {
            throw new RuntimeException('DeepL API key is not configured.');
        }

        $host = $this->host();

        $params = ['text' => $text, 'target_lang' => strtoupper($target)];

        if ($source !== '' && strtolower($source) !== 'auto') {
            $params['source_lang'] = strtoupper($source);
        }

        // Translate the text between tags and leave the markup untouched.
        if ($format === 'html') {
            $params['tag_handling'] = 'html';
        }

        $client   = new Client(['timeout' => 15]);
        $response = $client->post($host . '/v2/translate', [
            'headers'     => ['Authorization' => 'DeepL-Auth-Key ' . $this->api_key],
            'form_params' => $params,
        ]);

        $body = json_decode((string) $response->getBody(), true);

        return [
            'translation' => $body['translations'][0]['text'] ?? '',
            'source'      => $body['translations'][0]['detected_source_language'] ?? '',
        ];
    }
}
