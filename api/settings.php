<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/formatter.php';
require_once __DIR__ . '/../lib/openai.php';

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'citation_format' => current_citation_format(),
        'theme_mode' => current_theme_mode(),
        'include_pages_in_citations' => include_pages_in_citations(),
        'assistant_enabled' => setting('assistant_enabled', '1') !== '0',
        'assistant_model' => setting('assistant_model', 'gpt-4o-mini'),
        'zotero_user_id' => setting('zotero_user_id', ''),
        'zotero_library_type' => setting('zotero_library_type', 'users'),
        'zotero_library_id' => setting('zotero_library_id', ''),
        'zotero_auto_collection_enabled' => setting('zotero_auto_collection_enabled', '1') !== '0',
        'zotero_auto_collection_name' => setting('zotero_auto_collection_name', 'Ex Libris'),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}
require_admin_for_write();

$payload = json_input();

$citationFormat = $payload['citation_format'] ?? null;
if (is_string($citationFormat)) {
    $citationFormat = normalize_citation_format($citationFormat, '');
    if ($citationFormat !== '') {
        set_setting('citation_format', $citationFormat);
        regenerate_all_citation_cache();
    }
}

$includePages = $payload['include_pages_in_citations'] ?? null;
if (is_bool($includePages) || is_string($includePages) || is_int($includePages)) {
    $includeValue = (string) $includePages;
    $normalized = in_array(strtolower($includeValue), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    $previous = include_pages_in_citations() ? '1' : '0';
    if ($normalized !== $previous) {
        set_setting('include_pages_in_citations', $normalized);
        regenerate_all_citation_cache();
    }
}

$themeMode = $payload['theme_mode'] ?? null;
if (is_string($themeMode)) {
    $themeMode = strtolower(trim($themeMode));
    if (in_array($themeMode, ['auto', 'light', 'dark'], true)) {
        set_setting('theme_mode', $themeMode);
    }
}

$openaiApiKey = $payload['openai_api_key'] ?? null;
if (is_string($openaiApiKey)) {
    if (trim((string) getenv('EXLIBRIS_OPENAI_API_KEY')) !== '') {
        json_response([
            'ok' => false,
            'error' => 'OpenAI key is managed by environment variable EXLIBRIS_OPENAI_API_KEY.',
        ], 422);
    }
    $openaiApiKey = trim($openaiApiKey);
    if ($openaiApiKey === '') {
        set_setting('openai_api_key', '');
    } else {
        $validation = validate_openai_api_key($openaiApiKey);
        if (!($validation['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($validation['error'] ?? 'OpenAI key validation failed.'),
            ], 422);
        }

        set_setting('openai_api_key', $openaiApiKey);
    }
}

$assistantEnabled = $payload['assistant_enabled'] ?? null;
if (is_bool($assistantEnabled) || is_int($assistantEnabled) || is_string($assistantEnabled)) {
    $normalized = in_array(strtolower((string) $assistantEnabled), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    set_setting('assistant_enabled', $normalized);
}

$assistantModel = $payload['assistant_model'] ?? null;
if (is_string($assistantModel) && trim($assistantModel) !== '') {
    set_setting('assistant_model', trim($assistantModel));
}

foreach (['zotero_user_id', 'zotero_api_key', 'zotero_library_type', 'zotero_library_id'] as $key) {
    if (array_key_exists($key, $payload) && is_string($payload[$key])) {
        set_setting($key, trim((string) $payload[$key]));
    }
}

$zoteroAutoCollectionEnabled = $payload['zotero_auto_collection_enabled'] ?? null;
if (is_bool($zoteroAutoCollectionEnabled) || is_int($zoteroAutoCollectionEnabled) || is_string($zoteroAutoCollectionEnabled)) {
    $normalized = in_array(strtolower((string) $zoteroAutoCollectionEnabled), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    set_setting('zotero_auto_collection_enabled', $normalized);
}
$zoteroAutoCollectionName = $payload['zotero_auto_collection_name'] ?? null;
if (is_string($zoteroAutoCollectionName)) {
    $name = trim($zoteroAutoCollectionName);
    set_setting('zotero_auto_collection_name', $name !== '' ? $name : 'Ex Libris');
}

json_response([
    'ok' => true,
    'citation_format' => current_citation_format(),
    'theme_mode' => current_theme_mode(),
    'include_pages_in_citations' => include_pages_in_citations(),
    'assistant_enabled' => setting('assistant_enabled', '1') !== '0',
    'assistant_model' => setting('assistant_model', 'gpt-4o-mini'),
    'zotero_user_id' => setting('zotero_user_id', ''),
    'zotero_library_type' => setting('zotero_library_type', 'users'),
    'zotero_library_id' => setting('zotero_library_id', ''),
    'zotero_auto_collection_enabled' => setting('zotero_auto_collection_enabled', '1') !== '0',
    'zotero_auto_collection_name' => setting('zotero_auto_collection_name', 'Ex Libris'),
    'message' => 'Settings saved.',
]);
