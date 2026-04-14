<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';

function openai_config_value(string $group, string $key, mixed $default = null): mixed
{
    $config = require __DIR__ . '/../config.php';

    return $config[$group][$key] ?? $default;
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
            'content' => 'You extract bibliographic metadata and return only valid JSON. ' .
                'Schema keys: type,title,authors,year,publisher,journal,volume,issue,pages,doi,isbn,url,notes. ' .
                'authors must be an array of strings. Use empty strings for unknown scalar values.',
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
