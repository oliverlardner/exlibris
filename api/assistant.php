<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/formatter.php';
require_once __DIR__ . '/../lib/openai.php';
require_once __DIR__ . '/../lib/embeddings.php';

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

function assistant_norm_text(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

    return trim($value);
}

function assistant_first_author(array $source): string
{
    $authors = $source['authors'] ?? [];
    if (!is_array($authors) || $authors === []) {
        return '';
    }

    return assistant_norm_text((string) ($authors[0] ?? ''));
}

function assistant_title_similarity(string $a, string $b): float
{
    $a = assistant_norm_text($a);
    $b = assistant_norm_text($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $percent);

    return max(0.0, min(1.0, $percent / 100.0));
}

function assistant_duplicate_signal(array $left, array $right): array
{
    $score = 0.0;
    $reasons = [];

    $doiA = trim(mb_strtolower((string) ($left['doi'] ?? '')));
    $doiB = trim(mb_strtolower((string) ($right['doi'] ?? '')));
    if ($doiA !== '' && $doiA === $doiB) {
        $score += 1.0;
        $reasons[] = 'same DOI';
    }

    $isbnA = preg_replace('/[^0-9x]/i', '', (string) ($left['isbn'] ?? '')) ?? '';
    $isbnB = preg_replace('/[^0-9x]/i', '', (string) ($right['isbn'] ?? '')) ?? '';
    if ($isbnA !== '' && $isbnA === $isbnB) {
        $score += 1.0;
        $reasons[] = 'same ISBN';
    }

    $zotA = trim((string) ($left['zotero_item_key'] ?? ''));
    $zotB = trim((string) ($right['zotero_item_key'] ?? ''));
    if ($zotA !== '' && $zotA === $zotB) {
        $score += 1.0;
        $reasons[] = 'same Zotero key';
    }

    $titleSim = assistant_title_similarity((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    $yearA = trim((string) ($left['year'] ?? ''));
    $yearB = trim((string) ($right['year'] ?? ''));
    $firstAuthorA = assistant_first_author($left);
    $firstAuthorB = assistant_first_author($right);
    if ($titleSim >= 0.97) {
        $score += 0.7;
        $reasons[] = 'very similar title';
    } elseif ($titleSim >= 0.9) {
        $score += 0.45;
        $reasons[] = 'similar title';
    }
    if ($yearA !== '' && $yearA === $yearB) {
        $score += 0.2;
        $reasons[] = 'same year';
    }
    if ($firstAuthorA !== '' && $firstAuthorA === $firstAuthorB) {
        $score += 0.2;
        $reasons[] = 'same first author';
    }

    $isLikely = $score >= 0.8 || ($titleSim >= 0.9 && $yearA !== '' && $yearA === $yearB);

    return [
        'likely' => $isLikely,
        'score' => round(min(1.0, $score), 3),
        'title_similarity' => round($titleSim, 3),
        'reasons' => $reasons,
    ];
}

function assistant_survival_score(array $source): float
{
    $score = 0.0;
    $fields = ['title', 'year', 'publisher', 'journal', 'volume', 'issue', 'pages', 'doi', 'isbn', 'url', 'notes'];
    foreach ($fields as $field) {
        if (trim((string) ($source[$field] ?? '')) !== '') {
            $score += 1.0;
        }
    }
    $authors = $source['authors'] ?? [];
    if (is_array($authors) && $authors !== []) {
        $score += min(2.0, count($authors) * 0.5);
    }
    if (trim((string) ($source['zotero_item_key'] ?? '')) !== '') {
        $score += 1.5;
    }
    if (trim((string) ($source['origin_provider'] ?? '')) === 'zotero') {
        $score += 0.5;
    }

    return $score;
}

function assistant_pick_keep(array $group): array
{
    $best = null;
    $bestScore = -1.0;
    foreach ($group as $source) {
        $score = assistant_survival_score($source);
        if ($score > $bestScore) {
            $best = $source;
            $bestScore = $score;
        }
    }
    if (!is_array($best)) {
        return ['keep_id' => 0, 'reason' => 'No candidate selected'];
    }

    return [
        'keep_id' => (int) ($best['id'] ?? 0),
        'reason' => 'Highest metadata completeness and sync confidence score',
        'heuristic_score' => round($bestScore, 2),
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
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $draft) ?: [])));
        $links = [];
        foreach ($lines as $claim) {
            $matches = semantic_search_sources($claim, 3);
            foreach ($matches as $match) {
                $sourceId = (int) ($match['source']['id'] ?? 0);
                $score = (float) ($match['score'] ?? 0.0);
                $stmt = db()->prepare(
                    'INSERT INTO draft_claim_links (claim_text, source_id, confidence, rationale)
                     VALUES (:claim_text, :source_id, :confidence, :rationale)'
                );
                $stmt->execute([
                    'claim_text' => $claim,
                    'source_id' => $sourceId > 0 ? $sourceId : null,
                    'confidence' => $score,
                    'rationale' => 'Semantic match',
                ]);
            }
            $links[] = ['claim' => $claim, 'matches' => $matches];
        }
        assistant_store_run($action, null, $draft, ['links' => $links]);
        json_response(['ok' => true, 'links' => $links]);
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

    if ($action === 'dedupe_scan') {
        $rows = list_sources();
        $sources = array_map(static fn (array $row): array => source_to_array($row), $rows);
        $pairs = [];
        $adj = [];
        $count = count($sources);
        for ($i = 0; $i < $count; $i++) {
            $left = $sources[$i];
            $leftId = (int) ($left['id'] ?? 0);
            if ($leftId <= 0) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $right = $sources[$j];
                $rightId = (int) ($right['id'] ?? 0);
                if ($rightId <= 0) {
                    continue;
                }
                $signal = assistant_duplicate_signal($left, $right);
                if (!$signal['likely']) {
                    continue;
                }
                $pairs[] = [
                    'left_id' => $leftId,
                    'right_id' => $rightId,
                    'score' => $signal['score'],
                    'title_similarity' => $signal['title_similarity'],
                    'reasons' => $signal['reasons'],
                ];
                $adj[$leftId] ??= [];
                $adj[$rightId] ??= [];
                $adj[$leftId][] = $rightId;
                $adj[$rightId][] = $leftId;
            }
        }

        $sourceById = [];
        foreach ($sources as $source) {
            $sourceById[(int) ($source['id'] ?? 0)] = $source;
        }

        $visited = [];
        $groups = [];
        foreach (array_keys($adj) as $startId) {
            if (isset($visited[$startId])) {
                continue;
            }
            $stack = [$startId];
            $component = [];
            while ($stack !== []) {
                $node = array_pop($stack);
                if (isset($visited[$node])) {
                    continue;
                }
                $visited[$node] = true;
                $component[] = $node;
                foreach (($adj[$node] ?? []) as $next) {
                    if (!isset($visited[$next])) {
                        $stack[] = $next;
                    }
                }
            }
            if (count($component) < 2) {
                continue;
            }
            $groupSources = [];
            foreach ($component as $id) {
                if (is_array($sourceById[$id] ?? null)) {
                    $groupSources[] = $sourceById[$id];
                }
            }
            if ($groupSources === []) {
                continue;
            }
            $pick = assistant_pick_keep($groupSources);
            $keepId = (int) ($pick['keep_id'] ?? 0);
            $deleteIds = array_values(array_filter(array_map(
                static fn (array $source): int => (int) ($source['id'] ?? 0),
                $groupSources
            ), static fn (int $id): bool => $id > 0 && $id !== $keepId));

            $aiAdvice = null;
            $useAi = !in_array(strtolower(trim((string) ($payload['use_ai'] ?? '1'))), ['0', 'false', 'no', 'off'], true);
            if ($useAi && count($groupSources) <= 5) {
                $compact = array_map(static function (array $s): array {
                    return [
                        'id' => (int) ($s['id'] ?? 0),
                        'title' => (string) ($s['title'] ?? ''),
                        'authors' => $s['authors'] ?? [],
                        'year' => (string) ($s['year'] ?? ''),
                        'doi' => (string) ($s['doi'] ?? ''),
                        'isbn' => (string) ($s['isbn'] ?? ''),
                        'url' => (string) ($s['url'] ?? ''),
                        'notes_len' => mb_strlen((string) ($s['notes'] ?? '')),
                        'zotero_item_key' => (string) ($s['zotero_item_key'] ?? ''),
                    ];
                }, $groupSources);
                $aiAdvice = openai_json_response(
                    'You are a dedupe assistant for bibliography records. Return JSON with keys keep_id (number) and reason (string). Pick the best canonical record to keep.',
                    json_encode($compact, JSON_UNESCAPED_UNICODE)
                );
            }

            if (is_array($aiAdvice) && (int) ($aiAdvice['keep_id'] ?? 0) > 0) {
                $aiKeepId = (int) $aiAdvice['keep_id'];
                if (in_array($aiKeepId, $component, true)) {
                    $keepId = $aiKeepId;
                    $deleteIds = array_values(array_filter($component, static fn (int $id): bool => $id !== $keepId));
                    $pick['reason'] = 'AI suggestion: ' . trim((string) ($aiAdvice['reason'] ?? ''));
                }
            }

            $groups[] = [
                'ids' => array_values($component),
                'sources' => $groupSources,
                'suggested_keep_id' => $keepId,
                'suggested_delete_ids' => $deleteIds,
                'suggestion_reason' => (string) ($pick['reason'] ?? ''),
            ];
        }

        usort($groups, static fn (array $a, array $b): int => count($b['ids']) <=> count($a['ids']));
        assistant_store_run($action, null, 'dedupe_scan', ['pair_count' => count($pairs), 'groups' => $groups]);
        json_response([
            'ok' => true,
            'pair_count' => count($pairs),
            'group_count' => count($groups),
            'groups' => $groups,
        ]);
    }

    if ($action === 'dedupe_apply') {
        $keepId = (int) ($payload['keep_id'] ?? 0);
        $deleteIdsRaw = is_array($payload['delete_ids'] ?? null) ? $payload['delete_ids'] : [];
        $deleteIds = array_values(array_unique(array_filter(array_map('intval', $deleteIdsRaw), static fn (int $id): bool => $id > 0 && $id !== $keepId)));
        if ($keepId <= 0 || $deleteIds === []) {
            json_response(['ok' => false, 'error' => 'keep_id and delete_ids are required'], 422);
        }
        $keepRow = get_source($keepId);
        if (!is_array($keepRow)) {
            json_response(['ok' => false, 'error' => 'Keep source not found'], 404);
        }
        $keep = source_to_array($keepRow);
        foreach ($deleteIds as $deleteId) {
            $row = get_source($deleteId);
            if (!is_array($row)) {
                continue;
            }
            $drop = source_to_array($row);
            foreach (['title', 'year', 'publisher', 'journal', 'volume', 'issue', 'pages', 'doi', 'isbn', 'url', 'accessed_at', 'raw_input'] as $field) {
                if (trim((string) ($keep[$field] ?? '')) === '' && trim((string) ($drop[$field] ?? '')) !== '') {
                    $keep[$field] = (string) $drop[$field];
                }
            }
            $keep['authors'] = array_values(array_unique(array_merge(
                is_array($keep['authors'] ?? null) ? $keep['authors'] : [],
                is_array($drop['authors'] ?? null) ? $drop['authors'] : []
            )));
            $keep['project_ids'] = array_values(array_unique(array_merge(
                is_array($keep['project_ids'] ?? null) ? $keep['project_ids'] : [],
                is_array($drop['project_ids'] ?? null) ? $drop['project_ids'] : []
            )));
            if (trim((string) ($drop['notes'] ?? '')) !== '') {
                $mergedNote = "\n\nMerged from source #" . $deleteId . ":\n" . trim((string) $drop['notes']);
                if (!str_contains((string) ($keep['notes'] ?? ''), trim((string) $drop['notes']))) {
                    $keep['notes'] = rtrim((string) ($keep['notes'] ?? '')) . $mergedNote;
                }
            }
            if (trim((string) ($keep['zotero_item_key'] ?? '')) === '' && trim((string) ($drop['zotero_item_key'] ?? '')) !== '') {
                $keep['zotero_item_key'] = (string) $drop['zotero_item_key'];
                $keep['origin_provider'] = 'zotero';
                $keep['origin_external_id'] = (string) $drop['zotero_item_key'];
            }
        }
        save_source($keep);
        regenerate_citation_cache_for_source($keepId);
        upsert_source_embedding($keepId);

        $deleted = [];
        foreach ($deleteIds as $deleteId) {
            if (delete_source($deleteId)) {
                $deleted[] = $deleteId;
            }
        }
        assistant_store_run($action, $keepId, 'dedupe_apply', ['keep_id' => $keepId, 'deleted' => $deleted]);
        json_response([
            'ok' => true,
            'keep_id' => $keepId,
            'deleted_ids' => $deleted,
            'deleted_count' => count($deleted),
        ]);
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
