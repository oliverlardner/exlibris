<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/formatter.php';
require_once __DIR__ . '/../lib/openai.php';
require_once __DIR__ . '/../lib/embeddings.php';
require_once __DIR__ . '/../lib/assistant_reader.php';
require_once __DIR__ . '/../lib/assistant_claims.php';
require_once __DIR__ . '/../lib/assistant_dedupe.php';

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}
require_admin_for_write();

$payload = json_input();
$action = strtolower(trim((string) ($payload['action'] ?? '')));

if ($action === '') {
    json_response(['ok' => false, 'error' => 'action is required'], 422);
}

function assistant_store_run(string $type, ?int $sourceId, string $input, array $output): void
{
    $stmt = db()->prepare(
        'INSERT INTO assistant_runs (run_type, source_id, input_text, output_json)
         VALUES (:run_type, :source_id, :input_text, CAST(:output_json AS jsonb))'
    );
    $stmt->execute([
        'run_type' => $type,
        'source_id' => $sourceId,
        'input_text' => $input,
        'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE),
    ]);
}

function assistant_quality_score(array $source): array
{
    $score = 0.2;
    $reasons = [];
    if (trim((string) ($source['title'] ?? '')) !== '') {
        $score += 0.2;
        $reasons[] = 'has title';
    }
    if (($source['authors'] ?? []) !== []) {
        $score += 0.15;
        $reasons[] = 'has authors';
    }
    if (trim((string) ($source['year'] ?? '')) !== '') {
        $score += 0.15;
        $reasons[] = 'has year';
    }
    if (trim((string) ($source['doi'] ?? '')) !== '' || trim((string) ($source['isbn'] ?? '')) !== '') {
        $score += 0.2;
        $reasons[] = 'has persistent identifier';
    }
    if (trim((string) ($source['publisher'] ?? '')) !== '' || trim((string) ($source['journal'] ?? '')) !== '') {
        $score += 0.1;
        $reasons[] = 'has source venue';
    }
    if (trim((string) ($source['notes'] ?? '')) !== '') {
        $score += 0.1;
        $reasons[] = 'has notes';
    }

    $score = min(1.0, max(0.0, $score));
    return [
        'score' => round($score, 3),
        'reason' => implode(', ', $reasons),
    ];
}

try {
    if ($action === 'semantic_search') {
        $query = trim((string) ($payload['query'] ?? ''));
        $limit = max(1, min(25, (int) ($payload['limit'] ?? 10)));
        $results = semantic_search_sources($query, $limit);
        assistant_store_run($action, null, $query, ['results' => $results]);
        json_response(['ok' => true, 'results' => $results]);
    }

    if ($action === 'similar_sources') {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $row = get_source($sourceId);
        if (!is_array($row)) {
            json_response(['ok' => false, 'error' => 'Source not found'], 404);
        }
        $source = source_to_array($row);
        $query = embedding_input_for_source($source);
        $results = semantic_search_sources($query, max(1, min(25, (int) ($payload['limit'] ?? 8))));
        assistant_store_run($action, $sourceId, $query, ['results' => $results]);
        json_response(['ok' => true, 'results' => $results]);
    }

    if ($action === 'source_quality') {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $row = get_source($sourceId);
        if (!is_array($row)) {
            json_response(['ok' => false, 'error' => 'Source not found'], 404);
        }
        $source = source_to_array($row);
        $quality = assistant_quality_score($source);
        $source['quality_score'] = $quality['score'];
        $source['quality_reason'] = $quality['reason'];
        save_source($source);
        assistant_store_run($action, $sourceId, $source['title'], $quality);
        json_response(['ok' => true, 'quality' => $quality]);
    }

    if ($action === 'annotate_source') {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $row = get_source($sourceId);
        if (!is_array($row)) {
            json_response(['ok' => false, 'error' => 'Source not found'], 404);
        }
        $source = source_to_array($row);
        $prompt = "Source:\n" . json_encode($source, JSON_UNESCAPED_UNICODE);
        $response = openai_json_response(
            'Return JSON with keys: summary (string), key_claims (array), methods (array), limitations (array).',
            $prompt
        ) ?? ['summary' => '', 'key_claims' => [], 'methods' => [], 'limitations' => []];
        $source['ai_summary'] = (string) ($response['summary'] ?? '');
        $source['ai_claims'] = is_array($response['key_claims'] ?? null) ? $response['key_claims'] : [];
        $source['ai_methods'] = is_array($response['methods'] ?? null) ? $response['methods'] : [];
        $source['ai_limitations'] = is_array($response['limitations'] ?? null) ? $response['limitations'] : [];
        save_source($source);
        assistant_store_run($action, $sourceId, $source['title'], $response);
        json_response(['ok' => true, 'annotation' => $response]);
    }

    if ($action === 'cluster_themes') {
        $rows = list_sources();
        $clusters = [];
        foreach ($rows as $row) {
            $source = source_to_array($row);
            $label = trim((string) ($source['journal'] ?: $source['publisher'] ?: 'general'));
            $clusters[$label][] = [
                'id' => (int) $source['id'],
                'title' => $source['title'],
            ];
        }
        $out = [];
        foreach ($clusters as $label => $items) {
            $out[] = ['label' => $label, 'count' => count($items), 'items' => $items];
        }
        assistant_store_run($action, null, 'cluster all', ['clusters' => $out]);
        json_response(['ok' => true, 'clusters' => $out]);
    }

    if ($action === 'link_claims') {
        $draft = trim((string) ($payload['draft'] ?? ''));
        $out = assistant_handle_link_claims($draft);
        assistant_store_run($action, null, $draft, $out);
        json_response(['ok' => true, 'links' => $out['links'] ?? []]);
    }

    if ($action === 'citation_qa') {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $row = get_source($sourceId);
        if (!is_array($row)) {
            json_response(['ok' => false, 'error' => 'Source not found'], 404);
        }
        $source = source_to_array($row);
        $format = current_citation_format();
        $citation = format_citation($source, $format);
        $issues = [];
        if ($source['title'] === '') {
            $issues[] = 'Missing title';
        }
        if ($source['year'] === '') {
            $issues[] = 'Missing year';
        }
        if ($citation === '') {
            $issues[] = 'Empty citation output';
        }
        $out = ['citation' => $citation, 'issues' => $issues, 'pass' => $issues === []];
        assistant_store_run($action, $sourceId, $source['title'], $out);
        json_response(['ok' => true, 'qa' => $out]);
    }

    if ($action === 'research_question_builder') {
        $topic = trim((string) ($payload['topic'] ?? ''));
        $response = openai_json_response(
            'Return JSON with keys questions (array), keywords (array), search_strings (array).',
            'Topic: ' . $topic
        ) ?? ['questions' => [], 'keywords' => [], 'search_strings' => []];
        assistant_store_run($action, null, $topic, $response);
        json_response(['ok' => true, 'plan' => $response]);
    }

    if ($action === 'compare_brief') {
        $sourceIds = is_array($payload['source_ids'] ?? null) ? $payload['source_ids'] : [];
        $selected = [];
        foreach ($sourceIds as $id) {
            $row = get_source((int) $id);
            if (is_array($row)) {
                $selected[] = source_to_array($row);
            }
        }
        $response = openai_json_response(
            'Return JSON with keys summary (string), agreements (array), disagreements (array), gaps (array).',
            json_encode($selected, JSON_UNESCAPED_UNICODE)
        ) ?? ['summary' => '', 'agreements' => [], 'disagreements' => [], 'gaps' => []];
        assistant_store_run($action, null, 'compare', $response);
        json_response(['ok' => true, 'brief' => $response]);
    }

    if ($action === 'weekly_digest') {
        $rows = list_sources();
        $latest = array_slice($rows, 0, 15);
        $digestInput = array_map(static fn (array $r): array => source_to_array($r), $latest);
        $response = openai_json_response(
            'Return JSON with keys highlights (array), new_themes (array), action_items (array), summary (string).',
            json_encode($digestInput, JSON_UNESCAPED_UNICODE)
        ) ?? ['highlights' => [], 'new_themes' => [], 'action_items' => [], 'summary' => ''];
        $stmt = db()->prepare(
            'INSERT INTO digest_runs (digest_text, digest_json)
             VALUES (:digest_text, CAST(:digest_json AS jsonb))'
        );
        $stmt->execute([
            'digest_text' => (string) ($response['summary'] ?? ''),
            'digest_json' => json_encode($response, JSON_UNESCAPED_UNICODE),
        ]);
        assistant_store_run($action, null, 'weekly_digest', $response);
        json_response(['ok' => true, 'digest' => $response]);
    }

    if ($action === 'reader_synthesis') {
        try {
            $runOutput = assistant_handle_reader_synthesis($payload);
        } catch (InvalidArgumentException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 404);
        }
        $primarySourceId = (int) (($runOutput['source_ids'][0] ?? 0) ?: 0);
        assistant_store_run(
            $action,
            $primarySourceId > 0 ? $primarySourceId : null,
            (string) (($runOutput['context'] ?? '') !== '' ? $runOutput['context'] : 'reader_synthesis'),
            $runOutput
        );
        json_response([
            'ok' => true,
            'synthesis' => $runOutput['synthesis'] ?? [],
            'trace' => $runOutput['trace'] ?? [],
            'token_usage' => $runOutput['token_usage'] ?? [],
            'source_count' => count((array) ($runOutput['source_ids'] ?? [])),
            'sources' => $runOutput['sources'] ?? [],
        ]);
    }

    if ($action === 'reader_source_lookup') {
        $out = assistant_handle_reader_source_lookup($payload);
        json_response(['ok' => true, 'sources' => $out['sources'] ?? []]);
    }

    if ($action === 'reader_history') {
        $out = assistant_handle_reader_history($payload);
        json_response(['ok' => true, 'runs' => $out['runs'] ?? []]);
    }

    if ($action === 'dedupe_scan') {
        $out = assistant_handle_dedupe_scan($payload);
        assistant_store_run($action, null, 'dedupe_scan', ['pair_count' => (int) ($out['pair_count'] ?? 0), 'groups' => $out['groups'] ?? []]);
        json_response(array_merge(['ok' => true], $out));
    }

    if ($action === 'dedupe_apply') {
        $keepId = (int) ($payload['keep_id'] ?? 0);
        $deleteIdsRaw = is_array($payload['delete_ids'] ?? null) ? $payload['delete_ids'] : [];
        if ($keepId <= 0 || $deleteIdsRaw === []) {
            json_response(['ok' => false, 'error' => 'keep_id and delete_ids are required'], 422);
        }
        try {
            $out = assistant_handle_dedupe_apply($keepId, $deleteIdsRaw);
        } catch (InvalidArgumentException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 404);
        }
        assistant_store_run($action, $keepId, 'dedupe_apply', ['keep_id' => $keepId, 'deleted' => $out['deleted_ids'] ?? []]);
        json_response(array_merge(['ok' => true], $out));
    }

    if ($action === 'zotero_sync_status') {
        $configured = setting('zotero_api_key', '') !== '' && setting('zotero_user_id', '') !== '';
        json_response([
            'ok' => true,
            'configured' => $configured,
            'library_type' => setting('zotero_library_type', 'users'),
            'library_id' => setting('zotero_library_id', ''),
        ]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 422);
} catch (Throwable $e) {
    app_log('assistant_error', ['action' => $action, 'error' => $e->getMessage()]);
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
