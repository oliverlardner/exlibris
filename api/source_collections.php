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
$id = (int) ($payload['id'] ?? 0);
if ($id <= 0) {
    json_response(['error' => 'Valid source id is required'], 422);
}

$names = $payload['project_names'] ?? [];
if (!is_array($names)) {
    $names = [];
}
$names = array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $names)));

$row = get_source($id);
if (!is_array($row)) {
    json_response(['error' => 'Source not found'], 404);
}

$record = source_to_array($row);
$record['id'] = $id;
$record['project_names'] = $names;

try {
    save_source($record);
} catch (Throwable $e) {
    app_log('source_collections_save_failed', ['id' => $id, 'error' => $e->getMessage()]);
    json_response(['error' => 'Could not save collections'], 500);
}

regenerate_citation_cache_for_source($id);
upsert_source_embedding($id);

json_response([
    'ok' => true,
    'id' => $id,
    'project_names' => $names,
]);
