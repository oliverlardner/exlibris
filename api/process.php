<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/crossref.php';
require_once __DIR__ . '/../lib/openlibrary.php';
require_once __DIR__ . '/../lib/openai.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/primo.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$input = trim((string) ($payload['input'] ?? ''));
if ($input === '') {
    json_response(['error' => 'Input is required'], 422);
}

try {
    $result = null;
    $inputType = 'raw_text';
    $lookupTrace = [];

    if (looks_like_bibtex($input)) {
        $inputType = 'bibtex';
        $result = parse_bibtex_entry($input);
        $lookupTrace[] = [
            'step' => 'bibtex_parser',
            'status' => is_array($result) ? 'success' : 'no_result',
            'detail' => is_array($result) ? 'Parsed BibTeX entry.' : 'Could not parse BibTeX entry.',
        ];
    } elseif (looks_like_ris($input)) {
        $inputType = 'ris';
        $result = parse_ris_entry($input);
        $lookupTrace[] = [
            'step' => 'ris_parser',
            'status' => is_array($result) ? 'success' : 'no_result',
            'detail' => is_array($result) ? 'Parsed RIS record.' : 'Could not parse RIS record.',
        ];
    } elseif (preg_match('/10\.\d{4,9}\/[-._;()\/:A-Z0-9]+/i', $input, $m)) {
        $inputType = 'doi';
        $doi = $m[0];
        try {
            $result = lookup_doi_crossref($doi);
            $lookupTrace[] = [
                'step' => 'crossref',
                'status' => is_array($result) ? 'success' : 'no_result',
                'detail' => is_array($result) ? 'CrossRef returned metadata.' : 'CrossRef returned no result.',
            ];
        } catch (Throwable) {
            $result = null;
            $lookupTrace[] = [
                'step' => 'crossref',
                'status' => 'error',
                'detail' => 'CrossRef request failed.',
            ];
        }
        if (!is_array($result)) {
            $aiResult = fallback_with_openai_identifier('doi', $doi, $lookupTrace);
            if (is_array($aiResult)) {
                $result = $aiResult;
            } else {
                $result = [
                    'type' => 'article',
                    'title' => '',
                    'authors' => [],
                    'year' => '',
                    'publisher' => '',
                    'journal' => '',
                    'volume' => '',
                    'issue' => '',
                    'pages' => '',
                    'doi' => $doi,
                    'isbn' => '',
                    'url' => '',
                    'notes' => 'DOI detected but CrossRef did not return metadata. Add details manually.',
                ];
            }
        }
    } elseif (preg_match('/\b(?:97[89])?\d{9}[\dXx]\b/', normalize_isbn($input), $m)) {
        $inputType = 'isbn';
        $isbn = strtoupper($m[0]);
        try {
            $result = lookup_isbn_openlibrary($isbn);
            $lookupTrace[] = [
                'step' => 'openlibrary',
                'status' => is_array($result) ? 'success' : 'no_result',
                'detail' => is_array($result) ? 'Open Library returned metadata.' : 'Open Library returned no result.',
            ];
        } catch (Throwable) {
            $result = null;
            $lookupTrace[] = [
                'step' => 'openlibrary',
                'status' => 'error',
                'detail' => 'Open Library request failed.',
            ];
        }

        if (!is_array($result)) {
            $aiResult = fallback_with_openai_identifier('isbn', $isbn, $lookupTrace);
            if (is_array($aiResult)) {
                $result = $aiResult;
            } else {
                $result = [
                    'type' => 'book',
                    'title' => '',
                    'authors' => [],
                    'year' => '',
                    'publisher' => '',
                    'journal' => '',
                    'volume' => '',
                    'issue' => '',
                    'pages' => '',
                    'doi' => '',
                    'isbn' => $isbn,
                    'url' => '',
                    'notes' => 'ISBN detected but Open Library did not return metadata. Add details manually.',
                ];
            }
        } else {
            if (((string) ($result['isbn'] ?? '')) === '') {
                $result['isbn'] = $isbn;
            }
        }
    } elseif (filter_var($input, FILTER_VALIDATE_URL)) {
        $inputType = 'url';
        $pageHtml = fetch_page_html($input);
        $pageText = html_to_text($pageHtml);
        $urlMeta = extract_url_metadata($input, $pageHtml);
        $urlMetaDetail = str_contains((string) ($urlMeta['notes'] ?? ''), 'Primo permalink API')
            ? 'Parsed metadata from Primo permalink API and page tags.'
            : 'Parsed metadata from URL/oEmbed tags when available.';
        $lookupTrace[] = [
            'step' => 'url_metadata',
            'status' => 'success',
            'detail' => $urlMetaDetail,
        ];
        $aiResult = null;

        // URL extraction should still work even if OpenAI is unavailable.
        try {
            $aiResult = openai_extract_source($pageText !== '' ? $pageText : $input, $input);
            $aiAuthors = is_array($aiResult) && is_array($aiResult['authors'] ?? null) ? $aiResult['authors'] : [];
            $aiHasCore = is_array($aiResult)
                && (
                    trim((string) ($aiResult['title'] ?? '')) !== ''
                    || trim((string) ($aiResult['year'] ?? '')) !== ''
                    || $aiAuthors !== []
                );
            $lookupTrace[] = [
                'step' => 'openai_url',
                'status' => $aiHasCore ? 'success' : 'no_result',
                'detail' => $aiHasCore ? 'OpenAI returned metadata from page text.' : 'OpenAI returned low-confidence/empty metadata.',
            ];
        } catch (Throwable) {
            $aiResult = null;
            $lookupTrace[] = [
                'step' => 'openai_url',
                'status' => 'error',
                'detail' => 'OpenAI URL extraction failed.',
            ];
        }

        $result = merge_source_candidates($urlMeta, $aiResult, $input);
        $result['accessed_at'] = gmdate('c');
    } else {
        $result = openai_extract_source($input);
    }

    if (!is_array($result)) {
        $result = [
            'type' => 'other',
            'title' => '',
            'authors' => [],
            'year' => '',
            'publisher' => '',
            'journal' => '',
            'volume' => '',
            'issue' => '',
            'pages' => '',
            'doi' => '',
            'isbn' => '',
            'url' => filter_var($input, FILTER_VALIDATE_URL) ? $input : '',
            'notes' => '',
            'accessed_at' => '',
        ];
    }

    $result['raw_input'] = $input;
    $result['provenance'] = [
        'input_type' => $inputType,
        'confidence' => ($inputType === 'doi' || $inputType === 'isbn' || $inputType === 'bibtex' || $inputType === 'ris') ? 'high' : 'medium',
    ];

    json_response([
        'ok' => true,
        'input_type' => $inputType,
        'source' => $result,
        'lookup_trace' => $lookupTrace,
    ]);
} catch (Throwable $e) {
    $message = $e->getMessage();
    if (str_contains($message, '401')) {
        $message = 'OpenAI request unauthorized. Check API key in Settings.';
    } elseif (str_contains($message, 'HTTP POST returned status')) {
        $message = 'OpenAI request failed. Please verify your API key and model access.';
    }

    app_log('process_failed', ['error' => $message]);
    json_response([
        'ok' => false,
        'error' => $message,
    ], 502);
}

function fetch_page_html(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'exlibris/1.0',
    ]);
    $html = curl_exec($ch);
    if (!is_string($html) || $html === '') {
        curl_close($ch);
        return '';
    }
    curl_close($ch);

    return $html;
}

function html_to_text(string $html): string
{
    if ($html === '') {
        return '';
    }

    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text) ?? '';

    return mb_substr(trim($text), 0, 12000);
}

function merge_source_candidates(?array $primary, ?array $secondary, string $url): array
{
    $primary = is_array($primary) ? $primary : [];
    $secondary = is_array($secondary) ? $secondary : [];

    $authorsPrimary = $primary['authors'] ?? [];
    $authorsSecondary = $secondary['authors'] ?? [];
    if (!is_array($authorsPrimary)) {
        $authorsPrimary = [];
    }
    if (!is_array($authorsSecondary)) {
        $authorsSecondary = [];
    }

    return [
        'type' => (string) (($primary['type'] ?? '') ?: ($secondary['type'] ?? 'website')),
        'title' => (string) (($primary['title'] ?? '') ?: ($secondary['title'] ?? '')),
        'authors' => $authorsPrimary !== [] ? $authorsPrimary : $authorsSecondary,
        'year' => (string) (($primary['year'] ?? '') ?: ($secondary['year'] ?? '')),
        'publisher' => (string) (($primary['publisher'] ?? '') ?: ($secondary['publisher'] ?? '')),
        'journal' => (string) (($primary['journal'] ?? '') ?: ($secondary['journal'] ?? '')),
        'volume' => (string) (($primary['volume'] ?? '') ?: ($secondary['volume'] ?? '')),
        'issue' => (string) (($primary['issue'] ?? '') ?: ($secondary['issue'] ?? '')),
        'pages' => (string) (($primary['pages'] ?? '') ?: ($secondary['pages'] ?? '')),
        'doi' => (string) (($primary['doi'] ?? '') ?: ($secondary['doi'] ?? '')),
        'isbn' => (string) (($primary['isbn'] ?? '') ?: ($secondary['isbn'] ?? '')),
        'url' => (string) (($primary['url'] ?? '') ?: ($secondary['url'] ?? $url)),
        'notes' => (string) (($primary['notes'] ?? '') ?: ($secondary['notes'] ?? '')),
    ];
}

function extract_url_metadata(string $url, string $pageText = ''): array
{
    $meta = [
        'type' => 'website',
        'title' => '',
        'authors' => [],
        'year' => '',
        'publisher' => '',
        'journal' => '',
        'volume' => '',
        'issue' => '',
        'pages' => '',
        'doi' => '',
        'isbn' => '',
        'url' => $url,
        'notes' => '',
    ];

    $primoMeta = primo_fetch_permalink_metadata($url, 'normalize_isbn');
    if (is_array($primoMeta)) {
        $meta = merge_source_candidates($primoMeta, $meta, $url);
    }

    if (is_youtube_url($url)) {
        $oembed = fetch_youtube_oembed($url);
        if (is_array($oembed)) {
            $meta['type'] = 'video';
            $meta['title'] = (string) ($oembed['title'] ?? '');
            $author = trim((string) ($oembed['author_name'] ?? ''));
            if ($author !== '') {
                $meta['authors'] = [$author];
            }
            $meta['publisher'] = (string) (($oembed['provider_name'] ?? '') ?: 'YouTube');
        }
    }

    if ($pageText !== '') {
        $citationAuthors = extract_meta_values($pageText, 'name', 'citation_author');
        if ($citationAuthors !== []) {
            $meta['authors'] = $citationAuthors;
        }

        if ($meta['title'] === '') {
            $meta['title'] = extract_meta_value($pageText, 'property', 'og:title')
                ?: extract_meta_value($pageText, 'name', 'title')
                ?: extract_meta_value($pageText, 'name', 'citation_title')
                ?: extract_title_tag($pageText);
        }

        if (($meta['authors'] ?? []) === []) {
            $author = extract_meta_value($pageText, 'name', 'author')
                ?: extract_meta_value($pageText, 'property', 'article:author');
            if ($author !== '') {
                $meta['authors'] = [trim($author)];
            }
        }

        $published = extract_meta_value($pageText, 'itemprop', 'datePublished')
            ?: extract_meta_value($pageText, 'property', 'article:published_time')
            ?: extract_meta_value($pageText, 'name', 'citation_publication_date')
            ?: extract_meta_value($pageText, 'name', 'citation_date')
            ?: '';
        if ($published !== '' && preg_match('/\b(19|20)\d{2}\b/', $published, $m)) {
            $meta['year'] = $m[0];
        }

        if ($meta['publisher'] === '') {
            $meta['publisher'] = extract_meta_value($pageText, 'property', 'og:site_name')
                ?: extract_meta_value($pageText, 'name', 'citation_publisher')
                ?: extract_meta_value($pageText, 'name', 'citation_journal_title');
        }

        if ($meta['isbn'] === '') {
            $meta['isbn'] = normalize_isbn(extract_meta_value($pageText, 'name', 'citation_isbn'));
        }
    }

    return $meta;
}

function is_youtube_url(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return false;
    }
    $host = strtolower($host);

    return str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be');
}

function fetch_youtube_oembed(string $url): ?array
{
    $endpoint = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'exlibris/1.0',
    ]);
    $raw = curl_exec($ch);
    if (!is_string($raw) || $raw === '') {
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function extract_meta_value(string $html, string $attr, string $name): string
{
    $pattern = '/<meta[^>]*' . preg_quote($attr, '/') . '\s*=\s*["\']'
        . preg_quote($name, '/') . '["\'][^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*>/i';
    if (preg_match($pattern, $html, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
    }

    return '';
}

function extract_meta_values(string $html, string $attr, string $name): array
{
    $pattern = '/<meta[^>]*' . preg_quote($attr, '/') . '\s*=\s*["\']'
        . preg_quote($name, '/') . '["\'][^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*>/i';
    if (preg_match_all($pattern, $html, $matches)) {
        return array_values(array_filter(array_map(static function (string $v): string {
            return html_entity_decode(trim($v), ENT_QUOTES | ENT_HTML5);
        }, $matches[1] ?? [])));
    }

    return [];
}

function extract_title_tag(string $html): string
{
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
    }

    return '';
}

function normalize_isbn(string $value): string
{
    $value = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $value);

    return preg_replace('/[^0-9Xx]/', '', $value) ?? '';
}

function looks_like_bibtex(string $input): bool
{
    return preg_match('/@\w+\s*\{/', $input) === 1 && str_contains(strtolower($input), 'title');
}

function looks_like_ris(string $input): bool
{
    return preg_match('/^\s*TY\s*-\s*/m', $input) === 1;
}

function parse_bibtex_entry(string $input): ?array
{
    if (!preg_match('/@(\w+)\s*\{.*?\}/is', $input, $entryMatch)) {
        return null;
    }

    $entryType = strtolower((string) ($entryMatch[1] ?? 'misc'));
    $fields = [];
    if (preg_match_all('/(\w+)\s*=\s*(\{(?:[^{}]|(?2))*\}|\"(?:\\\\.|[^\"])*\")\s*,?/is', $input, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key = strtolower(trim((string) ($m[1] ?? '')));
            $value = trim((string) ($m[2] ?? ''));
            $value = trim($value, "{}\" \t\n\r\0\x0B");
            $fields[$key] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        }
    }

    $authorsRaw = (string) ($fields['author'] ?? '');
    $authors = [];
    if ($authorsRaw !== '') {
        foreach (preg_split('/\s+and\s+/i', $authorsRaw) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '') {
                $authors[] = $name;
            }
        }
    }

    $year = (string) ($fields['year'] ?? '');
    if ($year !== '' && preg_match('/\b(19|20)\d{2}\b/', $year, $ym)) {
        $year = $ym[0];
    }

    return [
        'type' => in_array($entryType, ['article', 'inproceedings', 'book', 'misc'], true) ? $entryType : 'other',
        'title' => (string) ($fields['title'] ?? ''),
        'authors' => $authors,
        'year' => $year,
        'publisher' => (string) ($fields['publisher'] ?? ''),
        'journal' => (string) (($fields['journal'] ?? '') ?: ($fields['booktitle'] ?? '')),
        'volume' => (string) ($fields['volume'] ?? ''),
        'issue' => (string) (($fields['number'] ?? '') ?: ($fields['issue'] ?? '')),
        'pages' => (string) ($fields['pages'] ?? ''),
        'doi' => (string) ($fields['doi'] ?? ''),
        'isbn' => normalize_isbn((string) ($fields['isbn'] ?? '')),
        'url' => (string) ($fields['url'] ?? ''),
        'notes' => '',
    ];
}

function parse_ris_entry(string $input): ?array
{
    $lines = preg_split('/\R/', $input) ?: [];
    $fields = [];
    foreach ($lines as $line) {
        if (!preg_match('/^\s*([A-Z0-9]{2})\s*-\s*(.*)\s*$/', $line, $m)) {
            continue;
        }
        $tag = strtoupper($m[1]);
        $value = trim($m[2]);
        $fields[$tag] ??= [];
        $fields[$tag][] = $value;
    }

    if ($fields === []) {
        return null;
    }

    $typeTag = strtolower((string) (($fields['TY'][0] ?? '')));
    $mapType = match ($typeTag) {
        'book' => 'book',
        'jour', 'jfull' => 'article',
        'video' => 'video',
        default => 'other',
    };

    $yearField = (string) (($fields['PY'][0] ?? '') ?: ($fields['Y1'][0] ?? ''));
    $year = '';
    if (preg_match('/\b(19|20)\d{2}\b/', $yearField, $ym)) {
        $year = $ym[0];
    }

    return [
        'type' => $mapType,
        'title' => (string) (($fields['TI'][0] ?? '') ?: ($fields['T1'][0] ?? '')),
        'authors' => array_values(array_filter(array_map('trim', array_merge($fields['AU'] ?? [], $fields['A1'] ?? [])))),
        'year' => $year,
        'publisher' => (string) (($fields['PB'][0] ?? '') ?: ($fields['CY'][0] ?? '')),
        'journal' => (string) (($fields['JO'][0] ?? '') ?: ($fields['T2'][0] ?? '')),
        'volume' => (string) ($fields['VL'][0] ?? ''),
        'issue' => (string) ($fields['IS'][0] ?? ''),
        'pages' => (string) (($fields['SP'][0] ?? '') . ((isset($fields['EP'][0]) && $fields['EP'][0] !== '') ? '-' . $fields['EP'][0] : '')),
        'doi' => (string) (($fields['DO'][0] ?? '') ?: ($fields['M3'][0] ?? '')),
        'isbn' => normalize_isbn((string) ($fields['SN'][0] ?? '')),
        'url' => (string) (($fields['UR'][0] ?? '') ?: ($fields['L1'][0] ?? '')),
        'notes' => '',
    ];
}

function fallback_with_openai_identifier(string $kind, string $value, array &$trace): ?array
{
    $kind = strtolower($kind);
    if (!in_array($kind, ['doi', 'isbn'], true)) {
        return null;
    }

    if (trim((string) setting('openai_api_key', '')) === '') {
        $trace[] = [
            'step' => 'openai_fallback_' . $kind,
            'status' => 'skipped',
            'detail' => 'OpenAI fallback skipped because no API key is configured.',
        ];
        return null;
    }

    $prompt = $kind === 'isbn'
        ? 'Find bibliographic metadata for this ISBN. If unknown, leave fields empty. ISBN: '
        : 'Find bibliographic metadata for this DOI. If unknown, leave fields empty. DOI: ';

    try {
        $result = openai_extract_source($prompt . $value);
    } catch (Throwable) {
        $trace[] = [
            'step' => 'openai_fallback_' . $kind,
            'status' => 'error',
            'detail' => 'OpenAI fallback request failed.',
        ];
        return null;
    }

    if (!is_array($result)) {
        $trace[] = [
            'step' => 'openai_fallback_' . $kind,
            'status' => 'no_result',
            'detail' => 'OpenAI fallback returned no structured metadata.',
        ];
        return null;
    }

    $authors = $result['authors'] ?? [];
    if (!is_array($authors)) {
        $authors = [];
    }
    $hasCoreData = trim((string) ($result['title'] ?? '')) !== ''
        || trim((string) ($result['year'] ?? '')) !== ''
        || $authors !== [];
    if (!$hasCoreData) {
        $trace[] = [
            'step' => 'openai_fallback_' . $kind,
            'status' => 'no_result',
            'detail' => 'OpenAI fallback returned low-confidence/empty metadata.',
        ];
        return null;
    }

    if ($kind === 'isbn') {
        if (trim((string) ($result['isbn'] ?? '')) === '') {
            $result['isbn'] = $value;
        }
        if (((string) ($result['type'] ?? '')) === '' || (string) $result['type'] === 'other') {
            $result['type'] = 'book';
        }
    }

    if ($kind === 'doi') {
        if (trim((string) ($result['doi'] ?? '')) === '') {
            $result['doi'] = $value;
        }
        if (((string) ($result['type'] ?? '')) === '' || (string) $result['type'] === 'other') {
            $result['type'] = 'article';
        }
    }

    $fallbackNote = 'Metadata is AI-assisted fallback because primary provider returned no result.';
    $notes = trim((string) ($result['notes'] ?? ''));
    $result['notes'] = $notes !== '' ? ($notes . ' ' . $fallbackNote) : $fallbackNote;
    $trace[] = [
        'step' => 'openai_fallback_' . $kind,
        'status' => 'success',
        'detail' => 'OpenAI fallback returned metadata.',
    ];

    return $result;
}

function fetch_primo_permalink_metadata(string $url): ?array
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
    $vid = (string) ($params['vid'] ?? '');
    $scope = (string) ($params['search_scope'] ?? '');
    $query = (string) ($params['query'] ?? '');
    $docid = strtolower((string) ($params['docid'] ?? ''));
    if ($vid === '' || $scope === '' || $query === '') {
        return null;
    }

    $apiUrl = sprintf(
        'https://%s/primaws/rest/pub/pnxs?vid=%s&lang=en&scope=%s&q=%s',
        $host,
        rawurlencode($vid),
        rawurlencode($scope),
        rawurlencode($query)
    );

    try {
        $json = http_get_json($apiUrl, ['User-Agent: exlibris/1.0']);
    } catch (Throwable) {
        return null;
    }

    $docs = $json['docs'] ?? [];
    if (!is_array($docs) || $docs === []) {
        return null;
    }

    $selected = null;
    foreach ($docs as $doc) {
        $recordId = strtolower((string) ($doc['pnx']['control']['recordid'][0] ?? ''));
        if ($docid !== '' && $recordId === $docid) {
            $selected = $doc;
            break;
        }
    }

    if (!is_array($selected)) {
        $selected = $docs[0];
    }

    return map_primo_doc_to_source($selected, $url);
}

function map_primo_doc_to_source(array $doc, string $url): array
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
            $normalized = normalize_isbn((string) $candidate);
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
