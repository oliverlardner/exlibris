<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/formatter.php';
require_once __DIR__ . '/reader.php';

function assistant_handle_reader_synthesis(array $payload): array
{
    $sourceIds = is_array($payload['source_ids'] ?? null) ? $payload['source_ids'] : [];
    $context = trim((string) ($payload['context'] ?? ''));
    if ($sourceIds === [] && $context === '') {
        throw new InvalidArgumentException('Provide source_ids or context');
    }

    $expandK = max(0, min(5, (int) ($payload['expand_k'] ?? 3)));
    $gathered = reader_gather($sourceIds, $context, $expandK);
    $primarySources = is_array($gathered['primary'] ?? null) ? $gathered['primary'] : [];
    $expandedSources = is_array($gathered['expanded'] ?? null) ? $gathered['expanded'] : [];
    $trace = is_array($gathered['trace'] ?? null) ? $gathered['trace'] : [];
    if ($primarySources === [] && $expandedSources === []) {
        throw new RuntimeException('No matching sources found');
    }

    foreach ($primarySources as &$source) {
        if (!is_array($source)) {
            continue;
        }
        reader_fetch_body($source, $trace);
    }
    unset($source);
    foreach ($expandedSources as &$source) {
        if (!is_array($source)) {
            continue;
        }
        reader_fetch_body($source, $trace);
    }
    unset($source);
    $sources = array_values(array_merge($primarySources, $expandedSources));

    $scholarlyCandidates = reader_scholarly_search($context, $sources);
    $trace[] = [
        'step' => 'reader_scholarly_search',
        'status' => $scholarlyCandidates !== [] ? 'success' : 'no_result',
        'detail' => $scholarlyCandidates !== []
            ? ('Found ' . count($scholarlyCandidates) . ' scholarly candidate(s).')
            : 'No additional scholarly candidates found.',
    ];

    $dossier = reader_build_dossier($primarySources, $expandedSources, $scholarlyCandidates, $context);
    $synthesis = reader_synthesize($dossier, $context, $trace);
    $usage = is_array($synthesis['usage'] ?? null) ? $synthesis['usage'] : [];
    if (isset($synthesis['usage'])) {
        unset($synthesis['usage']);
    }

    $compactSources = static function (array $sourcesList): array {
        return array_values(array_map(static function (array $source): array {
            return [
                'id' => (int) ($source['id'] ?? 0),
                'title' => (string) ($source['title'] ?? ''),
                'authors' => is_array($source['authors'] ?? null) ? $source['authors'] : [],
                'year' => (string) ($source['year'] ?? ''),
                'url' => (string) ($source['url'] ?? ''),
            ];
        }, $sourcesList));
    };

    $sourcesCompact = $compactSources($sources);
    $primaryCompact = $compactSources($primarySources);
    $expandedCompact = $compactSources($expandedSources);

    return [
        'context' => $context,
        'sources' => $sourcesCompact,
        'primary_sources' => $primaryCompact,
        'expanded_sources' => $expandedCompact,
        'selected_source_ids' => array_values(array_map(static fn (array $source): int => (int) ($source['id'] ?? 0), $primarySources)),
        'expanded_source_ids' => array_values(array_map(static fn (array $source): int => (int) ($source['id'] ?? 0), $expandedSources)),
        'source_ids' => array_values(array_map(static fn (array $source): int => (int) ($source['id'] ?? 0), $sources)),
        'synthesis' => $synthesis,
        'trace' => $trace,
        'token_usage' => $usage,
    ];
}

function assistant_handle_reader_source_lookup(array $payload): array
{
    $idsRaw = is_array($payload['source_ids'] ?? null) ? $payload['source_ids'] : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsRaw), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return ['sources' => []];
    }

    $sources = [];
    foreach ($ids as $id) {
        $row = get_source($id);
        if (!is_array($row)) {
            continue;
        }
        $source = source_to_array($row);
        $sources[] = [
            'id' => (int) ($source['id'] ?? 0),
            'title' => (string) ($source['title'] ?? ''),
            'authors' => is_array($source['authors'] ?? null) ? $source['authors'] : [],
            'year' => (string) ($source['year'] ?? ''),
            'url' => (string) ($source['url'] ?? ''),
        ];
    }

    return ['sources' => $sources];
}

function assistant_handle_reader_history(array $payload): array
{
    $limit = max(1, min(50, (int) ($payload['limit'] ?? 20)));
    $stmt = db()->prepare(
        'SELECT id, input_text, output_json, created_at
         FROM assistant_runs
         WHERE run_type = :run_type
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':run_type', 'reader_synthesis', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    $runs = [];
    foreach ($rows as $row) {
        $outputRaw = $row['output_json'] ?? [];
        $output = is_string($outputRaw) ? json_decode($outputRaw, true) : $outputRaw;
        if (!is_array($output)) {
            $output = [];
        }
        $synthesis = is_array($output['synthesis'] ?? null) ? $output['synthesis'] : [];
        $runs[] = [
            'id' => (int) ($row['id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'input_text' => (string) ($row['input_text'] ?? ''),
            'summary' => [
                'verdict' => (string) ($synthesis['verdict'] ?? 'skim'),
                'preview' => mb_substr(trim((string) ($synthesis['why_now'] ?? ($synthesis['verdict_reason'] ?? ''))), 0, 180),
            ],
            'output_json' => $output,
        ];
    }

    return ['runs' => $runs];
}
