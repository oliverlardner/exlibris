<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$name = trim((string) ($payload['name'] ?? ''));
if ($name === '') {
    json_response(['error' => 'Project name is required'], 422);
}

$projectId = get_or_create_project_by_name($name);
if ($projectId <= 0) {
    json_response(['error' => 'Could not create project'], 500);
}

$project = get_project($projectId);
if (!is_array($project)) {
    json_response(['error' => 'Could not load project'], 500);
}

json_response([
    'ok' => true,
    'project' => [
        'id' => (int) ($project['id'] ?? 0),
        'name' => (string) ($project['name'] ?? ''),
    ],
]);
