<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/common.php';

function openai_config_value(string $group, string $key, mixed $default = null): mixed
{
    return config_value($group, $key, $default);
}

function validate_openai_api_key(string $apiKey): array
{
    $apiKey = trim($apiKey);
    if ($apiKey === '') {
        return [
            'ok' => false,
            'error' => 'API key cannot be empty.',
        ];
    }

    try {
        http_get_json(
            'https://api.openai.com/v1/models',
            ['Authorization: Bearer ' . $apiKey]
        );

        return [
            'ok' => true,
            'message' => 'API key is valid.',
        ];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (str_contains($message, '401')) {
            $message = 'Invalid API key (401 unauthorized).';
        } elseif (str_contains($message, '403')) {
            $message = 'API key is valid but lacks required permissions (403).';
        } elseif (str_contains($message, '429')) {
            $message = 'Rate limit hit while validating key. Try again in a moment.';
        } else {
            $message = 'Could not validate API key right now: ' . $message;
        }

        return [
            'ok' => false,
            'error' => $message,
        ];
    }
}

function effective_openai_api_key(): string
{
    $env = trim((string) getenv('EXLIBRIS_OPENAI_API_KEY'));
    if ($env !== '') {
        return $env;
    }

    return trim((string) setting('openai_api_key', ''));
}

function openai_extract_source(string $input, ?string $url = null): ?array
{
    $apiKey = effective_openai_api_key();
    if ($apiKey === '') {
        return null;
    }

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are a bibliographic metadata assistant. Given the input, extract or identify the metadata and return only valid JSON. ' .
                'Schema keys: type (book/article/video/website/other), title, authors, year, publisher, journal, volume, issue, pages, doi, isbn, url, notes. ' .
                'authors must be an array of strings. ' .
                'For a book or article title: identify the work and fill in all metadata you know with confidence, including isbn (prefer ISBN-13) or doi if known. ' .
                'Use empty string for unknown scalar values.',
        ],
        [
            'role' => 'user',
            'content' => "Input:\n" . $input . ($url ? ("\nURL: " . $url) : ''),
        ],
    ];

    $payload = [
        'model' => (string) openai_config_value('ai', 'chat_model', 'gpt-4o-mini'),
        'temperature' => 0.1,
        'response_format' => ['type' => 'json_object'],
        'messages' => $messages,
    ];

    $data = http_post_json(
        'https://api.openai.com/v1/chat/completions',
        $payload,
        ['Authorization: Bearer ' . $apiKey]
    );

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        return null;
    }

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return null;
    }

    $authors = $parsed['authors'] ?? [];
    if (!is_array($authors)) {
        $authors = [];
    }

    return [
        'type' => (string) ($parsed['type'] ?? 'other'),
        'title' => (string) ($parsed['title'] ?? ''),
        'authors' => array_values(array_filter(array_map('strval', $authors))),
        'year' => (string) ($parsed['year'] ?? ''),
        'publisher' => (string) ($parsed['publisher'] ?? ''),
        'journal' => (string) ($parsed['journal'] ?? ''),
        'volume' => (string) ($parsed['volume'] ?? ''),
        'issue' => (string) ($parsed['issue'] ?? ''),
        'pages' => (string) ($parsed['pages'] ?? ''),
        'doi' => (string) ($parsed['doi'] ?? ''),
        'isbn' => (string) ($parsed['isbn'] ?? ''),
        'url' => (string) ($parsed['url'] ?? $url ?? ''),
        'notes' => (string) ($parsed['notes'] ?? ''),
    ];
}

function openai_json_response(string $systemPrompt, string $userPrompt): ?array
{
    $apiKey = effective_openai_api_key();
    if ($apiKey === '') {
        return null;
    }

    $payload = [
        'model' => (string) openai_config_value('ai', 'chat_model', 'gpt-4o-mini'),
        'temperature' => 0.2,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $data = http_post_json(
        'https://api.openai.com/v1/chat/completions',
        $payload,
        ['Authorization: Bearer ' . $apiKey]
    );

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        return null;
    }

    $decoded = json_decode($content, true);

    return is_array($decoded) ? $decoded : null;
}

function openai_reformat_extracted_text(array $source): ?array
{
    $rawText = str_replace(["\r\n", "\r"], "\n", (string) ($source['body_text'] ?? ''));
    $rawText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawText) ?? $rawText;
    $rawText = preg_replace("/[ \t]+\n/u", "\n", $rawText) ?? $rawText;
    $rawText = trim($rawText);
    if ($rawText === '') {
        return null;
    }

    $maxChars = 120000;
    $truncated = false;
    if (mb_strlen($rawText) > $maxChars) {
        $rawText = mb_substr($rawText, 0, $maxChars);
        $truncated = true;
    }

    $context = [
        'title' => (string) ($source['title'] ?? ''),
        'authors' => array_values(array_filter(array_map('strval', $source['authors'] ?? []))),
        'year' => (string) ($source['year'] ?? ''),
        'url' => (string) ($source['url'] ?? ''),
        'body_source' => (string) ($source['body_source'] ?? ''),
    ];

    $response = openai_json_response(
        'You clean extracted research text for a human reading interface. Return JSON with keys body_text (string) and change_summary (string). ' .
        'Preserve the underlying substance of the document. Remove navigation chrome, cookie banners, repeated headers/footers, orphaned link lists, duplicate boilerplate, and obvious OCR noise when confident. ' .
        'Reformat the remaining content into readable paragraphs, headings, and lists only when the structure is strongly implied by the source. ' .
        'Do not summarize away substantive passages. Do not invent citations, wording, or section structure not supported by the input. ' .
        'When uncertain, keep more text rather than less.',
        "Document context:\n" . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n\nExtracted text to clean:\n" . $rawText
    );
    if (!is_array($response)) {
        return null;
    }

    $cleanedText = str_replace(["\r\n", "\r"], "\n", trim((string) ($response['body_text'] ?? '')));
    $cleanedText = preg_replace('/\n{4,}/u', "\n\n\n", $cleanedText) ?? $cleanedText;
    if ($cleanedText === '') {
        return null;
    }

    return [
        'body_text' => $cleanedText,
        'change_summary' => trim((string) ($response['change_summary'] ?? '')),
        'input_truncated' => $truncated,
        'original_chars' => mb_strlen($rawText),
        'cleaned_chars' => mb_strlen($cleanedText),
    ];
}

function openai_reader_model(): string
{
    $env = trim((string) getenv('EXLIBRIS_OPENAI_READER_MODEL'));
    if ($env !== '') {
        return $env;
    }
    $fromConfig = trim((string) openai_config_value('ai', 'reader_model', ''));
    if ($fromConfig !== '') {
        return $fromConfig;
    }

    return (string) openai_config_value('ai', 'chat_model', 'gpt-4o-mini');
}

/**
 * Calls the Responses API with hosted web_search enabled and returns a parsed
 * JSON object when possible.
 *
 * @return array{output: array<string, mixed>, usage: array<string, mixed>}|null
 */
function openai_responses_with_web_search(string $systemPrompt, string $userPrompt, array $jsonSchema, string $model = ''): ?array
{
    $apiKey = effective_openai_api_key();
    if ($apiKey === '') {
        return null;
    }
    $chosenModel = trim($model) !== '' ? trim($model) : openai_reader_model();
    $schemaName = (string) ($jsonSchema['name'] ?? 'reader_synthesis');
    $schemaBody = is_array($jsonSchema['schema'] ?? null) ? $jsonSchema['schema'] : [];
    if ($schemaBody === []) {
        return null;
    }

    $payload = [
        'model' => $chosenModel,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $systemPrompt],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $userPrompt],
                ],
            ],
        ],
        'tools' => [
            ['type' => 'web_search'],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaName,
                'schema' => $schemaBody,
                'strict' => true,
            ],
        ],
    ];

    $data = http_post_json(
        'https://api.openai.com/v1/responses',
        $payload,
        ['Authorization: Bearer ' . $apiKey]
    );

    $outputText = trim((string) ($data['output_text'] ?? ''));
    if ($outputText === '') {
        $output = is_array($data['output'] ?? null) ? $data['output'] : [];
        foreach ($output as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $contentList = is_array($chunk['content'] ?? null) ? $chunk['content'] : [];
            foreach ($contentList as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $text = trim((string) ($item['text'] ?? ''));
                if ($text !== '') {
                    $outputText = $text;
                    break 2;
                }
            }
        }
    }

    if ($outputText === '') {
        return null;
    }

    $decoded = json_decode($outputText, true);
    if (!is_array($decoded)) {
        return null;
    }

    return [
        'output' => $decoded,
        'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
    ];
}

function openai_embedding(string $input): array
{
    $apiKey = effective_openai_api_key();
    if ($apiKey === '') {
        return [];
    }

    $payload = [
        'model' => (string) openai_config_value('ai', 'embedding_model', 'text-embedding-3-small'),
        'input' => $input,
    ];
    $data = http_post_json(
        'https://api.openai.com/v1/embeddings',
        $payload,
        ['Authorization: Bearer ' . $apiKey]
    );
    $vector = $data['data'][0]['embedding'] ?? [];

    return is_array($vector) ? array_values(array_map('floatval', $vector)) : [];
}
