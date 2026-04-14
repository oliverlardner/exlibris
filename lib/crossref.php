<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

function lookup_doi_crossref(string $doi): ?array
{
    $doi = trim($doi);
    if ($doi === '') {
        return null;
    }

    $url = 'https://api.crossref.org/works/' . rawurlencode($doi);
    $data = http_get_json($url, ['User-Agent: exlibris/1.0']);
    $item = $data['message'] ?? null;
    if (!is_array($item)) {
        return null;
    }

    $authors = [];
    foreach (($item['author'] ?? []) as $author) {
        $given = trim((string) ($author['given'] ?? ''));
        $family = trim((string) ($author['family'] ?? ''));
        $name = trim($family . ', ' . $given, ' ,');
        if ($name !== '') {
            $authors[] = $name;
        }
    }

    $dateParts = $item['issued']['date-parts'][0] ?? [];
    $year = isset($dateParts[0]) ? (string) $dateParts[0] : '';

    return [
        'type' => 'article',
        'title' => (string) (($item['title'][0] ?? '') ?: ''),
        'authors' => $authors,
        'year' => $year,
        'publisher' => (string) ($item['publisher'] ?? ''),
        'journal' => (string) (($item['container-title'][0] ?? '') ?: ''),
        'volume' => (string) ($item['volume'] ?? ''),
        'issue' => (string) ($item['issue'] ?? ''),
        'pages' => (string) ($item['page'] ?? ''),
        'doi' => (string) ($item['DOI'] ?? $doi),
        'isbn' => '',
        'url' => (string) ($item['URL'] ?? ''),
    ];
}
