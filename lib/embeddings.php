<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/formatter.php';

function embedding_input_for_source(array $source): string
{
    $authors = implode(', ', array_values(array_map('strval', $source['authors'] ?? [])));

    return trim(implode("\n", [
        (string) ($source['title'] ?? ''),
        $authors,
        (string) ($source['year'] ?? ''),
        (string) ($source['publisher'] ?? ''),
        (string) ($source['journal'] ?? ''),
        (string) ($source['ai_summary'] ?? ''),
        (string) ($source['doi'] ?? ''),
        (string) ($source['isbn'] ?? ''),
        (string) ($source['url'] ?? ''),
        (string) ($source['provenance_summary'] ?? ''),
        (string) ($source['notes'] ?? ''),
        (string) ($source['raw_input'] ?? ''),
    ]));
}

function upsert_source_embedding(int $sourceId): void
{
    $row = get_source($sourceId);
    if (!is_array($row)) {
        return;
    }

    $source = source_to_array($row);
    $input = embedding_input_for_source($source);
    if ($input === '') {
        return;
    }

    $embedding = openai_embedding($input);
    if ($embedding === []) {
        return;
    }

    $model = config_value('ai', 'embedding_model', 'text-embedding-3-small');
    $stmt = db()->prepare(
        'INSERT INTO source_embeddings (source_id, model, embedding_json, updated_at)
         VALUES (:source_id, :model, CAST(:embedding_json AS jsonb), NOW())
         ON CONFLICT (source_id) DO UPDATE SET
            model = EXCLUDED.model,
            embedding_json = EXCLUDED.embedding_json,
            updated_at = NOW()'
    );
    $stmt->execute([
        'source_id' => $sourceId,
        'model' => (string) $model,
        'embedding_json' => json_encode($embedding, JSON_UNESCAPED_UNICODE),
    ]);
}

function cosine_similarity(array $a, array $b): float
{
    $n = min(count($a), count($b));
    if ($n === 0) {
        return 0.0;
    }

    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $va = (float) $a[$i];
        $vb = (float) $b[$i];
        $dot += $va * $vb;
        $na += $va * $va;
        $nb += $vb * $vb;
    }
    if ($na <= 0.0 || $nb <= 0.0) {
        return 0.0;
    }

    return $dot / (sqrt($na) * sqrt($nb));
}

function semantic_search_sources(string $query, int $limit = 10): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $queryVector = openai_embedding($query);
    if ($queryVector === []) {
        return [];
    }

    $rows = db()->query(
        'SELECT s.*, e.embedding_json
         FROM sources s
         JOIN source_embeddings e ON e.source_id = s.id'
    )->fetchAll() ?: [];

    $scored = [];
    foreach ($rows as $row) {
        $embeddingRaw = $row['embedding_json'] ?? '[]';
        $embedding = is_string($embeddingRaw) ? json_decode($embeddingRaw, true) : $embeddingRaw;
        if (!is_array($embedding)) {
            continue;
        }
        $scored[] = [
            'score' => cosine_similarity($queryVector, $embedding),
            'source' => source_to_array($row),
        ];
    }

    usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

    return array_slice($scored, 0, max(1, $limit));
}
