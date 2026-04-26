<?php
declare(strict_types=1);

require_once __DIR__ . '/viewer_markdown_plain.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config.php';
    $db = $config['db'];
    // macOS + libpq + php-fpm can crash during implicit Kerberos/GSS
    // negotiation in PQconnectdb on local connections. Disable GSS
    // transport explicitly for this app to avoid 502s from worker segfaults.
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;gssencmode=disable', $db['host'], $db['port'], $db['name']);

    $pdo = new PDO(
        $dsn,
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    ensure_schema($pdo);

    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
    } catch (Throwable) {
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS projects (
            id BIGSERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT NULL,
            external_provider TEXT NOT NULL DEFAULT \'\',
            external_id TEXT NOT NULL DEFAULT \'\',
            sync_meta JSONB NOT NULL DEFAULT \'{}\'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sources (
            id BIGSERIAL PRIMARY KEY,
            type TEXT NOT NULL DEFAULT \'other\',
            title TEXT NOT NULL DEFAULT \'\',
            authors JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            year TEXT NOT NULL DEFAULT \'\',
            publisher TEXT NOT NULL DEFAULT \'\',
            journal TEXT NOT NULL DEFAULT \'\',
            volume TEXT NOT NULL DEFAULT \'\',
            issue TEXT NOT NULL DEFAULT \'\',
            pages TEXT NOT NULL DEFAULT \'\',
            doi TEXT NOT NULL DEFAULT \'\',
            isbn TEXT NOT NULL DEFAULT \'\',
            url TEXT NOT NULL DEFAULT \'\',
            accessed_at TEXT NOT NULL DEFAULT \'\',
            raw_input TEXT NOT NULL DEFAULT \'\',
            notes TEXT NOT NULL DEFAULT \'\',
            lookup_trace JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            provenance_summary TEXT NOT NULL DEFAULT \'\',
            body_text TEXT NOT NULL DEFAULT \'\',
            body_fetched_at TIMESTAMPTZ NULL,
            body_source TEXT NOT NULL DEFAULT \'\',
            citation_cache JSONB NOT NULL DEFAULT \'{}\'::jsonb,
            quality_score DOUBLE PRECISION NULL,
            quality_reason TEXT NOT NULL DEFAULT \'\',
            ai_summary TEXT NOT NULL DEFAULT \'\',
            ai_claims JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            ai_methods JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            ai_limitations JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            theme_labels JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            origin_provider TEXT NOT NULL DEFAULT \'\',
            origin_external_id TEXT NOT NULL DEFAULT \'\',
            origin_updated_at TIMESTAMPTZ NULL,
            zotero_item_key TEXT NOT NULL DEFAULT \'\',
            zotero_version BIGINT NULL,
            zotero_synced_at TIMESTAMPTZ NULL,
            pdf_path TEXT NOT NULL DEFAULT \'\',
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec('ALTER TABLE projects ADD COLUMN IF NOT EXISTS external_provider TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE projects ADD COLUMN IF NOT EXISTS external_id TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE projects ADD COLUMN IF NOT EXISTS sync_meta JSONB NOT NULL DEFAULT \'{}\'::jsonb');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS origin_provider TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS origin_external_id TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS origin_updated_at TIMESTAMPTZ NULL');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS zotero_item_key TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS zotero_version BIGINT NULL');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS zotero_synced_at TIMESTAMPTZ NULL');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS pdf_path TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS body_text TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS body_fetched_at TIMESTAMPTZ NULL');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS body_source TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS lookup_trace JSONB NOT NULL DEFAULT \'[]\'::jsonb');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS provenance_summary TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec('ALTER TABLE sources ADD COLUMN IF NOT EXISTS reader_synthesis JSONB NOT NULL DEFAULT \'{}\'::jsonb');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS source_project (
            source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            PRIMARY KEY (source_id, project_id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS source_embeddings (
            source_id BIGINT PRIMARY KEY REFERENCES sources(id) ON DELETE CASCADE,
            model TEXT NOT NULL,
            embedding_json JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS assistant_runs (
            id BIGSERIAL PRIMARY KEY,
            run_type TEXT NOT NULL,
            source_id BIGINT NULL REFERENCES sources(id) ON DELETE CASCADE,
            input_text TEXT NOT NULL DEFAULT \'\',
            output_json JSONB NOT NULL DEFAULT \'{}\'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS draft_claim_links (
            id BIGSERIAL PRIMARY KEY,
            claim_text TEXT NOT NULL,
            source_id BIGINT NULL REFERENCES sources(id) ON DELETE CASCADE,
            confidence DOUBLE PRECISION NULL,
            rationale TEXT NOT NULL DEFAULT \'\',
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS digest_runs (
            id BIGSERIAL PRIMARY KEY,
            digest_text TEXT NOT NULL,
            digest_json JSONB NOT NULL DEFAULT \'{}\'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS source_notes (
            id BIGSERIAL PRIMARY KEY,
            source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
            quote_text TEXT NOT NULL DEFAULT \'\',
            start_offset INTEGER NOT NULL DEFAULT 0,
            end_offset INTEGER NOT NULL DEFAULT 0,
            note_text TEXT NOT NULL DEFAULT \'\',
            tag_labels JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            project_ids JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec('ALTER TABLE source_notes ADD COLUMN IF NOT EXISTS note_scope TEXT NOT NULL DEFAULT \'body\'');

    $ready = true;
}

function data_dir(): string
{
    $config = require __DIR__ . '/../config.php';
    $dir = (string) ($config['storage']['data_dir'] ?? (__DIR__ . '/../data'));
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function source_markdown_dir(): string
{
    $dir = data_dir() . '/sources-md';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function source_markdown_path(int $id): string
{
    return source_markdown_dir() . '/source-' . $id . '.md';
}

function write_source_markdown(array $source): void
{
    $id = (int) ($source['id'] ?? 0);
    if ($id <= 0) {
        return;
    }

    $authors = implode(', ', array_values(array_map('strval', $source['authors'] ?? [])));
    $content = implode("\n", [
        '# ' . ((string) ($source['title'] ?? '') !== '' ? (string) $source['title'] : 'Untitled Source'),
        '',
        '- ID: ' . $id,
        '- Type: ' . (string) ($source['type'] ?? ''),
        '- Authors: ' . $authors,
        '- Year: ' . (string) ($source['year'] ?? ''),
        '- Journal: ' . (string) ($source['journal'] ?? ''),
        '- Publisher: ' . (string) ($source['publisher'] ?? ''),
        '- Volume: ' . (string) ($source['volume'] ?? ''),
        '- Issue: ' . (string) ($source['issue'] ?? ''),
        '- Pages: ' . (string) ($source['pages'] ?? ''),
        '- DOI: ' . (string) ($source['doi'] ?? ''),
        '- ISBN: ' . (string) ($source['isbn'] ?? ''),
        '- URL: ' . (string) ($source['url'] ?? ''),
        '- Accessed At: ' . (string) ($source['accessed_at'] ?? ''),
        '- Updated: ' . (string) ($source['updated_at'] ?? ''),
        '',
        '## Notes',
        '',
        (string) ($source['notes'] ?? ''),
        '',
        '## Raw Input',
        '',
        (string) ($source['raw_input'] ?? ''),
        '',
    ]);

    file_put_contents(source_markdown_path($id), $content);
}

function setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return $default;
    }

    return (string) ($row['value'] ?? $default ?? '');
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (key, value) VALUES (:key, :value)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
    );
    $stmt->execute(['key' => $key, 'value' => $value]);
}

function ensure_defaults(): void
{
    if (setting('citation_format') === null) {
        set_setting('citation_format', 'apa');
    }
    if (setting('theme_mode') === null) {
        set_setting('theme_mode', 'auto');
    }
    if (setting('include_pages_in_citations') === null) {
        set_setting('include_pages_in_citations', '1');
    }
    if (setting('assistant_enabled') === null) {
        set_setting('assistant_enabled', '1');
    }
    if (setting('zotero_auto_collection_enabled') === null) {
        set_setting('zotero_auto_collection_enabled', '1');
    }
    if (setting('zotero_auto_collection_name') === null) {
        set_setting('zotero_auto_collection_name', 'Ex Libris');
    }
}

function list_sources(): array
{
    return db()->query('SELECT * FROM sources ORDER BY created_at DESC')->fetchAll() ?: [];
}

function list_projects(): array
{
    return db()->query('SELECT * FROM projects ORDER BY name ASC')->fetchAll() ?: [];
}

function project_ids_for_source(int $sourceId): array
{
    $stmt = db()->prepare('SELECT project_id FROM source_project WHERE source_id = :source_id ORDER BY project_id ASC');
    $stmt->execute(['source_id' => $sourceId]);
    $rows = $stmt->fetchAll() ?: [];

    return array_values(array_map(static fn (array $r): int => (int) ($r['project_id'] ?? 0), $rows));
}

function set_source_projects(int $sourceId, array $projectIds): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn (int $id): bool => $id > 0)));
    $delete = db()->prepare('DELETE FROM source_project WHERE source_id = :source_id');
    $delete->execute(['source_id' => $sourceId]);
    if ($ids === []) {
        return;
    }

    $insert = db()->prepare(
        'INSERT INTO source_project (source_id, project_id) VALUES (:source_id, :project_id)
         ON CONFLICT DO NOTHING'
    );
    foreach ($ids as $projectId) {
        $insert->execute([
            'source_id' => $sourceId,
            'project_id' => $projectId,
        ]);
    }
}

function get_or_create_project_by_external(string $provider, string $externalId, string $name): int
{
    $provider = trim($provider);
    $externalId = trim($externalId);
    $name = trim($name);
    if ($provider === '' || $externalId === '') {
        return 0;
    }

    $select = db()->prepare(
        'SELECT id FROM projects
         WHERE external_provider = :external_provider AND external_id = :external_id
         LIMIT 1'
    );
    $select->execute([
        'external_provider' => $provider,
        'external_id' => $externalId,
    ]);
    $row = $select->fetch();
    if (is_array($row)) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && $name !== '') {
            $update = db()->prepare('UPDATE projects SET name = :name WHERE id = :id');
            $update->execute(['name' => $name, 'id' => $id]);
        }

        return $id;
    }

    $insert = db()->prepare(
        'INSERT INTO projects (name, external_provider, external_id, sync_meta)
         VALUES (:name, :external_provider, :external_id, \'{}\'::jsonb)
         RETURNING id'
    );
    $insert->execute([
        'name' => $name !== '' ? $name : ('Collection ' . $externalId),
        'external_provider' => $provider,
        'external_id' => $externalId,
    ]);

    return (int) ($insert->fetch()['id'] ?? 0);
}

function get_project(int $projectId): ?array
{
    if ($projectId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $projectId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function get_or_create_project_by_name(string $name): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    $select = db()->prepare('SELECT id FROM projects WHERE lower(name) = lower(:name) LIMIT 1');
    $select->execute(['name' => $name]);
    $row = $select->fetch();
    if (is_array($row)) {
        return (int) ($row['id'] ?? 0);
    }

    $insert = db()->prepare(
        'INSERT INTO projects (name, description, external_provider, external_id, sync_meta)
         VALUES (:name, \'\', \'\', \'\', \'{}\'::jsonb) RETURNING id'
    );
    $insert->execute(['name' => $name]);

    return (int) ($insert->fetch()['id'] ?? 0);
}

function set_project_external_link(int $projectId, string $provider, string $externalId): void
{
    if ($projectId <= 0) {
        return;
    }
    $stmt = db()->prepare(
        'UPDATE projects
         SET external_provider = :external_provider, external_id = :external_id
         WHERE id = :id'
    );
    $stmt->execute([
        'external_provider' => trim($provider),
        'external_id' => trim($externalId),
        'id' => $projectId,
    ]);
}

function projects_for_source_ids(array $sourceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $params = [];
    $holders = [];
    foreach ($ids as $idx => $id) {
        $key = ':id' . $idx;
        $holders[] = $key;
        $params['id' . $idx] = $id;
    }
    $sql = 'SELECT sp.source_id, p.id AS project_id, p.name
            FROM source_project sp
            JOIN projects p ON p.id = sp.project_id
            WHERE sp.source_id IN (' . implode(', ', $holders) . ')
            ORDER BY p.name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $out = [];
    foreach ($rows as $row) {
        $sourceId = (int) ($row['source_id'] ?? 0);
        $out[$sourceId] ??= [];
        $out[$sourceId][] = [
            'id' => (int) ($row['project_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    return $out;
}

function source_note_counts_for_source_ids(array $sourceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $params = [];
    $holders = [];
    foreach ($ids as $idx => $id) {
        $key = ':id' . $idx;
        $holders[] = $key;
        $params['id' . $idx] = $id;
    }

    $sql = 'SELECT source_id, COUNT(*) AS note_count
            FROM source_notes
            WHERE source_id IN (' . implode(', ', $holders) . ')
              AND note_scope = \'body\'
            GROUP BY source_id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $out = [];
    foreach ($rows as $row) {
        $out[(int) ($row['source_id'] ?? 0)] = (int) ($row['note_count'] ?? 0);
    }

    return $out;
}

function get_source(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM sources WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function save_source(array $source): array
{
    $id = (int) ($source['id'] ?? 0);
    $now = gmdate('c');
    $existingRow = $id > 0 ? get_source($id) : null;
    $pick = static function (string $key, mixed $fallback = '') use ($source, $existingRow): mixed {
        if (array_key_exists($key, $source)) {
            return $source[$key];
        }
        if (is_array($existingRow) && array_key_exists($key, $existingRow)) {
            return $existingRow[$key];
        }

        return $fallback;
    };
    $existingAuthors = [];
    if (is_array($existingRow)) {
        $existingAuthors = $existingRow['authors'] ?? [];
        if (is_string($existingAuthors)) {
            $existingAuthors = json_decode($existingAuthors, true);
        }
        if (!is_array($existingAuthors)) {
            $existingAuthors = [];
        }
    }

    $authorsValue = $pick('authors', $existingAuthors);
    if (!is_array($authorsValue)) {
        $authorsValue = [];
    }
    $existingProjectIds = $id > 0 ? project_ids_for_source($id) : [];
    $projectIdsValue = $pick('project_ids', $existingProjectIds);
    if (!is_array($projectIdsValue)) {
        $projectIdsValue = [];
    }
    $projectNamesValue = $pick('project_names', []);
    if (!is_array($projectNamesValue)) {
        $projectNamesValue = [];
    }
    if ($projectNamesValue !== []) {
        $resolvedProjectIds = [];
        foreach ($projectNamesValue as $projectName) {
            $projectId = get_or_create_project_by_name((string) $projectName);
            if ($projectId > 0) {
                $resolvedProjectIds[] = $projectId;
            }
        }
        $projectIdsValue = $resolvedProjectIds;
    }

    $payload = [
        'type' => (string) $pick('type', 'other'),
        'title' => (string) $pick('title', ''),
        'authors' => json_encode(array_values(array_filter(array_map('strval', $authorsValue))), JSON_UNESCAPED_UNICODE),
        'year' => (string) $pick('year', ''),
        'publisher' => (string) $pick('publisher', ''),
        'journal' => (string) $pick('journal', ''),
        'volume' => (string) $pick('volume', ''),
        'issue' => (string) $pick('issue', ''),
        'pages' => (string) $pick('pages', ''),
        'doi' => (string) $pick('doi', ''),
        'isbn' => (string) $pick('isbn', ''),
        'url' => (string) $pick('url', ''),
        'accessed_at' => (string) $pick('accessed_at', ''),
        'raw_input' => (string) $pick('raw_input', ''),
        'notes' => (string) $pick('notes', ''),
        'lookup_trace' => json_encode(is_array($pick('lookup_trace', [])) ? $pick('lookup_trace', []) : [], JSON_UNESCAPED_UNICODE),
        'provenance_summary' => (string) $pick('provenance_summary', ''),
        'body_text' => (string) $pick('body_text', ''),
        'body_fetched_at' => (string) $pick('body_fetched_at', ''),
        'body_source' => (string) $pick('body_source', ''),
        'citation_cache' => json_encode(is_array($pick('citation_cache', [])) ? $pick('citation_cache', []) : [], JSON_UNESCAPED_UNICODE),
        'quality_score' => $pick('quality_score', null),
        'quality_reason' => (string) $pick('quality_reason', ''),
        'ai_summary' => (string) $pick('ai_summary', ''),
        'ai_claims' => json_encode(is_array($pick('ai_claims', [])) ? $pick('ai_claims', []) : [], JSON_UNESCAPED_UNICODE),
        'ai_methods' => json_encode(is_array($pick('ai_methods', [])) ? $pick('ai_methods', []) : [], JSON_UNESCAPED_UNICODE),
        'ai_limitations' => json_encode(is_array($pick('ai_limitations', [])) ? $pick('ai_limitations', []) : [], JSON_UNESCAPED_UNICODE),
        'theme_labels' => json_encode(is_array($pick('theme_labels', [])) ? $pick('theme_labels', []) : [], JSON_UNESCAPED_UNICODE),
        'origin_provider' => (string) $pick('origin_provider', ''),
        'origin_external_id' => (string) $pick('origin_external_id', ''),
        'origin_updated_at' => (string) $pick('origin_updated_at', ''),
        'zotero_item_key' => (string) $pick('zotero_item_key', ''),
        'zotero_version' => $pick('zotero_version', null),
        'zotero_synced_at' => (string) $pick('zotero_synced_at', ''),
        'pdf_path' => (string) $pick('pdf_path', ''),
        'reader_synthesis' => json_encode(is_array($pick('reader_synthesis', [])) ? $pick('reader_synthesis', []) : [], JSON_UNESCAPED_UNICODE),
        'updated_at' => $now,
    ];

    if ($id > 0) {
        $stmt = db()->prepare(
            'UPDATE sources SET
                type=:type, title=:title, authors=CAST(:authors AS jsonb), year=:year,
                publisher=:publisher, journal=:journal, volume=:volume, issue=:issue,
                pages=:pages, doi=:doi, isbn=:isbn, url=:url, accessed_at=:accessed_at,
                raw_input=:raw_input, notes=:notes,
                lookup_trace=CAST(:lookup_trace AS jsonb), provenance_summary=:provenance_summary,
                body_text=:body_text, body_fetched_at=NULLIF(:body_fetched_at, \'\')::timestamptz, body_source=:body_source,
                citation_cache=CAST(:citation_cache AS jsonb),
                quality_score=:quality_score, quality_reason=:quality_reason,
                ai_summary=:ai_summary, ai_claims=CAST(:ai_claims AS jsonb),
                ai_methods=CAST(:ai_methods AS jsonb), ai_limitations=CAST(:ai_limitations AS jsonb),
                theme_labels=CAST(:theme_labels AS jsonb),
                origin_provider=:origin_provider, origin_external_id=:origin_external_id,
                origin_updated_at=NULLIF(:origin_updated_at, \'\')::timestamptz,
                zotero_item_key=:zotero_item_key,
                zotero_version=NULLIF(CAST(:zotero_version AS text), \'\')::bigint,
                zotero_synced_at=NULLIF(:zotero_synced_at, \'\')::timestamptz,
                pdf_path=:pdf_path,
                reader_synthesis=CAST(:reader_synthesis AS jsonb),
                updated_at=:updated_at
             WHERE id=:id'
        );
        $params = $payload;
        $params['id'] = $id;
        $stmt->execute($params);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO sources (
                type,title,authors,year,publisher,journal,volume,issue,pages,doi,isbn,url,
                accessed_at,raw_input,notes,lookup_trace,provenance_summary,body_text,body_fetched_at,body_source,citation_cache,quality_score,quality_reason,
                ai_summary,ai_claims,ai_methods,ai_limitations,theme_labels,
                origin_provider,origin_external_id,origin_updated_at,zotero_item_key,zotero_version,zotero_synced_at,
                pdf_path,reader_synthesis,
                created_at,updated_at
            ) VALUES (
                :type,:title,CAST(:authors AS jsonb),:year,:publisher,:journal,:volume,:issue,:pages,:doi,:isbn,:url,
                :accessed_at,:raw_input,:notes,CAST(:lookup_trace AS jsonb),:provenance_summary,:body_text,NULLIF(:body_fetched_at, \'\')::timestamptz,:body_source,CAST(:citation_cache AS jsonb),:quality_score,:quality_reason,
                :ai_summary,CAST(:ai_claims AS jsonb),CAST(:ai_methods AS jsonb),CAST(:ai_limitations AS jsonb),CAST(:theme_labels AS jsonb),
                :origin_provider,:origin_external_id,NULLIF(:origin_updated_at, \'\')::timestamptz,:zotero_item_key,NULLIF(CAST(:zotero_version AS text), \'\')::bigint,NULLIF(:zotero_synced_at, \'\')::timestamptz,
                :pdf_path,CAST(:reader_synthesis AS jsonb),
                :created_at,:updated_at
            ) RETURNING id'
        );
        $params = $payload;
        $params['created_at'] = $now;
        $stmt->execute($params);
        $id = (int) ($stmt->fetch()['id'] ?? 0);
    }

    $row = get_source($id);
    if (!is_array($row)) {
        throw new RuntimeException('Failed to fetch saved source');
    }
    set_source_projects($id, $projectIdsValue);
    write_source_markdown(source_row_to_markdown_input($row));

    return $row;
}

function delete_source(int $id): bool
{
    $deleteLinks = db()->prepare('DELETE FROM source_project WHERE source_id = :id');
    $deleteLinks->execute(['id' => $id]);
    $stmt = db()->prepare('DELETE FROM sources WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $mdPath = source_markdown_path($id);
    if (file_exists($mdPath)) {
        unlink($mdPath);
    }

    return $stmt->rowCount() > 0;
}

function source_row_to_markdown_input(array $row): array
{
    $authors = $row['authors'] ?? [];
    if (is_string($authors)) {
        $authors = json_decode($authors, true);
    }
    if (!is_array($authors)) {
        $authors = [];
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'type' => (string) ($row['type'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'authors' => $authors,
        'year' => (string) ($row['year'] ?? ''),
        'journal' => (string) ($row['journal'] ?? ''),
        'publisher' => (string) ($row['publisher'] ?? ''),
        'volume' => (string) ($row['volume'] ?? ''),
        'issue' => (string) ($row['issue'] ?? ''),
        'pages' => (string) ($row['pages'] ?? ''),
        'doi' => (string) ($row['doi'] ?? ''),
        'isbn' => (string) ($row['isbn'] ?? ''),
        'url' => (string) ($row['url'] ?? ''),
        'accessed_at' => (string) ($row['accessed_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'raw_input' => (string) ($row['raw_input'] ?? ''),
    ];
}

function source_note_to_array(array $row): array
{
    $decodeArray = static function (mixed $value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    };

    $rawScope = (string) ($row['note_scope'] ?? 'body');
    $noteScope = in_array($rawScope, ['body', 'reading_guide'], true)
        ? $rawScope
        : 'body';

    return [
        'id' => (int) ($row['id'] ?? 0),
        'source_id' => (int) ($row['source_id'] ?? 0),
        'quote_text' => (string) ($row['quote_text'] ?? ''),
        'start_offset' => max(0, (int) ($row['start_offset'] ?? 0)),
        'end_offset' => max(0, (int) ($row['end_offset'] ?? 0)),
        'note_text' => (string) ($row['note_text'] ?? ''),
        'tag_labels' => array_values(array_filter(array_map('strval', $decodeArray($row['tag_labels'] ?? [])))),
        'project_ids' => array_values(array_filter(array_map('intval', $decodeArray($row['project_ids'] ?? [])), static fn (int $id): bool => $id > 0)),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'note_scope' => $noteScope,
    ];
}

function list_source_notes(int $sourceId, string $noteScope = 'body'): array
{
    if ($sourceId <= 0) {
        return [];
    }
    $scope = in_array($noteScope, ['body', 'reading_guide'], true) ? $noteScope : 'body';
    $stmt = db()->prepare(
        'SELECT * FROM source_notes WHERE source_id = :source_id AND note_scope = :note_scope ORDER BY start_offset ASC, id ASC'
    );
    $stmt->execute(['source_id' => $sourceId, 'note_scope' => $scope]);
    $rows = $stmt->fetchAll() ?: [];

    return array_values(array_map(static fn (array $row): array => source_note_to_array($row), $rows));
}

function get_source_note(int $noteId): ?array
{
    if ($noteId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM source_notes WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $noteId]);
    $row = $stmt->fetch();

    return is_array($row) ? source_note_to_array($row) : null;
}

function create_source_note(array $note): array
{
    $sourceId = (int) ($note['source_id'] ?? 0);
    if ($sourceId <= 0) {
        throw new RuntimeException('Valid source_id is required.');
    }
    $source = get_source($sourceId);
    if (!is_array($source)) {
        throw new RuntimeException('Source not found.');
    }
    $noteScope = trim((string) ($note['note_scope'] ?? 'body'));
    if ($noteScope === '') {
        $noteScope = 'body';
    }
    if (!in_array($noteScope, ['body', 'reading_guide'], true)) {
        throw new RuntimeException('Invalid note scope.');
    }

    $bodyText = (string) ($source['body_text'] ?? '');
    $readingGuideRaw = reading_guide_markdown_for_viewer($source);
    $readingGuideTrim = trim($readingGuideRaw);
    $documentText = '';
    $startOffset = max(0, (int) ($note['start_offset'] ?? 0));
    $endOffset = max(0, (int) ($note['end_offset'] ?? 0));

    if ($noteScope === 'body') {
        if ($bodyText === '') {
            throw new RuntimeException('Source has no extracted text to annotate.');
        }
        $documentText = $bodyText;
    } else {
        if ($readingGuideTrim === '') {
            throw new RuntimeException('No AI reading guide text to annotate.');
        }
        $documentText = viewer_markdown_plain_text($readingGuideRaw);
    }

    if ($endOffset <= $startOffset) {
        throw new RuntimeException('Annotation range is invalid.');
    }
    $enc = 'UTF-8';
    $bodyLength = mb_strlen($documentText, $enc);
    $quoteTextRaw = trim((string) ($note['quote_text'] ?? ''));
    $sliceLen = max(0, $endOffset - $startOffset);
    $slice = $sliceLen > 0 ? mb_substr($documentText, $startOffset, $sliceLen, $enc) : '';
    $needsAlign = $endOffset > $bodyLength || ($quoteTextRaw !== '' && $slice !== $quoteTextRaw);
    if ($needsAlign && $quoteTextRaw !== '') {
        $aligned = viewer_align_note_range_to_quote($documentText, $startOffset, $endOffset, $quoteTextRaw, $enc);
        if (is_array($aligned)) {
            [$startOffset, $endOffset] = $aligned;
        }
    }
    if ($endOffset > $bodyLength) {
        throw new RuntimeException('Annotation range exceeds document text length.');
    }
    $verifySlice = mb_substr($documentText, $startOffset, $endOffset - $startOffset, $enc);
    if ($quoteTextRaw !== '' && trim($verifySlice) !== $quoteTextRaw) {
        throw new RuntimeException('Highlight does not match the current document text. Try selecting the passage again.');
    }

    $projectIds = $note['project_ids'] ?? [];
    if (!is_array($projectIds)) {
        $projectIds = [];
    }
    $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn (int $id): bool => $id > 0)));

    $tagLabels = $note['tag_labels'] ?? [];
    if (!is_array($tagLabels)) {
        $tagLabels = [];
    }
    $tagLabels = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $tagLabels))));

    $quoteText = $quoteTextRaw !== '' ? $quoteTextRaw : mb_substr($documentText, $startOffset, $endOffset - $startOffset, $enc);
    $noteText = trim((string) ($note['note_text'] ?? ''));
    if ($quoteText === '' || $noteText === '') {
        throw new RuntimeException('Both highlighted text and note text are required.');
    }

    foreach (list_source_notes($sourceId, $noteScope) as $existing) {
        $existingStart = (int) ($existing['start_offset'] ?? 0);
        $existingEnd = (int) ($existing['end_offset'] ?? 0);
        if ($startOffset < $existingEnd && $endOffset > $existingStart) {
            throw new RuntimeException('Highlights cannot overlap existing notes yet.');
        }
    }

    $now = gmdate('c');
    $stmt = db()->prepare(
        'INSERT INTO source_notes (
            source_id, note_scope, quote_text, start_offset, end_offset, note_text, tag_labels, project_ids, created_at, updated_at
        ) VALUES (
            :source_id, :note_scope, :quote_text, :start_offset, :end_offset, :note_text, CAST(:tag_labels AS jsonb), CAST(:project_ids AS jsonb), :created_at, :updated_at
        ) RETURNING *'
    );
    $stmt->execute([
        'source_id' => $sourceId,
        'note_scope' => $noteScope,
        'quote_text' => $quoteText,
        'start_offset' => $startOffset,
        'end_offset' => $endOffset,
        'note_text' => $noteText,
        'tag_labels' => json_encode($tagLabels, JSON_UNESCAPED_UNICODE),
        'project_ids' => json_encode($projectIds, JSON_UNESCAPED_UNICODE),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Failed to create source note.');
    }

    return source_note_to_array($row);
}

function delete_source_note(int $noteId): bool
{
    if ($noteId <= 0) {
        return false;
    }
    $stmt = db()->prepare('DELETE FROM source_notes WHERE id = :id');
    $stmt->execute(['id' => $noteId]);

    return $stmt->rowCount() > 0;
}
