<?php

declare(strict_types=1);

namespace TranslateNotes\Engines;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Microsoft / Azure Translator.
 *
 * Free tier (F0) allows ~2,000,000 characters per month. Requires an Azure
 * "Translator" resource: copy one of its keys and its region (e.g. "westeurope",
 * or "global" for a global resource). Preserves HTML formatting and auto-detects
 * the source language when $source is "auto".
 *
 * @see https://learn.microsoft.com/azure/ai-services/translator/reference/v3-0-translate
 */
class MicrosoftEngine implements TranslationEngine
{
    private const ENDPOINT = 'https://api.cognitive.microsofttranslator.com';

    // Codes Azure needs with their region/script; everything else uses the bare
    // primary subtag (so "en-US" -> "en", but "pt-BR" stays "pt-br").
    private const REGIONAL = [
        'pt-br', 'pt-pt', 'zh-hans', 'zh-hant', 'sr-cyrl', 'sr-latn', 'mn-cyrl', 'mn-mong',
    ];

    private string $api_key;
    private string $region;

    public function __construct(string $api_key, string $region = '')
    {
        $this->api_key = $api_key;
        $this->region  = $region;
    }

    public static function key(): string
    {
        return 'microsoft';
    }

    public static function label(): string
    {
        return 'Microsoft Translator';
    }

    public function translate(string $text, string $target, string $source, string $format = 'text'): array
    {
        if ($this->api_key === '') {
            throw new RuntimeException('Microsoft Translator API key is not configured.');
        }

        $query = [
            'api-version' => '3.0',
            'to'          => $this->normalise($target),
            'textType'    => $format === 'html' ? 'html' : 'plain',
        ];

        if ($source !== '' && strtolower($source) !== 'auto') {
            $query['from'] = $this->normalise($source);
        }

        $headers = [
            'Ocp-Apim-Subscription-Key' => $this->api_key,
            'Content-Type'              => 'application/json',
        ];

        // Regional (non-global) resources require the region header.
        if ($this->region !== '') {
            $headers['Ocp-Apim-Subscription-Region'] = $this->region;
        }

        $client   = new Client(['timeout' => 20]);
        $response = $client->post(self::ENDPOINT . '/translate', [
            'headers' => $headers,
            'query'   => $query,
            'json'    => [['Text' => $text]],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $item = $body[0] ?? [];

        return [
            'translation' => $item['translations'][0]['text'] ?? '',
            'source'      => $item['detectedLanguage']['language'] ?? '',
        ];
    }

    /** Map a webtrees language tag to an Azure language code. */
    private function normalise(string $tag): string
    {
        $code = strtolower($tag);

        return in_array($code, self::REGIONAL, true) ? $code : explode('-', $code, 2)[0];
    }
}
