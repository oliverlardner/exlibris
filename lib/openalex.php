<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

/**
 * OpenAlex bibliographic metadata provider.
 *
 * https://docs.openalex.org/how-to-use-the-api/rate-limits-and-authentication
 * The polite pool wants a mailto; we send a generic contact in the User-Agent
 * so the service can reach us if a query is misbehaving.
 */
const OPENALEX_UA = ['User-Agent: exlibris/1.0 (mailto:exlibris@local)'];

function openalex_by_doi(string $doi): ?array
{
    $doi = trim($doi);
    if ($doi === '') {
        return null;
    }
    // OpenAlex accepts DOI via /works/doi:<value>
    $url = 'https://api.openalex.org/works/doi:' . rawurlencode($doi);
    try {
        $data = http_get_json($url, OPENALEX_UA);
    } catch (Throwable) {
        return null;
    }

    if (!is_array($data) || !isset($data['id'])) {
        return null;
    }

    return map_openalex_work_to_source($data);
}

function openalex_by_url(string $landingUrl): ?array
{
    $landingUrl = trim($landingUrl);
    if ($landingUrl === '') {
        return null;
    }

    $query = http_build_query([
        'filter'   => 'locations.landing_page_url:' . $landingUrl,
        'per-page' => 1,
    ]);
    try {
        $data = http_get_json('https://api.openalex.org/works?' . $query, OPENALEX_UA);
    } catch (Throwable) {
        return null;
    }

    $results = $data['results'] ?? [];
    if (!is_array($results) || $results === []) {
        return null;
    }

    return map_openalex_work_to_source($results[0]);
}

/**
 * Title-based search. Useful when no identifier is known but AI/metadata
 * extracted a title and optionally an author surname.
 */
function openalex_by_title(string $title, ?string $author = null): ?array
{
    $title = trim($title);
    if ($title === '') {
        return null;
    }
    $params = [
        'search'   => $title,
        'per-page' => 5,
    ];
    if ($author !== null && trim($author) !== '') {
        $params['filter'] = 'authorships.author.display_name.search:' . trim($author);
    }
    try {
        $data = http_get_json(
            'https://api.openalex.org/works?' . http_build_query($params),
            OPENALEX_UA
        );
    } catch (Throwable) {
        return null;
    }
    $results = $data['results'] ?? [];
    if (!is_array($results) || $results === []) {
        return null;
    }

    // Prefer a result whose title shares non-trivial words with the query.
    $queryWords = array_filter(
        preg_split('/\W+/u', strtolower($title)) ?: [],
        static fn (string $w): bool => mb_strlen($w) > 3
    );

    $best = $results[0];
    foreach ($results as $candidate) {
        $candTitle = strtolower((string) ($candidate['title'] ?? ''));
        $hits = 0;
        foreach ($queryWords as $w) {
            if (str_contains($candTitle, $w)) {
                $hits++;
            }
        }
        if ($queryWords !== [] && $hits >= max(1, (int) (count($queryWords) * 0.6))) {
            $best = $candidate;
            break;
        }
    }

    return map_openalex_work_to_source($best);
}

function map_openalex_work_to_source(array $work): array
{
    $authors = [];
    foreach ((array) ($work['authorships'] ?? []) as $authorship) {
        $name = trim((string) ($authorship['author']['display_name'] ?? ''));
        if ($name !== '') {
            $authors[] = $name;
        }
    }

    $primaryLocation = $work['primary_location'] ?? [];
    $venueName = is_array($primaryLocation) ? (string) ($primaryLocation['source']['display_name'] ?? '') : '';
    $publisher = is_array($primaryLocation) ? (string) ($primaryLocation['source']['host_organization_name'] ?? '') : '';
    $landingUrl = is_array($primaryLocation) ? (string) ($primaryLocation['landing_page_url'] ?? '') : '';

    $biblio = (array) ($work['biblio'] ?? []);
    $firstPage = trim((string) ($biblio['first_page'] ?? ''));
    $lastPage  = trim((string) ($biblio['last_page'] ?? ''));
    $pages = $firstPage . ($lastPage !== '' && $lastPage !== $firstPage ? '-' . $lastPage : '');

    $year = (string) ($work['publication_year'] ?? '');

    $doi = '';
    $doiRaw = (string) ($work['doi'] ?? '');
    if ($doiRaw !== '' && preg_match('/10\.\d{4,9}\/[-._;()\/:A-Z0-9]+/i', $doiRaw, $m)) {
        $doi = $m[0];
    }

    $type = strtolower((string) ($work['type'] ?? ''));
    $internalType = match ($type) {
        'article', 'journal-article', 'proceedings-article', 'preprint', 'editorial', 'review', 'letter', 'paratext' => 'article',
        'book', 'book-chapter', 'monograph' => 'book',
        'dissertation' => 'other',
        default => 'article',
    };

    return [
        'type'      => $internalType,
        'title'     => (string) ($work['title'] ?? ''),
        'authors'   => $authors,
        'year'      => $year,
        'publisher' => $publisher,
        'journal'   => $venueName,
        'volume'    => trim((string) ($biblio['volume'] ?? '')),
        'issue'     => trim((string) ($biblio['issue'] ?? '')),
        'pages'     => $pages,
        'doi'       => $doi,
        'isbn'      => '',
        'url'       => $landingUrl,
        'notes'     => '',
    ];
}
