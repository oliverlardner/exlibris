<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

/**
 * Semantic Scholar Graph API integration.
 *
 * Works for any identifier type the API accepts: DOI, ArXiv ID, PubMed ID,
 * CorpusId, and a limited set of URL patterns (mostly arXiv / ACL anthology).
 *
 * Without an API key the service is shared rate-limited, so failures here are
 * treated as "no result" and the pipeline continues to other backups.
 *
 * Set EXLIBRIS_SEMANTIC_SCHOLAR_KEY to raise rate limits on your instance.
 */
const SEMANTIC_SCHOLAR_FIELDS = 'title,authors,year,venue,journal,externalIds,openAccessPdf,publicationVenue';

function semantic_scholar_headers(): array
{
    $headers = ['User-Agent: exlibris/1.0'];
    $key = trim((string) (getenv('EXLIBRIS_SEMANTIC_SCHOLAR_KEY') ?: ''));
    if ($key !== '') {
        $headers[] = 'x-api-key: ' . $key;
    }
    return $headers;
}

function semantic_scholar_by_doi(string $doi): ?array
{
    $doi = trim($doi);
    if ($doi === '') {
        return null;
    }
    $url = 'https://api.semanticscholar.org/graph/v1/paper/DOI:' . rawurlencode($doi)
        . '?fields=' . SEMANTIC_SCHOLAR_FIELDS;
    try {
        $data = http_get_json($url, semantic_scholar_headers());
    } catch (Throwable) {
        return null;
    }
    if (!is_array($data) || !isset($data['title'])) {
        return null;
    }

    return map_semantic_scholar_paper_to_source($data);
}

function semantic_scholar_by_url(string $landingUrl): ?array
{
    $landingUrl = trim($landingUrl);
    if ($landingUrl === '') {
        return null;
    }
    $url = 'https://api.semanticscholar.org/graph/v1/paper/URL:' . rawurlencode($landingUrl)
        . '?fields=' . SEMANTIC_SCHOLAR_FIELDS;
    try {
        $data = http_get_json($url, semantic_scholar_headers());
    } catch (Throwable) {
        return null;
    }
    if (!is_array($data) || !isset($data['title'])) {
        return null;
    }

    return map_semantic_scholar_paper_to_source($data);
}

function map_semantic_scholar_paper_to_source(array $paper): array
{
    $authors = [];
    foreach ((array) ($paper['authors'] ?? []) as $author) {
        $name = trim((string) ($author['name'] ?? ''));
        if ($name !== '') {
            $authors[] = $name;
        }
    }

    $journal = (array) ($paper['journal'] ?? []);
    $journalName = trim((string) ($journal['name'] ?? ''));
    $volume = trim((string) ($journal['volume'] ?? ''));
    $pages = trim((string) ($journal['pages'] ?? ''));

    $venueName = trim((string) ($paper['venue'] ?? ''));
    if ($journalName === '' && $venueName !== '') {
        $journalName = $venueName;
    }

    $publisher = '';
    $pubVenue = $paper['publicationVenue'] ?? null;
    if (is_array($pubVenue)) {
        $publisher = trim((string) ($pubVenue['publisher'] ?? ''));
    }

    $externals = (array) ($paper['externalIds'] ?? []);
    $doi = trim((string) ($externals['DOI'] ?? ''));

    return [
        'type'      => 'article',
        'title'     => trim((string) ($paper['title'] ?? '')),
        'authors'   => $authors,
        'year'      => (string) ($paper['year'] ?? ''),
        'publisher' => $publisher,
        'journal'   => $journalName,
        'volume'    => $volume,
        'issue'     => '',
        'pages'     => $pages,
        'doi'       => $doi,
        'isbn'      => '',
        'url'       => '',
        'notes'     => '',
    ];
}
