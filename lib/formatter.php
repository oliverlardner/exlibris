<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function formatter_include_pages(): bool
{
    return include_pages_in_citations();
}

function format_citation(array $source, string $format): string
{
    $format = strtolower(trim($format));

    return match ($format) {
        'mla' => format_mla($source),
        'chicago' => format_chicago($source),
        default => format_apa($source),
    };
}

function format_author_list(array $authors, string $style): string
{
    $authors = array_values(array_filter(array_map('trim', $authors)));
    if ($authors === []) {
        return '';
    }

    if ($style === 'apa') {
        if (count($authors) === 1) {
            return $authors[0];
        }
        $last = array_pop($authors);

        return implode(', ', $authors) . ', & ' . $last;
    }

    if (count($authors) === 1) {
        return $authors[0];
    }

    $last = array_pop($authors);

    return implode(', ', $authors) . ', and ' . $last;
}

function format_apa(array $s): string
{
    $type = strtolower(trim((string) ($s['type'] ?? '')));
    $author = format_author_list($s['authors'] ?? [], 'apa');
    $year = trim((string) ($s['year'] ?? ''));
    $title = trim((string) ($s['title'] ?? ''));
    $journal = trim((string) ($s['journal'] ?? ''));
    $volume = trim((string) ($s['volume'] ?? ''));
    $issue = trim((string) ($s['issue'] ?? ''));
    $pages = trim((string) ($s['pages'] ?? ''));
    if (!formatter_include_pages()) {
        $pages = '';
    }
    $publisher = trim((string) ($s['publisher'] ?? ''));
    $doi = trim((string) ($s['doi'] ?? ''));
    $url = trim((string) ($s['url'] ?? ''));

    $chunks = [];
    if ($author !== '') {
        $chunks[] = $author . '.';
    }
    if ($year !== '') {
        $chunks[] = '(' . $year . ').';
    }
    if ($title !== '') {
        if ($type === 'video') {
            $chunks[] = $title . ' [Video].';
        } else {
            $chunks[] = $title . '.';
        }
    }

    if ($journal !== '') {
        $journalLine = $journal;
        if ($volume !== '') {
            $journalLine .= ', ' . $volume;
        }
        if ($issue !== '') {
            $journalLine .= '(' . $issue . ')';
        }
        if ($pages !== '') {
            $journalLine .= ', ' . $pages;
        }
        $chunks[] = $journalLine . '.';
    } elseif ($publisher !== '') {
        $chunks[] = $publisher . '.';
    }

    if ($doi !== '') {
        $chunks[] = 'https://doi.org/' . $doi;
    } elseif ($url !== '') {
        $chunks[] = $url;
    }

    return trim(implode(' ', $chunks));
}

function format_mla(array $s): string
{
    $author = format_author_list($s['authors'] ?? [], 'mla');
    $title = trim((string) ($s['title'] ?? ''));
    $journal = trim((string) ($s['journal'] ?? ''));
    $publisher = trim((string) ($s['publisher'] ?? ''));
    $year = trim((string) ($s['year'] ?? ''));
    $volume = trim((string) ($s['volume'] ?? ''));
    $issue = trim((string) ($s['issue'] ?? ''));
    $pages = trim((string) ($s['pages'] ?? ''));
    if (!formatter_include_pages()) {
        $pages = '';
    }
    $doi = trim((string) ($s['doi'] ?? ''));
    $url = trim((string) ($s['url'] ?? ''));

    $chunks = [];
    if ($author !== '') {
        $chunks[] = $author . '.';
    }
    if ($title !== '') {
        $chunks[] = '"' . $title . '."';
    }
    if ($journal !== '') {
        $journalLine = $journal;
        if ($volume !== '') {
            $journalLine .= ', vol. ' . $volume;
        }
        if ($issue !== '') {
            $journalLine .= ', no. ' . $issue;
        }
        if ($year !== '') {
            $journalLine .= ', ' . $year;
        }
        if ($pages !== '') {
            $journalLine .= ', pp. ' . $pages;
        }
        $chunks[] = $journalLine . '.';
    } else {
        if ($publisher !== '') {
            $chunks[] = $publisher . ',';
        }
        if ($year !== '') {
            $chunks[] = $year . '.';
        }
    }

    if ($doi !== '') {
        $chunks[] = 'doi:' . $doi . '.';
    } elseif ($url !== '') {
        $chunks[] = $url . '.';
    }

    return trim(implode(' ', $chunks));
}

function format_chicago(array $s): string
{
    $author = format_author_list($s['authors'] ?? [], 'mla');
    $title = trim((string) ($s['title'] ?? ''));
    $journal = trim((string) ($s['journal'] ?? ''));
    $publisher = trim((string) ($s['publisher'] ?? ''));
    $year = trim((string) ($s['year'] ?? ''));
    $volume = trim((string) ($s['volume'] ?? ''));
    $issue = trim((string) ($s['issue'] ?? ''));
    $pages = trim((string) ($s['pages'] ?? ''));
    if (!formatter_include_pages()) {
        $pages = '';
    }
    $doi = trim((string) ($s['doi'] ?? ''));
    $url = trim((string) ($s['url'] ?? ''));

    $chunks = [];
    if ($author !== '') {
        $chunks[] = $author . '.';
    }
    if ($title !== '') {
        $chunks[] = '"' . $title . '."';
    }

    if ($journal !== '') {
        $journalLine = $journal;
        if ($volume !== '') {
            $journalLine .= ' ' . $volume;
        }
        if ($issue !== '') {
            $journalLine .= ', no. ' . $issue;
        }
        if ($year !== '') {
            $journalLine .= ' (' . $year . ')';
        }
        if ($pages !== '') {
            $journalLine .= ': ' . $pages;
        }
        $chunks[] = $journalLine . '.';
    } else {
        $bookLine = trim($publisher . ' ' . $year);
        if ($bookLine !== '') {
            $chunks[] = $bookLine . '.';
        }
    }

    if ($doi !== '') {
        $chunks[] = 'https://doi.org/' . $doi . '.';
    } elseif ($url !== '') {
        $chunks[] = $url . '.';
    }

    return trim(implode(' ', $chunks));
}

function source_to_array(array $row): array
{
    $authors = $row['authors'] ?? [];
    if (is_string($authors)) {
        $decoded = json_decode($authors, true);
        $authors = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($authors)) {
        $authors = [];
    }

    $decodeMaybeJsonArray = static function (mixed $value): array {
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

    return [
        'id' => (int) $row['id'],
        'type' => (string) ($row['type'] ?? 'other'),
        'title' => (string) ($row['title'] ?? ''),
        'authors' => array_values(array_filter(array_map('strval', $authors))),
        'year' => (string) ($row['year'] ?? ''),
        'publisher' => (string) ($row['publisher'] ?? ''),
        'journal' => (string) ($row['journal'] ?? ''),
        'volume' => (string) ($row['volume'] ?? ''),
        'issue' => (string) ($row['issue'] ?? ''),
        'pages' => (string) ($row['pages'] ?? ''),
        'doi' => (string) ($row['doi'] ?? ''),
        'isbn' => (string) ($row['isbn'] ?? ''),
        'url' => (string) ($row['url'] ?? ''),
        'accessed_at' => (string) ($row['accessed_at'] ?? ''),
        'raw_input' => (string) ($row['raw_input'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'lookup_trace' => $decodeMaybeJsonArray($row['lookup_trace'] ?? []),
        'provenance_summary' => (string) ($row['provenance_summary'] ?? ''),
        'body_text' => (string) ($row['body_text'] ?? ''),
        'body_fetched_at' => (string) ($row['body_fetched_at'] ?? ''),
        'body_source' => (string) ($row['body_source'] ?? ''),
        'quality_score' => isset($row['quality_score']) ? (float) $row['quality_score'] : null,
        'quality_reason' => (string) ($row['quality_reason'] ?? ''),
        'ai_summary' => (string) ($row['ai_summary'] ?? ''),
        'ai_claims' => $decodeMaybeJsonArray($row['ai_claims'] ?? []),
        'ai_methods' => $decodeMaybeJsonArray($row['ai_methods'] ?? []),
        'ai_limitations' => $decodeMaybeJsonArray($row['ai_limitations'] ?? []),
        'theme_labels' => $decodeMaybeJsonArray($row['theme_labels'] ?? []),
        'origin_provider' => (string) ($row['origin_provider'] ?? ''),
        'origin_external_id' => (string) ($row['origin_external_id'] ?? ''),
        'origin_updated_at' => (string) ($row['origin_updated_at'] ?? ''),
        'zotero_item_key' => (string) ($row['zotero_item_key'] ?? ''),
        'zotero_version' => isset($row['zotero_version']) ? (int) $row['zotero_version'] : null,
        'zotero_synced_at' => (string) ($row['zotero_synced_at'] ?? ''),
        'project_ids' => project_ids_for_source((int) $row['id']),
    ];
}

function citation_cache_for_source(array $source): array
{
    return [
        'apa' => format_citation($source, 'apa'),
        'mla' => format_citation($source, 'mla'),
        'chicago' => format_citation($source, 'chicago'),
    ];
}

function regenerate_citation_cache_for_source(int $sourceId): void
{
    $row = get_source($sourceId);
    if (!is_array($row)) {
        return;
    }

    $source = source_to_array($row);
    $cache = citation_cache_for_source($source);
    $source['citation_cache'] = $cache;
    save_source($source);
}

function regenerate_all_citation_cache(): void
{
    $rows = list_sources();
    foreach ($rows as $row) {
        regenerate_citation_cache_for_source((int) $row['id']);
    }
}
