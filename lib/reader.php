<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/formatter.php';
require_once __DIR__ . '/embeddings.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/openalex.php';
require_once __DIR__ . '/semantic_scholar.php';
require_once __DIR__ . '/http.php';

const READER_BODY_REFRESH_DAYS = 30;
const READER_BODY_CHAR_LIMIT = 4000;
const READER_MAX_EXPANSION = 5;
const READER_MAX_SCHOLARLY = 10;

function reader_gather(?array $sourceIds, string $context, int $expandK = READER_MAX_EXPANSION): array
{
    $primary = [];
    $seenIds = [];
    $trace = [];

    foreach ((array) $sourceIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0 || isset($seenIds[$id])) {
            continue;
        }
        $row = get_source($id);
        if (!is_array($row)) {
            continue;
        }
        $source = source_to_array($row);
        $primary[] = $source;
        $seenIds[$id] = true;
    }

    $trace[] = [
        'step' => 'reader_primary_sources',
        'status' => 'success',
        'detail' => 'Loaded ' . count($primary) . ' selected source(s).',
    ];

    $expanded = [];
    $trace[] = [
        'step' => 'reader_semantic_expand',
        'status' => 'skipped',
        'detail' => 'Semantic source expansion is disabled. Reader synthesis uses only explicitly selected source(s).',
    ];

    return [
        'primary' => $primary,
        'expanded' => $expanded,
        'all' => array_values(array_merge($primary, $expanded)),
        'trace' => $trace,
    ];
}

function reader_fetch_body(array &$source, array &$trace): void
{
    $sourceId = (int) ($source['id'] ?? 0);
    $url = trim((string) ($source['url'] ?? ''));
    if ($sourceId <= 0 || $url === '') {
        return;
    }

    $cachedBody = trim((string) ($source['body_text'] ?? ''));
    $fetchedAt = trim((string) ($source['body_fetched_at'] ?? ''));
    if ($cachedBody !== '' && !reader_is_stale($fetchedAt, READER_BODY_REFRESH_DAYS)) {
        $trace[] = [
            'step' => 'reader_fetch_body',
            'status' => 'cached',
            'detail' => 'Used cached body text for source #' . $sourceId . '.',
        ];
        return;
    }

    $html = reader_fetch_page_html($url);
    $bodyText = reader_html_to_text($html);
    if ($bodyText === '') {
        $trace[] = [
            'step' => 'reader_fetch_body',
            'status' => 'no_result',
            'detail' => 'Could not extract readable body text for source #' . $sourceId . '.',
        ];
        return;
    }

    $source['body_text'] = $bodyText;
    $source['body_fetched_at'] = gmdate('c');
    $source['body_source'] = 'html';
    save_source($source);
    $trace[] = [
        'step' => 'reader_fetch_body',
        'status' => 'success',
        'detail' => 'Fetched and cached body text for source #' . $sourceId . '.',
    ];
}

function reader_scholarly_search(string $context, array $seedSources): array
{
    $queries = reader_build_queries($context, $seedSources);
    $candidates = [];
    $seen = [];

    foreach ($queries as $query) {
        if (count($candidates) >= READER_MAX_SCHOLARLY) {
            break;
        }

        try {
            $openalex = openalex_by_title($query);
            if (is_array($openalex)) {
                $candidate = reader_external_candidate_from_source($openalex, 'openalex', $query);
                $key = reader_external_candidate_key($candidate);
                if ($key !== '' && !isset($seen[$key])) {
                    $candidates[] = $candidate;
                    $seen[$key] = true;
                }
            }
        } catch (Throwable) {
        }

        if (count($candidates) >= READER_MAX_SCHOLARLY) {
            break;
        }

        foreach (reader_semantic_scholar_search($query, 3) as $candidate) {
            $key = reader_external_candidate_key($candidate);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $candidates[] = $candidate;
            $seen[$key] = true;
            if (count($candidates) >= READER_MAX_SCHOLARLY) {
                break;
            }
        }
    }

    return array_slice($candidates, 0, READER_MAX_SCHOLARLY);
}

function reader_synthesize(array $dossier, string $context, array &$trace): array
{
    $context = trim($context);
    $schema = [
        'name' => 'reader_synthesis',
        'schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => ['read', 'skim', 'ignore']],
                'verdict_reason' => ['type' => 'string'],
                'why_now' => ['type' => 'string'],
                'claims' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'strength' => ['type' => 'string', 'enum' => ['strong', 'moderate', 'weak']],
                        ],
                        'required' => ['text', 'source_ids', 'strength'],
                    ],
                ],
                'evidence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'source_id' => ['type' => 'integer'],
                            'method' => ['type' => 'string'],
                            'quotes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['source_id', 'method', 'quotes'],
                    ],
                ],
                'connections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'relation' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['source_ids', 'relation', 'note'],
                    ],
                ],
                'open_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'cautions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'external_candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'authors' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'year' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'why_relevant' => ['type' => 'string'],
                            'provider' => ['type' => 'string', 'enum' => ['openalex', 'semantic_scholar', 'web_search']],
                        ],
                        'required' => ['title', 'authors', 'year', 'url', 'why_relevant', 'provider'],
                    ],
                ],
                'companion_overview' => [
                    'type' => 'string',
                    'description' => 'What this material is, genre, and why someone might read it. Ground in selected_sources; may name entities from the excerpt.',
                ],
                'companion_deeper_context' => [
                    'type' => 'string',
                    'description' => 'Background, historical context, or field positioning to help a reader. May use web_search tool knowledge; label uncertainty; not a substitute for the source text.',
                ],
                'companion_reading_tips' => [
                    'type' => 'string',
                    'description' => 'How to get the most from this source: what to look for, questions to ask, suggested order if long. At least 2-3 concrete tips when the source has any body text or metadata.',
                ],
            ],
            'required' => [
                'verdict',
                'verdict_reason',
                'why_now',
                'claims',
                'evidence',
                'connections',
                'open_questions',
                'cautions',
                'external_candidates',
                'companion_overview',
                'companion_deeper_context',
                'companion_reading_tips',
            ],
        ],
    ];

    $contextMode = $context === '' ? 'general' : 'research';
    $system = 'You are an expert research reader. You have two jobs that both appear in the JSON output. '
        . 'JOB A — RESEARCH-ALIGNED BRIEF: For verdict, verdict_reason, why_now, claims, evidence, connections, open_questions, and cautions, treat selected_sources in the dossier as the only texts you are evaluating. '
        . 'Ground those fields in the supplied excerpts, metadata, and in-source notes. Do not justify verdict or these reader-brief items using scholarly_candidates or the web. '
        . 'If the user stated a research context and the sources are off-topic, verdict may be "ignore" for that project — but you must still provide substantive JOB B. '
        . 'JOB B — READING COMPANION: companion_overview, companion_deeper_context, and companion_reading_tips help a human get value from the material. '
        . 'Always fill all three with useful prose (not placeholders). If verdict is "ignore" for the research question, the companion must still help the reader with context, history, and how to read the work. '
        . 'In companion_deeper_context you may bring in well-known background from web search and general knowledge; be explicit that this is external framing, not a claim the source made. '
        . 'If research context is empty, assume the user is simply engaging with the material: prioritize JOB B, use titles and excerpt to find related reading for external_candidates, and use verdict to suggest read/skim/ignore for time/utility as a general reader (not "ignore" the companion content). '
        . 'If introducing facts for JOB B, also add a relevant external_candidates entry with a real URL when possible. '
        . 'Be concise, honest about limits of the cached excerpt, and avoid empty output.';
    $user = "Mode: {$contextMode} (\"general\" = no user research context; still deliver a full reading companion and external_candidates).\n"
        . "Research context:\n" . ($context !== '' ? $context : '(none — treat as general reading; enrich with companion fields and web-friendly leads)')
        . "\n\nDossier JSON:\n" . json_encode($dossier, JSON_UNESCAPED_UNICODE);

    $response = openai_responses_with_web_search($system, $user, $schema);
    if (is_array($response['output'] ?? null)) {
        $trace[] = [
            'step' => 'reader_web_search',
            'status' => 'success',
            'detail' => 'Responses API synthesis completed with web_search enabled.',
        ];
        $normalized = reader_normalize_synthesis($response['output'], $dossier);
        $normalized['usage'] = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        return $normalized;
    }

    $trace[] = [
        'step' => 'reader_web_search',
        'status' => 'error',
        'detail' => 'Responses API synthesis failed; used fallback synthesis without web search.',
    ];

    $fallback = openai_json_response(
        'Return JSON with keys verdict, verdict_reason, why_now, claims, evidence, connections, open_questions, cautions, external_candidates, '
        . 'companion_overview, companion_deeper_context, companion_reading_tips. Each key must exist. '
        . 'Ground the research-brief fields (verdict through cautions) only in selected_sources. '
        . 'companion_* may include general/world context to help the reader. Never leave companion fields empty if any source text or metadata is present.',
        $user
    );
    if (!is_array($fallback)) {
        $empty = reader_empty_synthesis();
        $empty['usage'] = [];
        return $empty;
    }

    $normalized = reader_normalize_synthesis($fallback, $dossier);
    $normalized['usage'] = [];
    return $normalized;
}

function reader_build_dossier(array $primarySources, array $expandedSources, array $scholarlyCandidates, string $context): array
{
    $packSources = static function (array $sources): array {
        $packedSources = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $packedSources[] = [
                'id' => (int) ($source['id'] ?? 0),
                'type' => (string) ($source['type'] ?? ''),
                'title' => (string) ($source['title'] ?? ''),
                'authors' => is_array($source['authors'] ?? null) ? array_values(array_map('strval', $source['authors'])) : [],
                'year' => (string) ($source['year'] ?? ''),
                'publisher' => (string) ($source['publisher'] ?? ''),
                'journal' => (string) ($source['journal'] ?? ''),
                'doi' => (string) ($source['doi'] ?? ''),
                'isbn' => (string) ($source['isbn'] ?? ''),
                'url' => (string) ($source['url'] ?? ''),
                'notes' => (string) ($source['notes'] ?? ''),
                'provenance_summary' => (string) ($source['provenance_summary'] ?? ''),
                'lookup_trace' => is_array($source['lookup_trace'] ?? null) ? $source['lookup_trace'] : [],
                'ai_summary' => (string) ($source['ai_summary'] ?? ''),
                'ai_claims' => is_array($source['ai_claims'] ?? null) ? $source['ai_claims'] : [],
                'ai_methods' => is_array($source['ai_methods'] ?? null) ? $source['ai_methods'] : [],
                'ai_limitations' => is_array($source['ai_limitations'] ?? null) ? $source['ai_limitations'] : [],
                'body_excerpt' => mb_substr((string) ($source['body_text'] ?? ''), 0, READER_BODY_CHAR_LIMIT),
                'body_fetched_at' => (string) ($source['body_fetched_at'] ?? ''),
            ];
        }

        return $packedSources;
    };

    $packedPrimary = $packSources($primarySources);
    $packedExpanded = $packSources($expandedSources);
    $packedSources = array_values(array_merge($packedPrimary, $packedExpanded));

    return [
        'context' => trim($context),
        'selected_sources' => $packedPrimary,
        'expanded_sources' => $packedExpanded,
        'sources' => $packedSources,
        'scholarly_candidates' => array_values($scholarlyCandidates),
    ];
}

function reader_is_stale(string $fetchedAt, int $days): bool
{
    $fetchedAt = trim($fetchedAt);
    if ($fetchedAt === '') {
        return true;
    }
    $ts = strtotime($fetchedAt);
    if ($ts === false) {
        return true;
    }

    return $ts < (time() - ($days * 86400));
}

function reader_fetch_page_html(string $url): string
{
    return http_fetch_html($url, 15);
}

function reader_html_to_text(string $html): string
{
    return http_html_to_text($html, 20000);
}

function reader_build_queries(string $context, array $seedSources): array
{
    $queries = [];
    $context = trim($context);
    if ($context !== '') {
        $queries[] = $context;
    }
    foreach ($seedSources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $title = trim((string) ($source['title'] ?? ''));
        if ($title !== '') {
            $queries[] = $title;
        }
        if ($context !== '' && $title !== '') {
            $queries[] = $context . ' ' . $title;
        }
    }

    $out = [];
    $seen = [];
    foreach ($queries as $q) {
        $key = mb_strtolower(trim($q));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $out[] = $q;
        $seen[$key] = true;
        if (count($out) >= 4) {
            break;
        }
    }

    return $out;
}

function reader_semantic_scholar_search(string $query, int $limit = 3): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $url = 'https://api.semanticscholar.org/graph/v1/paper/search?' . http_build_query([
        'query' => $query,
        'limit' => max(1, min(10, $limit)),
        'fields' => SEMANTIC_SCHOLAR_FIELDS,
    ]);
    try {
        $data = http_get_json($url, semantic_scholar_headers());
    } catch (Throwable) {
        return [];
    }

    $papers = is_array($data['data'] ?? null) ? $data['data'] : [];
    $out = [];
    foreach ($papers as $paper) {
        if (!is_array($paper)) {
            continue;
        }
        $mapped = map_semantic_scholar_paper_to_source($paper);
        $out[] = reader_external_candidate_from_source($mapped, 'semantic_scholar', $query);
    }

    return $out;
}

function reader_external_candidate_from_source(array $source, string $provider, string $why): array
{
    $url = trim((string) ($source['url'] ?? ''));
    $doi = trim((string) ($source['doi'] ?? ''));
    if ($url === '' && $doi !== '') {
        $url = 'https://doi.org/' . $doi;
    }

    return [
        'title' => trim((string) ($source['title'] ?? '')),
        'authors' => is_array($source['authors'] ?? null) ? array_values(array_map('strval', $source['authors'])) : [],
        'year' => trim((string) ($source['year'] ?? '')),
        'url' => $url,
        'why_relevant' => 'Related to query: ' . $why,
        'provider' => $provider,
    ];
}

function reader_external_candidate_key(array $candidate): string
{
    $url = mb_strtolower(trim((string) ($candidate['url'] ?? '')));
    if ($url !== '') {
        return 'url:' . $url;
    }
    $title = mb_strtolower(trim((string) ($candidate['title'] ?? '')));
    $year = trim((string) ($candidate['year'] ?? ''));

    return $title !== '' ? ('title:' . $title . '|' . $year) : '';
}

function reader_empty_synthesis(): array
{
    return [
        'verdict' => 'skim',
        'verdict_reason' => 'Not enough grounded evidence to make a stronger recommendation.',
        'why_now' => '',
        'claims' => [],
        'evidence' => [],
        'connections' => [],
        'open_questions' => [],
        'cautions' => ['Reader synthesis returned no structured output.'],
        'external_candidates' => [],
        'companion_overview' => '',
        'companion_deeper_context' => '',
        'companion_reading_tips' => '',
    ];
}

function reader_normalize_synthesis(array $raw, array $dossier): array
{
    $out = reader_empty_synthesis();
    $out['verdict'] = in_array((string) ($raw['verdict'] ?? ''), ['read', 'skim', 'ignore'], true)
        ? (string) $raw['verdict']
        : 'skim';
    $out['verdict_reason'] = trim((string) ($raw['verdict_reason'] ?? $out['verdict_reason']));
    $out['why_now'] = trim((string) ($raw['why_now'] ?? ''));
    $out['claims'] = is_array($raw['claims'] ?? null) ? array_values($raw['claims']) : [];
    $out['evidence'] = is_array($raw['evidence'] ?? null) ? array_values($raw['evidence']) : [];
    $out['connections'] = is_array($raw['connections'] ?? null) ? array_values($raw['connections']) : [];
    $out['open_questions'] = is_array($raw['open_questions'] ?? null) ? array_values(array_map('strval', $raw['open_questions'])) : [];
    $out['cautions'] = is_array($raw['cautions'] ?? null) ? array_values(array_map('strval', $raw['cautions'])) : [];
    $out['external_candidates'] = is_array($raw['external_candidates'] ?? null) ? array_values($raw['external_candidates']) : [];
    $out['companion_overview'] = trim((string) ($raw['companion_overview'] ?? ''));
    $out['companion_deeper_context'] = trim((string) ($raw['companion_deeper_context'] ?? ''));
    $out['companion_reading_tips'] = trim((string) ($raw['companion_reading_tips'] ?? ''));

    $validSourceIds = [];
    foreach ((array) ($dossier['sources'] ?? []) as $source) {
        if (!is_array($source)) {
            continue;
        }
        $sid = (int) ($source['id'] ?? 0);
        if ($sid > 0) {
            $validSourceIds[$sid] = true;
        }
    }

    $hasExternalUrls = false;
    foreach ($out['external_candidates'] as $candidate) {
        if (trim((string) ($candidate['url'] ?? '')) !== '') {
            $hasExternalUrls = true;
            break;
        }
    }

    $invalidClaimRefs = 0;
    $normalizedClaims = [];
    foreach ($out['claims'] as $claim) {
        if (!is_array($claim)) {
            continue;
        }
        $sourceIds = array_values(array_filter(array_map(
            'intval',
            is_array($claim['source_ids'] ?? null) ? $claim['source_ids'] : []
        ), static fn (int $id): bool => $id > 0 && isset($validSourceIds[$id])));
        if (!$hasExternalUrls && $sourceIds === []) {
            $invalidClaimRefs++;
            continue;
        }
        if ($sourceIds === [] && (is_array($claim['source_ids'] ?? null) ? $claim['source_ids'] : []) !== []) {
            $invalidClaimRefs++;
        }
        $claim['source_ids'] = $sourceIds;
        $normalizedClaims[] = $claim;
    }
    $out['claims'] = array_values($normalizedClaims);

    $invalidEvidenceRefs = 0;
    $normalizedEvidence = [];
    foreach ($out['evidence'] as $evidence) {
        if (!is_array($evidence)) {
            continue;
        }
        $sourceId = (int) ($evidence['source_id'] ?? 0);
        if ($sourceId <= 0 || !isset($validSourceIds[$sourceId])) {
            $invalidEvidenceRefs++;
            continue;
        }
        $evidence['source_id'] = $sourceId;
        $normalizedEvidence[] = $evidence;
    }
    $out['evidence'] = array_values($normalizedEvidence);

    $normalizedConnections = [];
    foreach ($out['connections'] as $connection) {
        if (!is_array($connection)) {
            continue;
        }
        $sourceIds = array_values(array_filter(array_map(
            'intval',
            is_array($connection['source_ids'] ?? null) ? $connection['source_ids'] : []
        ), static fn (int $id): bool => $id > 0 && isset($validSourceIds[$id])));
        $connection['source_ids'] = $sourceIds;
        $normalizedConnections[] = $connection;
    }
    $out['connections'] = array_values($normalizedConnections);

    if ($invalidClaimRefs > 0) {
        $out['cautions'][] = $invalidClaimRefs . ' claim reference(s) were dropped because they could not be grounded to selected sources.';
    }
    if ($invalidEvidenceRefs > 0) {
        $out['cautions'][] = $invalidEvidenceRefs . ' evidence reference(s) were dropped due to invalid source ids.';
    }

    if (($invalidClaimRefs + $invalidEvidenceRefs) > 0 && $out['verdict'] === 'read') {
        $out['verdict'] = 'skim';
        $out['verdict_reason'] = 'Grounding validation downgraded confidence; review evidence before full read.';
    }

    $dossierCandidates = is_array($dossier['scholarly_candidates'] ?? null) ? $dossier['scholarly_candidates'] : [];
    if ($out['external_candidates'] === [] && $dossierCandidates !== []) {
        $out['external_candidates'] = array_slice($dossierCandidates, 0, 5);
    }

    foreach (reader_evidence_readiness_cautions($dossier) as $caution) {
        if (!in_array($caution, $out['cautions'], true)) {
            $out['cautions'][] = $caution;
        }
    }

    return $out;
}

function reader_evidence_readiness_cautions(array $dossier): array
{
    $cautions = [];
    foreach ((array) ($dossier['sources'] ?? []) as $source) {
        if (!is_array($source)) {
            continue;
        }
        $sourceId = (int) ($source['id'] ?? 0);
        $title = trim((string) ($source['title'] ?? ''));
        $label = $title !== '' ? $title : ('source #' . $sourceId);
        $bodyExcerpt = trim((string) ($source['body_excerpt'] ?? ''));
        if ($bodyExcerpt === '') {
            $cautions[] = 'No body text cached for ' . $label . '; synthesis may rely mostly on metadata.';
            continue;
        }
        $fetchedAt = trim((string) ($source['body_fetched_at'] ?? ''));
        if ($fetchedAt !== '' && reader_is_stale($fetchedAt, READER_BODY_REFRESH_DAYS)) {
            $cautions[] = 'Cached body text is stale for ' . $label . '; consider refreshing source text.';
        }
    }

    return $cautions;
}
