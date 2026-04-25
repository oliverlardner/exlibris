<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function formatter_include_pages(): bool
{
    return include_pages_in_citations();
}

function format_citation(array $source, string $format): string
{
    $format = normalize_citation_format($format);

    return match ($format) {
        'mla' => format_mla($source),
        'chicago18' => format_chicago18($source),
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

function formatter_source_type(array $source): string
{
    $type = strtolower(trim((string) ($source['type'] ?? 'other')));

    return match ($type) {
        'article', 'journalarticle', 'conferencepaper', 'inproceedings', 'preprint', 'report' => 'article',
        'book', 'monograph' => 'book',
        'video', 'film', 'movie', 'podcast' => 'video',
        'website', 'webpage', 'blogpost', 'blog-post' => 'website',
        default => $type !== '' ? $type : 'other',
    };
}

function formatter_trim_title(string $title): string
{
    return trim(rtrim($title, " \t\n\r\0\x0B."));
}

function formatter_sentence(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    return rtrim($text, " \t\n\r\0\x0B.") . '.';
}

function formatter_quoted_title(string $title): string
{
    $title = formatter_trim_title($title);
    if ($title === '') {
        return '';
    }

    return '"' . $title . '."';
}

function formatter_identifier_link(string $doi, string $url): string
{
    $doi = trim($doi);
    if ($doi !== '') {
        $doi = preg_replace('~^(https?://)?(dx\.)?doi\.org/~i', '', $doi) ?? $doi;

        return formatter_sentence('https://doi.org/' . ltrim($doi, '/'));
    }

    $safeUrl = safe_external_url(trim($url));
    if ($safeUrl !== '') {
        return formatter_sentence($safeUrl);
    }

    return '';
}

function formatter_accessed_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return formatter_sentence('Accessed ' . $raw);
    }

    return formatter_sentence('Accessed ' . date('F j, Y', $timestamp));
}

function formatter_split_author_name(string $author): array
{
    $author = trim(preg_replace('/\s+/u', ' ', $author) ?? $author);
    if ($author === '' || str_contains($author, ',')) {
        return ['', $author];
    }

    $parts = preg_split('/\s+/u', $author) ?: [];
    if (count($parts) <= 1) {
        return ['', $author];
    }

    $particles = ['da', 'de', 'del', 'della', 'der', 'di', 'du', 'la', 'le', 'st.', 'st', 'van', 'von'];
    $family = [array_pop($parts)];
    while ($parts !== []) {
        $candidate = strtolower((string) end($parts));
        if (!in_array($candidate, $particles, true)) {
            break;
        }
        array_unshift($family, (string) array_pop($parts));
    }

    return [implode(' ', $parts), implode(' ', $family)];
}

function formatter_chicago18_name(string $author, bool $invert): string
{
    $author = trim($author);
    if ($author === '') {
        return '';
    }
    if (!$invert || str_contains($author, ',')) {
        return $author;
    }

    [$given, $family] = formatter_split_author_name($author);
    if ($family === '') {
        return $author;
    }

    return $given !== '' ? $family . ', ' . $given : $family;
}

function format_chicago18_bibliography_authors(array $authors): string
{
    $authors = array_values(array_filter(array_map('trim', $authors)));
    if ($authors === []) {
        return '';
    }

    $formatted = [];
    foreach ($authors as $index => $author) {
        $formatted[] = formatter_chicago18_name($author, $index === 0);
    }

    if (count($formatted) === 1) {
        return $formatted[0];
    }

    $last = array_pop($formatted);

    return implode(', ', $formatted) . ', and ' . $last;
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

function format_chicago18(array $s): string
{
    $type = formatter_source_type($s);
    $author = format_chicago18_bibliography_authors($s['authors'] ?? []);
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
    $accessedAt = trim((string) ($s['accessed_at'] ?? ''));
    $hasJournal = $journal !== '';
    $hasUrl = $url !== '';
    $isArticleLike = $type === 'article' || $hasJournal;
    $isWebsiteLike = $type === 'website' || (!$isArticleLike && $hasUrl && $type !== 'book' && $type !== 'video');

    $chunks = [];
    if ($author !== '') {
        $chunks[] = formatter_sentence($author);
    }

    if ($isArticleLike || $isWebsiteLike) {
        $quotedTitle = formatter_quoted_title($title);
        if ($quotedTitle !== '') {
            $chunks[] = $quotedTitle;
        }
    } else {
        $plainTitle = formatter_sentence(formatter_trim_title($title));
        if ($plainTitle !== '') {
            $chunks[] = $plainTitle;
        }
    }

    if ($type === 'video') {
        $chunks[] = 'Video.';
    }

    if ($isArticleLike) {
        $journalLine = $journal;
        if ($volume !== '') {
            $journalLine .= ($journalLine !== '' ? ' ' : '') . $volume;
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
        if ($journalLine === '') {
            $journalLine = implode(', ', array_values(array_filter([$publisher, $year], static fn (string $value): bool => $value !== '')));
        }
        $journalLine = formatter_sentence($journalLine);
        if ($journalLine !== '') {
            $chunks[] = $journalLine;
        }
    } elseif ($isWebsiteLike) {
        $siteLine = implode(', ', array_values(array_filter([
            $journal !== '' ? $journal : $publisher,
            $year,
        ], static fn (string $value): bool => $value !== '')));
        $siteLine = formatter_sentence($siteLine);
        if ($siteLine !== '') {
            $chunks[] = $siteLine;
        }
        $accessedLine = formatter_accessed_date($accessedAt);
        if ($accessedLine !== '') {
            $chunks[] = $accessedLine;
        }
    } else {
        $bookLine = implode(', ', array_values(array_filter([$publisher, $year], static fn (string $value): bool => $value !== '')));
        $bookLine = formatter_sentence($bookLine);
        if ($bookLine !== '') {
            $chunks[] = $bookLine;
        }
    }

    $identifier = formatter_identifier_link($doi, $url);
    if ($identifier !== '') {
        $chunks[] = $identifier;
    }

    return trim(implode(' ', $chunks));
}

function format_chicago(array $s): string
{
    return format_chicago18($s);
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
        'pdf_path' => (string) ($row['pdf_path'] ?? ''),
        'reader_synthesis' => (static function (mixed $value): array {
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
        })($row['reader_synthesis'] ?? []),
        'project_ids' => project_ids_for_source((int) $row['id']),
    ];
}

function citation_cache_for_source(array $source): array
{
    $chicago18 = format_citation($source, 'chicago18');

    return [
        'apa' => format_citation($source, 'apa'),
        'mla' => format_citation($source, 'mla'),
        'chicago18' => $chicago18,
        'chicago' => $chicago18,
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

/**
 * Renders a long-form AI reading summary. Supports optional ## section headings
 * (markdown style) for a mini-reader layout.
 */
function render_ai_reading_summary(string $raw): void
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = trim($raw);
    if ($raw === '') {
        return;
    }

    if (!str_contains($raw, "\n## ")) {
        echo '<div class="ai-reading-block">' . nl2br(h($raw)) . '</div>';

        return;
    }

    $chunks = preg_split('/\n##\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chunks) || $chunks === []) {
        echo '<div class="ai-reading-block">' . nl2br(h($raw)) . '</div>';

        return;
    }

    $intro = array_shift($chunks);
    if (trim($intro) !== '') {
        echo '<div class="ai-reading-block">' . nl2br(h(trim($intro))) . '</div>';
    }

    foreach ($chunks as $chunk) {
        $lines = explode("\n", ltrim($chunk), 2);
        $title = trim((string) ($lines[0] ?? ''));
        $body = trim((string) ($lines[1] ?? ''));
        if ($title !== '') {
            echo '<h3 class="ai-reading-h">' . h($title) . '</h3>';
        }
        if ($body !== '') {
            echo '<div class="ai-reading-block">' . nl2br(h($body)) . '</div>';
        }
    }
}

function render_citation_with_copy(string $citation): void
{
    if ($citation === '') {
        echo '<p class="citation"></p>';

        return;
    }
    ?>
    <p class="citation">
        <?= h($citation) ?><button
            type="button"
            class="source-icon-btn citation-copy-btn"
            data-copy-citation="<?= h($citation) ?>"
            title="Copy citation"
            aria-label="Copy citation"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="8.5" y="8.5" width="11" height="11" rx="0" fill="none" stroke="currentColor" stroke-width="1.5" />
                <rect x="3.5" y="3.5" width="11" height="11" rx="0" fill="none" stroke="currentColor" stroke-width="1.5" />
            </svg>
        </button>
    </p>
    <?php
}
