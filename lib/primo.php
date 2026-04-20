<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

function primo_fetch_permalink_metadata(string $url, callable $isbnNormalizer): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    if (!str_contains($host, 'exlibrisgroup.com') || !str_contains($path, '/discovery/fulldisplay')) {
        return null;
    }

    parse_str((string) ($parts['query'] ?? ''), $params);
    $vid   = (string) ($params['vid'] ?? '');
    $scope = (string) ($params['search_scope'] ?? '');
    $query = (string) ($params['query'] ?? '');
    $docid = (string) ($params['docid'] ?? '');
    if ($vid === '' || $scope === '') {
        return null;
    }

    $headers   = ['User-Agent: exlibris/1.0'];
    $baseQuery = http_build_query(['vid' => $vid, 'lang' => 'en', 'scope' => $scope]);

    // Strategy 1: direct recordid lookup — precise, doesn't depend on search ranking
    if ($docid !== '') {
        $docidQuery = 'recordid,exact,' . $docid;
        $directUrl  = sprintf('https://%s/primaws/rest/pub/pnxs?%s&q=%s', $host, $baseQuery, rawurlencode($docidQuery));
        try {
            $json = http_get_json($directUrl, $headers);
            $docs = $json['docs'] ?? [];
            if (is_array($docs) && $docs !== []) {
                return primo_map_doc_to_source($docs[0], $url, $isbnNormalizer);
            }
        } catch (Throwable) {}
    }

    // Strategy 2: fall back to the search query in the URL, then match docid in results
    if ($query === '') {
        return null;
    }
    $searchUrl = sprintf('https://%s/primaws/rest/pub/pnxs?%s&q=%s', $host, $baseQuery, rawurlencode($query));
    try {
        $json = http_get_json($searchUrl, $headers);
    } catch (Throwable) {
        return null;
    }

    $docs = $json['docs'] ?? [];
    if (!is_array($docs) || $docs === []) {
        return null;
    }

    $docidLower = strtolower($docid);
    $selected   = null;
    foreach ($docs as $doc) {
        $recordId = strtolower((string) ($doc['pnx']['control']['recordid'][0] ?? ''));
        if ($docidLower !== '' && $recordId === $docidLower) {
            $selected = $doc;
            break;
        }
    }
    if (!is_array($selected)) {
        $selected = $docs[0];
    }

    return primo_map_doc_to_source($selected, $url, $isbnNormalizer);
}

function primo_map_doc_to_source(array $doc, string $url, callable $isbnNormalizer): array
{
    $display = $doc['pnx']['display'] ?? [];
    $addata = $doc['pnx']['addata'] ?? [];

    $authors = [];
    foreach (($addata['au'] ?? $display['creator'] ?? []) as $rawAuthor) {
        $author = trim(preg_replace('/\$\$Q.*$/', '', (string) $rawAuthor) ?? '');
        if ($author !== '') {
            $authors[] = $author;
        }
    }

    $yearRaw = (string) (($addata['date'][0] ?? '') ?: ($display['creationdate'][0] ?? ''));
    $year = '';
    if (preg_match('/\b(19|20)\d{2}\b/', $yearRaw, $m)) {
        $year = $m[0];
    }

    $isbn = '';
    $isbnCandidates = $addata['isbn'] ?? $display['identifier'] ?? [];
    if (is_array($isbnCandidates)) {
        foreach ($isbnCandidates as $candidate) {
            $normalized = (string) $isbnNormalizer((string) $candidate);
            if ($normalized !== '') {
                $isbn = $normalized;
                break;
            }
        }
    }

    return [
        'type' => strtolower((string) ($display['type'][0] ?? 'book')) === 'book' ? 'book' : 'website',
        'title' => trim((string) ($display['title'][0] ?? $addata['btitle'][0] ?? '')),
        'authors' => array_values(array_unique($authors)),
        'year' => $year,
        'publisher' => trim((string) (($addata['pub'][0] ?? '') ?: ($display['publisher'][0] ?? ''))),
        'journal' => '',
        'volume' => '',
        'issue' => '',
        'pages' => '',
        'doi' => trim((string) ($addata['doi'][0] ?? '')),
        'isbn' => $isbn,
        'url' => $url,
        'notes' => 'Metadata extracted from Primo permalink API.',
    ];
}
