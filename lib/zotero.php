<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/formatter.php';

function zotero_config(): array
{
    return [
        'user_id' => trim((string) setting('zotero_user_id', '')),
        'api_key' => trim((string) setting('zotero_api_key', '')),
        'library_type' => trim((string) setting('zotero_library_type', 'users')),
        'library_id' => trim((string) setting('zotero_library_id', '')),
    ];
}

function zotero_auto_collection_enabled(): bool
{
    $value = trim((string) setting('zotero_auto_collection_enabled', '1'));

    return !in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
}

function zotero_auto_collection_name(): string
{
    $name = trim((string) setting('zotero_auto_collection_name', 'Ex Libris'));

    return $name !== '' ? $name : 'Ex Libris';
}

function zotero_library_path(): string
{
    $cfg = zotero_config();
    if ($cfg['api_key'] === '' || $cfg['user_id'] === '') {
        throw new RuntimeException('Zotero credentials are missing in Settings.');
    }

    $libraryType = in_array($cfg['library_type'], ['users', 'groups'], true) ? $cfg['library_type'] : 'users';
    if ($libraryType === 'groups' && $cfg['library_id'] === '') {
        throw new RuntimeException('Zotero library type is groups, but library id is empty.');
    }
    $libraryId = $libraryType === 'groups' ? $cfg['library_id'] : $cfg['user_id'];

    return $libraryType . '/' . rawurlencode($libraryId);
}

function zotero_headers(): array
{
    $cfg = zotero_config();

    return [
        'User-Agent: exlibris/1.0',
        'Zotero-API-Version: 3',
        'Zotero-API-Key: ' . $cfg['api_key'],
    ];
}

function zotero_fetch_items(int $limit = 25, int $start = 0): array
{
    $url = sprintf(
        'https://api.zotero.org/%s/items?format=json&limit=%d&start=%d',
        zotero_library_path(),
        $limit,
        $start
    );

    try {
        return http_get_json($url, zotero_headers());
    } catch (Throwable $e) {
        throw new RuntimeException('Zotero items request failed. Check user/group id and API key permissions. ' . $e->getMessage());
    }
}

function zotero_fetch_collections(): array
{
    $url = sprintf(
        'https://api.zotero.org/%s/collections?format=json&limit=100',
        zotero_library_path()
    );

    try {
        return http_get_json($url, zotero_headers());
    } catch (Throwable $e) {
        throw new RuntimeException('Zotero collections request failed. Check library type/id and API key permissions. ' . $e->getMessage());
    }
}

function zotero_fetch_item(string $itemKey): ?array
{
    $itemKey = trim($itemKey);
    if ($itemKey === '') {
        return null;
    }
    $url = sprintf(
        'https://api.zotero.org/%s/items/%s?format=json',
        zotero_library_path(),
        rawurlencode($itemKey)
    );
    try {
        $item = http_get_json($url, zotero_headers());
    } catch (Throwable) {
        return null;
    }

    return is_array($item) ? $item : null;
}

function zotero_get_or_create_collection_key(string $collectionName): string
{
    $collectionName = trim($collectionName);
    if ($collectionName === '') {
        return '';
    }

    $collections = zotero_fetch_collections();
    foreach ($collections as $collection) {
        if (!is_array($collection)) {
            continue;
        }
        $name = trim((string) ($collection['data']['name'] ?? ''));
        $key = trim((string) ($collection['key'] ?? ''));
        if ($key !== '' && strcasecmp($name, $collectionName) === 0) {
            return $key;
        }
    }

    $url = sprintf('https://api.zotero.org/%s/collections?format=json', zotero_library_path());
    $result = zotero_http_json('POST', $url, [['name' => $collectionName]], zotero_headers());
    $key = (string) (($result['successful']['0']['key'] ?? '') ?: '');
    if ($key === '') {
        throw new RuntimeException('Could not create Zotero collection "' . $collectionName . '".');
    }

    return $key;
}

function zotero_resolve_auto_collection_key(): string
{
    if (!zotero_auto_collection_enabled()) {
        return '';
    }
    $name = zotero_auto_collection_name();
    $cachedKey = trim((string) setting('zotero_exlibris_collection_key', ''));
    $collections = zotero_fetch_collections();
    foreach ($collections as $collection) {
        if (!is_array($collection)) {
            continue;
        }
        $key = trim((string) ($collection['key'] ?? ''));
        $rowName = trim((string) ($collection['data']['name'] ?? ''));
        if ($cachedKey !== '' && $key === $cachedKey) {
            if (strcasecmp($rowName, $name) === 0) {
                return $key;
            }
            break;
        }
    }

    $key = '';
    foreach ($collections as $collection) {
        if (!is_array($collection)) {
            continue;
        }
        $rowName = trim((string) ($collection['data']['name'] ?? ''));
        $rowKey = trim((string) ($collection['key'] ?? ''));
        if ($rowKey !== '' && strcasecmp($rowName, $name) === 0) {
            $key = $rowKey;
            break;
        }
    }

    if ($key === '') {
        $key = zotero_get_or_create_collection_key($name);
    }
    set_setting('zotero_exlibris_collection_key', $key);

    return $key;
}

function zotero_item_to_source(array $item): array
{
    $data = $item['data'] ?? [];
    $creators = $data['creators'] ?? [];
    $authors = [];
    if (is_array($creators)) {
        foreach ($creators as $creator) {
            if (!is_array($creator)) {
                continue;
            }
            $name = trim((string) (($creator['name'] ?? '') ?: trim(($creator['lastName'] ?? '') . ', ' . ($creator['firstName'] ?? ''))));
            if ($name !== '') {
                $authors[] = $name;
            }
        }
    }

    $date = (string) ($data['date'] ?? '');
    $year = '';
    if (preg_match('/\b(19|20)\d{2}\b/', $date, $m)) {
        $year = $m[0];
    }

    $itemType = strtolower((string) ($data['itemType'] ?? ''));
    $mappedType = match ($itemType) {
        'journalarticle', 'conferencepaper' => 'article',
        'book', 'booksection' => 'book',
        'videorecording', 'film' => 'video',
        default => 'other',
    };

    return [
        'type' => $mappedType,
        'title' => (string) ($data['title'] ?? ''),
        'authors' => $authors,
        'year' => $year,
        'publisher' => (string) (($data['publisher'] ?? '') ?: ($data['libraryCatalog'] ?? '')),
        'journal' => (string) (($data['publicationTitle'] ?? '') ?: ($data['bookTitle'] ?? '')),
        'volume' => (string) ($data['volume'] ?? ''),
        'issue' => (string) ($data['issue'] ?? ''),
        'pages' => (string) ($data['pages'] ?? ''),
        'doi' => (string) ($data['DOI'] ?? ''),
        'isbn' => preg_replace('/[^0-9Xx]/', '', (string) ($data['ISBN'] ?? '')) ?? '',
        'url' => (string) ($data['url'] ?? ''),
        'accessed_at' => (string) ($data['accessDate'] ?? ''),
        'notes' => trim('Imported from Zotero. ' . (string) ($data['extra'] ?? '')),
        'raw_input' => json_encode($item, JSON_UNESCAPED_UNICODE),
        'origin_provider' => 'zotero',
        'origin_external_id' => (string) ($item['key'] ?? ''),
        'origin_updated_at' => (string) ($data['dateModified'] ?? ''),
        'zotero_item_key' => (string) ($item['key'] ?? ''),
        'zotero_version' => isset($item['version']) ? (int) $item['version'] : null,
        'zotero_synced_at' => gmdate('c'),
        'project_external_keys' => is_array($data['collections'] ?? null) ? array_values(array_map('strval', $data['collections'])) : [],
    ];
}

function zotero_collection_to_project(array $collection): array
{
    $data = $collection['data'] ?? [];
    $name = trim((string) ($data['name'] ?? ''));
    $key = trim((string) ($collection['key'] ?? ''));
    $parent = trim((string) ($data['parentCollection'] ?? ''));

    return [
        'name' => $name !== '' ? $name : ('Collection ' . $key),
        'external_provider' => 'zotero',
        'external_id' => $key,
        'sync_meta' => [
            'parent_collection' => $parent,
            'version' => $collection['version'] ?? null,
        ],
    ];
}

function source_fingerprint(array $source): string
{
    return strtolower(implode('|', [
        trim((string) ($source['doi'] ?? '')),
        trim((string) ($source['isbn'] ?? '')),
        trim((string) ($source['title'] ?? '')),
        trim((string) (($source['authors'][0] ?? ''))),
        trim((string) ($source['year'] ?? '')),
    ]));
}

function zotero_http_json(string $method, string $url, array $payload = [], array $headers = []): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Unable to encode Zotero payload');
    }
    $ch = curl_init($url);
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Accept: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    if (!is_string($raw)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Zotero request failed: ' . $err);
    }
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $responseBody = substr($raw, $headerSize);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Zotero request returned status ' . $code);
    }
    $decoded = json_decode($responseBody, true);

    return is_array($decoded) ? $decoded : [];
}

function source_to_zotero_item_data(array $source, array $collectionKeys = []): array
{
    $creators = [];
    foreach (($source['authors'] ?? []) as $author) {
        $name = trim((string) $author);
        if ($name === '') {
            continue;
        }
        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));
            $creators[] = [
                'creatorType' => 'author',
                'firstName' => $first,
                'lastName' => $last,
            ];
        } else {
            $creators[] = [
                'creatorType' => 'author',
                'name' => $name,
            ];
        }
    }

    $type = strtolower((string) ($source['type'] ?? 'other'));
    $itemType = match ($type) {
        'article' => 'journalArticle',
        'book' => 'book',
        'video' => 'videoRecording',
        default => 'document',
    };

    $item = [
        'itemType' => $itemType,
        'title' => (string) ($source['title'] ?? ''),
        'creators' => $creators,
        'date' => (string) ($source['year'] ?? ''),
        'url' => (string) ($source['url'] ?? ''),
        'accessDate' => (string) ($source['accessed_at'] ?? ''),
        'extra' => 'Synced from Ex Libris',
        'collections' => array_values(array_unique(array_filter(array_map('strval', $collectionKeys)))),
    ];

    if ($itemType === 'journalArticle') {
        $item['publicationTitle'] = (string) ($source['journal'] ?? '');
        $item['volume'] = (string) ($source['volume'] ?? '');
        $item['issue'] = (string) ($source['issue'] ?? '');
        $item['pages'] = (string) ($source['pages'] ?? '');
        $item['DOI'] = (string) ($source['doi'] ?? '');
        $item['publisher'] = (string) ($source['publisher'] ?? '');
    } elseif ($itemType === 'book') {
        $item['publisher'] = (string) ($source['publisher'] ?? '');
        $item['ISBN'] = (string) ($source['isbn'] ?? '');
        $item['numPages'] = (string) ($source['pages'] ?? '');
    } elseif ($itemType === 'videoRecording') {
        $item['studio'] = (string) ($source['publisher'] ?? '');
        $item['runningTime'] = '';
    }

    return $item;
}

function zotero_resolve_collection_keys_for_source(array $source): array
{
    $keys = [];
    $projectIds = $source['project_ids'] ?? [];
    if (!is_array($projectIds)) {
        $projectIds = [];
    }

    foreach ($projectIds as $projectIdRaw) {
        $projectId = (int) $projectIdRaw;
        if ($projectId <= 0) {
            continue;
        }
        $project = get_project($projectId);
        if (!is_array($project)) {
            continue;
        }
        $externalProvider = trim((string) ($project['external_provider'] ?? ''));
        $externalId = trim((string) ($project['external_id'] ?? ''));
        if ($externalProvider === 'zotero' && $externalId !== '') {
            $keys[] = $externalId;
            continue;
        }
        $name = trim((string) ($project['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = zotero_get_or_create_collection_key($name);
        if ($key === '') {
            continue;
        }
        set_project_external_link($projectId, 'zotero', $key);
        $keys[] = $key;
    }

    return array_values(array_unique(array_filter(array_map('strval', $keys))));
}

function zotero_push_source(array $source): array
{
    $path = zotero_library_path();
    $headers = zotero_headers();
    $autoCollectionKey = zotero_resolve_auto_collection_key();
    $collectionKeys = zotero_resolve_collection_keys_for_source($source);
    if ($autoCollectionKey !== '') {
        $collectionKeys[] = $autoCollectionKey;
    }
    $collectionKeys = array_values(array_unique(array_filter(array_map('strval', $collectionKeys))));
    $itemKey = trim((string) ($source['zotero_item_key'] ?? ''));

    if ($itemKey !== '') {
        $existing = zotero_fetch_item($itemKey);
        $existingCollections = [];
        if (is_array($existing)) {
            $existingCollections = $existing['data']['collections'] ?? [];
            if (!is_array($existingCollections)) {
                $existingCollections = [];
            }
        }
        $data = source_to_zotero_item_data($source, array_values(array_unique(array_merge(
            array_map('strval', $existingCollections),
            $collectionKeys
        ))));
        $url = sprintf('https://api.zotero.org/%s/items/%s', $path, rawurlencode($itemKey));
        $result = zotero_http_json('PUT', $url, $data, $headers);
        $failed = $result['failed'][0]['message'] ?? '';
        if (is_string($failed) && trim($failed) !== '') {
            throw new RuntimeException('Zotero update failed: ' . $failed);
        }
        return [
            'key' => $itemKey,
            'created' => false,
            'collection_keys' => $collectionKeys,
        ];
    }

    $data = source_to_zotero_item_data($source, $collectionKeys);
    $url = sprintf('https://api.zotero.org/%s/items?format=json', $path);
    $result = zotero_http_json('POST', $url, [$data], $headers);
    $failed = $result['failed'][0]['message'] ?? '';
    if (is_string($failed) && trim($failed) !== '') {
        throw new RuntimeException('Zotero create failed: ' . $failed);
    }
    $key = (string) (($result['successful']['0']['key'] ?? '') ?: '');
    if ($key === '') {
        $key = (string) (($result['success']['0'] ?? '') ?: '');
    }
    if ($key === '') {
        throw new RuntimeException('Zotero push succeeded but item key was not returned.');
    }

    return [
        'key' => $key,
        'created' => true,
        'collection_keys' => $collectionKeys,
    ];
}
