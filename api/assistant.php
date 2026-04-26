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
        if (effective_openai_api_key() === '') {
            json_response(['ok' => false, 'error' => 'OpenAI API key is not configured. Add it in Settings first.'], 503);
        }
        $source = source_to_array($row);
        $body = trim((string) ($source['body_text'] ?? ''));
        $forPrompt = $source;
        if ($body !== '') {
            $maxBody = 48000;
            if (mb_strlen($body) > $maxBody) {
                $forPrompt['body_text'] = mb_substr($body, 0, $maxBody) . "\n[... " . (mb_strlen($body) - $maxBody) . " more characters truncated ...]";
            } else {
                $forPrompt['body_text'] = $body;
            }
        } else {
            $forPrompt['body_text'] = '';
        }
        $prompt = "The JSON describes a source. The body_text field is an excerpt of extracted full text (if any). Read it as a human would and write a substantive guide.\n"
            . json_encode($forPrompt, JSON_UNESCAPED_UNICODE);
        $system = 'You are writing a "mini reader" for someone engaging with a source: warm, clear, and intellectually serious — like a skilled reading companion, not a sparse abstract. '
            . 'Return JSON with a single key: summary (string). Everything goes in summary; do not return key_claims, methods, or limitations as separate fields. '
            . 'summary is one long markdown reading guide (roughly 700–1500 words when there is anything substantive to work with; shorter only when the material is very thin). '
            . 'Structure with "## Section title" headings on their own line, with a blank line above and below. Use at least these three sections, EXACTLY these titles, at the END in this order: "## Key claims", "## Methods / approach", "## Limitations". '
            . 'Before those three, include 3–6 sections that fit the material — e.g. "## What this is", "## The through-line", "## Structure and moves", "## How to read it", "## Open questions" — adapt to the source. '
            . 'Inside Key claims / Methods / Limitations sections, use markdown bullets ("- ") with one claim/method/limitation per bullet, 2–4 sentences each. '
            . 'Key claims: 5–10 bullets capturing important claims, arguments, or thematic moves (for fiction/film/games include thematic claims, formal moves, cultural arguments the work makes). '
            . 'Methods / approach: 3–7 bullets. Adapt to genre — research methods for scholarship; form and craft (medium, narrative structure, visual/aural strategies, world-building, mode of argument) for film/anime/games; rhetorical strategies and evidence types for essays/journalism. '
            . 'Limitations: 3–7 bullets. For scholarship: methodological limits; for fiction/film/games: blind spots, ideological tensions, gaps, datedness, scope limits, contested critical reception. '
            . 'These three sections are REQUIRED and must always have bullets — if the body text is missing, infer from metadata, genre conventions, and known scholarship/criticism, and prefix each inferred bullet with "Inferred:". Use blank lines between paragraphs and between bullets. Ground concrete claims in the excerpt or metadata; if inferring, say so.';
        $response = openai_json_response(
            $system,
            $prompt,
            0.34,
            5000
        ) ?? ['summary' => ''];
        $summaryText = (string) ($response['summary'] ?? '');
        // Legacy compatibility: if a model still returns separate arrays, append
        // them as ## sections so the single-document viewer stays consistent.
        $legacyClaims = is_array($response['key_claims'] ?? null) ? $response['key_claims'] : [];
        $legacyMethods = is_array($response['methods'] ?? null) ? $response['methods'] : [];
        $legacyLimits = is_array($response['limitations'] ?? null) ? $response['limitations'] : [];
        $summaryText = ensure_reading_guide_sections_in_summary($summaryText, $legacyClaims, $legacyMethods, $legacyLimits);
        $source['ai_summary'] = $summaryText;
        // Empty out the separate columns: the guide now lives entirely in ai_summary.
        $source['ai_claims'] = [];
        $source['ai_methods'] = [];
        $source['ai_limitations'] = [];
        save_source($source);
        $responseForUi = ['summary' => $summaryText];
        assistant_store_run($action, $sourceId, $source['title'], $responseForUi);
        json_response(['ok' => true, 'annotation' => $responseForUi, 'source_id' => $sourceId]);
    }

    if ($action === 'reformat_body_text') {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $row = get_source($sourceId);
        if (!is_array($row)) {
            json_response(['ok' => false, 'error' => 'Source not found'], 404);
        }
        if (effective_openai_api_key() === '') {
            json_response(['ok' => false, 'error' => 'OpenAI API key is not configured. Add it in Settings first.'], 503);
        }

        $source = source_to_array($row);
        $existingBodyText = trim((string) ($source['body_text'] ?? ''));
        if ($existingBodyText === '') {
            json_response(['ok' => false, 'error' => 'Source has no extracted text to reformat.'], 422);
        }

        $response = openai_reformat_extracted_text($source);
        if (!is_array($response) || trim((string) ($response['body_text'] ?? '')) === '') {
            json_response(['ok' => false, 'error' => 'AI reformatting did not return cleaned text.'], 502);
        }

        $existingBodySource = trim((string) ($source['body_source'] ?? ''));
        $source['body_text'] = (string) $response['body_text'];
        $source['body_fetched_at'] = gmdate('c');
        $source['body_source'] = str_contains($existingBodySource, 'ai_cleaned')
            ? $existingBodySource
            : ($existingBodySource !== '' ? ($existingBodySource . '+ai_cleaned') : 'ai_cleaned');
        save_source($source);

        assistant_store_run($action, $sourceId, $source['title'], $response);
        json_response([
            'ok' => true,
            'reformatted' => [
                'change_summary' => (string) ($response['change_summary'] ?? ''),
                'input_truncated' => (bool) ($response['input_truncated'] ?? false),
                'original_chars' => (int) ($response['original_chars'] ?? 0),
                'cleaned_chars' => (int) ($response['cleaned_chars'] ?? 0),
            ],
            'body_chars' => mb_strlen((string) $source['body_text']),
        ]);
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
        $primarySourceId = (int) ((($runOutput['selected_source_ids'][0] ?? 0) ?: ($runOutput['source_ids'][0] ?? 0)) ?: 0);
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
            'primary_sources' => $runOutput['primary_sources'] ?? [],
            'expanded_sources' => $runOutput['expanded_sources'] ?? [],
            'selected_source_ids' => $runOutput['selected_source_ids'] ?? [],
            'expanded_source_ids' => $runOutput['expanded_source_ids'] ?? [],
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
