<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$id = (int) ($payload['id'] ?? 0);
if ($id <= 0) {
    json_response(['error' => 'Valid id is required'], 422);
}

$deleted = delete_source($id);

json_response([
    'ok' => true,
    'deleted' => $deleted,
]);
