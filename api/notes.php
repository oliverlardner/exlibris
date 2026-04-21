<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$action = trim((string) ($payload['action'] ?? 'create'));

if ($action === 'delete') {
    $noteId = (int) ($payload['note_id'] ?? 0);
    if ($noteId <= 0) {
        json_response(['error' => 'Valid note_id is required'], 422);
    }
    $deleted = delete_source_note($noteId);
    json_response([
        'ok' => true,
        'deleted' => $deleted,
        'note_id' => $noteId,
    ]);
}

if ($action !== 'create') {
    json_response(['error' => 'Unsupported notes action'], 422);
}

$note = $payload['note'] ?? [];
if (!is_array($note)) {
    json_response(['error' => 'Invalid note payload'], 422);
}

try {
    $created = create_source_note($note);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 422);
}

json_response([
    'ok' => true,
    'note' => $created,
]);
