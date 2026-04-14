<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/embeddings.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$query = trim((string) ($payload['query'] ?? ''));
$limit = max(1, min(25, (int) ($payload['limit'] ?? 10)));

if ($query === '') {
    json_response(['ok' => false, 'error' => 'query is required'], 422);
}

$results = semantic_search_sources($query, $limit);
json_response(['ok' => true, 'results' => $results]);
