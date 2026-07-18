<?php
declare(strict_types=1);

namespace Tds\Ext\BlogCms\Service;

/**
 * Thin DeepL v2 client for the save-time translation sync — a curl port of
 * tds-content-api's DeeplTranslator (no Guzzle dependency). Free vs pro endpoint
 * is chosen by the `:fx` key suffix; `de → DE`, `en → EN-GB` as target.
 *
 * Every failure path returns null — a flaky DeepL API, a missing key or a quota
 * hit must never fail the admin's save (same swallow-and-log philosophy as the
 * RebuildTrigger). Markdown code spans/fences are shielded so code stays
 * byte-identical through the translation.
 */
final class DeeplTranslator
{
    /** DeepL caps one request at 50 text params. */
    private const MAX_TEXTS_PER_REQUEST = 50;

    public function __construct(private readonly string $apiKey)
    {
    }

    public static function fromEnv(): self
    {
        return new self((string) (getenv('BLOG_DEEPL_API_KEY') ?: getenv('DEEPL_API_KEY') ?: ''));
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Translate a batch of plain-text strings, one request per 50-chunk.
     * Returns translations in input order, or null on any failure.
     *
     * @param list<string> $texts
     * @return list<string>|null
     */
    public function translateTexts(array $texts, string $to, string $from): ?array
    {
        if ($texts === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($texts, self::MAX_TEXTS_PER_REQUEST) as $chunk) {
            $translated = $this->call($chunk, $to, $from, []);
            if ($translated === null) {
                return null;
            }
            $out = array_merge($out, $translated);
        }
        return $out;
    }

    /**
     * Translate markdown while keeping code byte-identical. Fenced blocks and
     * inline code spans are shielded as ignored `<x>` XML tags (tag_handling=xml),
     * then unwrapped afterwards.
     */
    public function translateMarkdown(string $markdown, string $to, string $from): ?string
    {
        if (trim($markdown) === '') {
            return $markdown;
        }

        $segments = [];
        $shielded = preg_replace_callback(
            '/(^```[\s\S]*?^```[ \t]*$|`[^`\n]+`)/m',
            static function (array $m) use (&$segments): string {
                $segments[] = $m[0];
                return "\u{E000}" . (count($segments) - 1) . "\u{E001}";
            },
            $markdown,
        );
        if ($shielded === null) {
            $shielded = $markdown;
            $segments = [];
        }

        $xml = htmlspecialchars($shielded, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = preg_replace_callback(
            "/\u{E000}(\d+)\u{E001}/u",
            static fn (array $m): string => '<x>'
                . htmlspecialchars($segments[(int) $m[1]], ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</x>',
            $xml,
        );
        if ($xml === null) {
            return null;
        }

        $translated = $this->call([$xml], $to, $from, [
            'tag_handling' => 'xml',
            'ignore_tags' => 'x',
        ]);
        if ($translated === null) {
            return null;
        }

        $restoredSegments = [];
        $restored = preg_replace_callback(
            '/<x>([\s\S]*?)<\/x>/',
            static function (array $m) use (&$restoredSegments): string {
                $restoredSegments[] = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                return "\u{E000}" . (count($restoredSegments) - 1) . "\u{E001}";
            },
            $translated[0],
        );
        if ($restored === null) {
            return null;
        }
        $plain = html_entity_decode($restored, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return preg_replace_callback(
            "/\u{E000}(\d+)\u{E001}/u",
            static fn (array $m): string => $restoredSegments[(int) $m[1]] ?? '',
            $plain,
        );
    }

    /**
     * One DeepL /v2/translate request. Returns translations in input order or
     * null on any failure.
     *
     * @param list<string> $texts
     * @param array<string,string> $extra
     * @return list<string>|null
     */
    private function call(array $texts, string $to, string $from, array $extra): ?array
    {
        if ($this->apiKey === '') {
            return null;
        }

        $host = str_ends_with($this->apiKey, ':fx') ? 'api-free.deepl.com' : 'api.deepl.com';
        $params = array_merge([
            'text' => array_values($texts),
            'source_lang' => strtoupper($from),
            'target_lang' => $to === 'en' ? 'EN-GB' : strtoupper($to),
        ], $extra);

        $ch = curl_init("https://{$host}/v2/translate");
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: DeepL-Auth-Key ' . $this->apiKey,
                'Content-Type: application/json',
                'User-Agent: tds-ext-blog-cms',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 300) {
            error_log(sprintf('[blog-cms] DeepL translate failed (HTTP %d): %s', $code, $err));
            return null;
        }

        $data = json_decode((string) $body, true);
        $translations = is_array($data) ? ($data['translations'] ?? null) : null;
        if (!is_array($translations) || count($translations) !== count($texts)) {
            error_log('[blog-cms] DeepL returned an unexpected payload shape');
            return null;
        }
        return array_map(
            static fn (array $t): string => (string) ($t['text'] ?? ''),
            array_values($translations),
        );
    }
}
