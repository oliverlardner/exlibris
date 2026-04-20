<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/formatter.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/embeddings.php';

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

function assistant_handle_dedupe_scan(array $payload): array
{
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

    return [
        'pair_count' => count($pairs),
        'group_count' => count($groups),
        'groups' => $groups,
    ];
}

function assistant_handle_dedupe_apply(int $keepId, array $deleteIdsRaw): array
{
    $deleteIds = array_values(array_unique(array_filter(array_map('intval', $deleteIdsRaw), static fn (int $id): bool => $id > 0 && $id !== $keepId)));
    if ($keepId <= 0 || $deleteIds === []) {
        throw new InvalidArgumentException('keep_id and delete_ids are required');
    }
    $keepRow = get_source($keepId);
    if (!is_array($keepRow)) {
        throw new RuntimeException('Keep source not found');
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

    return [
        'keep_id' => $keepId,
        'deleted_ids' => $deleted,
        'deleted_count' => count($deleted),
    ];
}
