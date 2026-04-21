<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/formatter.php';
require_once __DIR__ . '/../lib/embeddings.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$source = $payload['source'] ?? [];
if (!is_array($source)) {
    json_response(['error' => 'Invalid source payload'], 422);
}

$authors = $source['authors'] ?? [];
if (!is_array($authors)) {
    $authors = [];
}
$lookupTrace = $source['lookup_trace'] ?? [];
if (!is_array($lookupTrace)) {
    $lookupTrace = [];
}
$hasProjectNames = array_key_exists('project_names', $source);
$projectNames = $source['project_names'] ?? [];
if (!is_array($projectNames)) {
    $projectNames = [];
}
$projectNames = array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $projectNames)));
$hasBodyText = array_key_exists('body_text', $source);
$existingRow = isset($source['id']) ? get_source((int) $source['id']) : null;
$existingSource = is_array($existingRow) ? source_to_array($existingRow) : null;

$record = [
    'id' => isset($source['id']) ? (int) $source['id'] : 0,
    'type' => trim((string) ($source['type'] ?? 'other')),
    'title' => trim((string) ($source['title'] ?? '')),
    'authors' => array_values(array_filter(array_map('strval', $authors))),
    'year' => trim((string) ($source['year'] ?? '')),
    'publisher' => trim((string) ($source['publisher'] ?? '')),
    'journal' => trim((string) ($source['journal'] ?? '')),
    'volume' => trim((string) ($source['volume'] ?? '')),
    'issue' => trim((string) ($source['issue'] ?? '')),
    'pages' => trim((string) ($source['pages'] ?? '')),
    'doi' => trim((string) ($source['doi'] ?? '')),
    'isbn' => trim((string) ($source['isbn'] ?? '')),
    'url' => trim((string) ($source['url'] ?? '')),
    'accessed_at' => trim((string) ($source['accessed_at'] ?? '')),
    'raw_input' => (string) ($source['raw_input'] ?? ''),
    'notes' => (string) ($source['notes'] ?? ''),
    'lookup_trace' => $lookupTrace,
    'provenance_summary' => trim((string) ($source['provenance_summary'] ?? '')),
];
if ($hasProjectNames) {
    $record['project_names'] = $projectNames;
}
if ($hasBodyText) {
    $bodyText = trim(str_replace(["\r\n", "\r"], "\n", (string) ($source['body_text'] ?? '')));
    $record['body_text'] = $bodyText;

    $existingBodyText = trim((string) ($existingSource['body_text'] ?? ''));
    $existingBodySource = trim((string) ($existingSource['body_source'] ?? ''));
    if ($bodyText !== $existingBodyText) {
        $record['body_fetched_at'] = gmdate('c');
        $record['body_source'] = str_contains($existingBodySource, 'manual_edit')
            ? $existingBodySource
            : ($existingBodySource !== '' ? ($existingBodySource . '+manual_edit') : 'manual_edit');
    } else {
        $record['body_source'] = trim((string) ($source['body_source'] ?? $existingBodySource));
        $record['body_fetched_at'] = (string) ($existingSource['body_fetched_at'] ?? '');
    }
}

$saved = save_source($record);
$id = (int) $saved['id'];

regenerate_citation_cache_for_source($id);
upsert_source_embedding($id);
$row = get_source($id);

if (!is_array($row)) {
    json_response(['error' => 'Unable to fetch saved record'], 500);
}

$sourceOut = source_to_array($row);
$cache = $row['citation_cache'] ?? null;
if (is_string($cache)) {
    $cache = json_decode($cache, true);
}
if (!is_array($cache)) {
    $cache = citation_cache_for_source($sourceOut);
}

json_response([
    'ok' => true,
    'id' => $id,
    'source' => $sourceOut,
    'citation_cache' => $cache,
]);
