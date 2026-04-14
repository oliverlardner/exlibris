<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/zotero.php';
require_once __DIR__ . '/../lib/formatter.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$mode = (string) ($payload['mode'] ?? 'preview');
$limit = max(1, min(100, (int) ($payload['limit'] ?? 25)));
$start = max(0, (int) ($payload['start'] ?? 0));

if ($mode === 'collections') {
    try {
        $collections = zotero_fetch_collections();
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 422);
    }

    $saved = [];
    foreach ($collections as $collection) {
        if (!is_array($collection)) {
            continue;
        }
        $project = zotero_collection_to_project($collection);
        $projectId = get_or_create_project_by_external(
            (string) $project['external_provider'],
            (string) $project['external_id'],
            (string) $project['name']
        );
        if ($projectId > 0) {
            $saved[] = [
                'project_id' => $projectId,
                'name' => (string) $project['name'],
                'external_id' => (string) $project['external_id'],
            ];
        }
    }

    json_response([
        'ok' => true,
        'mode' => $mode,
        'count' => count($saved),
        'collections' => $saved,
    ]);
}

if ($mode === 'push_one' || $mode === 'push_unsynced') {
    $sourceIds = [];
    if ($mode === 'push_one') {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        if ($sourceId <= 0) {
            json_response(['ok' => false, 'error' => 'source_id is required for push_one'], 422);
        }
        $sourceIds[] = $sourceId;
    } else {
        foreach (list_sources() as $row) {
            $source = source_to_array($row);
            if (trim((string) ($source['zotero_item_key'] ?? '')) === '') {
                $sourceIds[] = (int) ($source['id'] ?? 0);
            }
        }
    }

    $results = [];
    foreach ($sourceIds as $sourceId) {
        $row = get_source((int) $sourceId);
        if (!is_array($row)) {
            continue;
        }
        $source = source_to_array($row);
        try {
            $push = zotero_push_source($source);
            $source['origin_provider'] = 'zotero';
            $source['origin_external_id'] = (string) ($push['key'] ?? '');
            $source['zotero_item_key'] = (string) ($push['key'] ?? '');
            $source['zotero_synced_at'] = gmdate('c');
            save_source($source);
            $results[] = [
                'source_id' => (int) $source['id'],
                'zotero_key' => (string) ($push['key'] ?? ''),
                'collection_keys' => is_array($push['collection_keys'] ?? null) ? $push['collection_keys'] : [],
                'created' => (bool) ($push['created'] ?? false),
                'status' => 'success',
            ];
        } catch (Throwable $e) {
            $results[] = [
                'source_id' => (int) $source['id'],
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    json_response([
        'ok' => true,
        'mode' => $mode,
        'count' => count($results),
        'results' => $results,
    ]);
}

try {
    $items = zotero_fetch_items($limit, $start);
    $collections = zotero_fetch_collections();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
$collectionProjectMap = [];
foreach ($collections as $collection) {
    if (!is_array($collection)) {
        continue;
    }
    $project = zotero_collection_to_project($collection);
    $projectId = get_or_create_project_by_external(
        (string) $project['external_provider'],
        (string) $project['external_id'],
        (string) $project['name']
    );
    $externalId = (string) $project['external_id'];
    if ($projectId > 0 && $externalId !== '') {
        $collectionProjectMap[$externalId] = $projectId;
    }
}

$preview = [];
$existing = [];
foreach (list_sources() as $row) {
    $source = source_to_array($row);
    $zoteroKey = trim((string) ($source['zotero_item_key'] ?? ''));
    if ($zoteroKey !== '') {
        $existing['zotero:' . $zoteroKey] = (int) ($source['id'] ?? 0);
    }
}
foreach (list_sources() as $row) {
    $source = source_to_array($row);
    $existing['fp:' . source_fingerprint($source)] = (int) ($source['id'] ?? 0);
}

$importedIds = [];
$linkedProjects = 0;
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $source = zotero_item_to_source($item);
    $fingerprint = source_fingerprint($source);
    $zoteroKey = (string) ($source['zotero_item_key'] ?? '');
    $existingId = 0;
    if ($zoteroKey !== '' && isset($existing['zotero:' . $zoteroKey])) {
        $existingId = (int) $existing['zotero:' . $zoteroKey];
    } elseif (isset($existing['fp:' . $fingerprint])) {
        $existingId = (int) $existing['fp:' . $fingerprint];
    }
    $isDuplicate = $existingId > 0;
    $projectIds = [];
    foreach (($source['project_external_keys'] ?? []) as $externalKey) {
        $projectId = (int) ($collectionProjectMap[(string) $externalKey] ?? 0);
        if ($projectId > 0) {
            $projectIds[] = $projectId;
        }
    }
    $source['project_ids'] = $projectIds;

    $preview[] = [
        'key' => (string) ($item['key'] ?? ''),
        'title' => (string) ($source['title'] ?? ''),
        'year' => (string) ($source['year'] ?? ''),
        'project_ids' => $projectIds,
        'duplicate' => $isDuplicate,
        'source' => $source,
    ];

    if ($mode === 'import' && !$isDuplicate) {
        $saved = save_source($source);
        $id = (int) ($saved['id'] ?? 0);
        if ($id > 0) {
            regenerate_citation_cache_for_source($id);
            $importedIds[] = $id;
            $existing['fp:' . $fingerprint] = $id;
            if ($zoteroKey !== '') {
                $existing['zotero:' . $zoteroKey] = $id;
            }
            if ($projectIds !== []) {
                $linkedProjects += count($projectIds);
            }
        }
    }

    if ($mode === 'import' && $isDuplicate) {
        $row = get_source($existingId);
        if (is_array($row)) {
            $existingSource = source_to_array($row);
            $existingSource['project_ids'] = array_values(array_unique(array_merge(
                $existingSource['project_ids'] ?? [],
                $projectIds
            )));
            $existingSource['zotero_item_key'] = $zoteroKey !== '' ? $zoteroKey : (string) ($existingSource['zotero_item_key'] ?? '');
            $existingSource['origin_provider'] = 'zotero';
            $existingSource['origin_external_id'] = $zoteroKey !== '' ? $zoteroKey : (string) ($existingSource['origin_external_id'] ?? '');
            $existingSource['zotero_synced_at'] = gmdate('c');
            save_source($existingSource);
        }
    }
}

json_response([
    'ok' => true,
    'mode' => $mode,
    'count' => count($preview),
    'imported_count' => count($importedIds),
    'imported_ids' => $importedIds,
    'linked_project_count' => $linkedProjects,
    'items' => $preview,
]);
