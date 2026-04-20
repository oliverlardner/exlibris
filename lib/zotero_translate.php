<?php
declare(strict_types=1);

/**
 * Zotero translation-server integration.
 *
 * A translation server runs hundreds of site-specific Zotero translators and
 * correctly identifies articles on publisher platforms (APA PsycNet, PubMed,
 * arXiv, Springer, JSTOR, SSRN, etc.) — including ones that are JavaScript-
 * rendered SPAs or that expose no scholarly <meta> tags in static HTML.
 *
 * Endpoint is configurable via `EXLIBRIS_ZOTERO_TRANSLATION_URL`. Default is
 * the public Manubot-hosted instance. For production, run your own:
 *
 *     docker run -d -p 1969:1969 --rm zotero/translation-server
 *     EXLIBRIS_ZOTERO_TRANSLATION_URL=http://localhost:1969/web
 */

function zotero_translation_endpoint(): string
{
    $configured = trim((string) (getenv('EXLIBRIS_ZOTERO_TRANSLATION_URL') ?: ''));
    if ($configured !== '') {
        return $configured;
    }

    return 'https://translate.manubot.org/web';
}

/**
 * Translate a URL via the Zotero translation server.
 * Returns a source-schema array on success, null when no translator matched
 * or the request failed.
 */
function zotero_translate_url(string $url, int $timeout = 10): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $endpoint = zotero_translation_endpoint();
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $url,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/plain'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'exlibris/1.0',
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $code >= 400) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }

    // Translation server returns an array of items (one per detected record).
    // 300-series responses with a selection map indicate ambiguity — we don't
    // follow those here; the user can refine manually.
    $item = $decoded[0] ?? null;
    if (!is_array($item) || !isset($item['itemType'])) {
        return null;
    }

    return map_zotero_item_to_source($item, $url);
}

function map_zotero_item_to_source(array $item, string $originalUrl): array
{
    static $typeMap = [
        'journalArticle'    => 'article',
        'conferencePaper'   => 'article',
        'magazineArticle'   => 'article',
        'newspaperArticle'  => 'article',
        'preprint'          => 'article',
        'book'              => 'book',
        'bookSection'       => 'book',
        'thesis'            => 'other',
        'report'            => 'other',
        'webpage'           => 'website',
        'blogPost'          => 'website',
        'videoRecording'    => 'video',
    ];

    $zType = (string) ($item['itemType'] ?? '');
    $type  = $typeMap[$zType] ?? 'other';

    $authors = [];
    foreach ((array) ($item['creators'] ?? []) as $creator) {
        if (!is_array($creator)) {
            continue;
        }
        $role = (string) ($creator['creatorType'] ?? 'author');
        if ($role !== '' && $role !== 'author') {
            continue;
        }
        $first = trim((string) ($creator['firstName'] ?? ''));
        $last  = trim((string) ($creator['lastName'] ?? ''));
        $name  = trim((string) ($creator['name'] ?? ''));

        if ($last !== '' || $first !== '') {
            $composed = trim($last . ($first !== '' ? ', ' . $first : ''), " \t,");
            if ($composed !== '') {
                $authors[] = $composed;
            }
        } elseif ($name !== '') {
            $authors[] = $name;
        }
    }

    $dateRaw = (string) ($item['date'] ?? '');
    $year = '';
    if ($dateRaw !== '' && preg_match('/\b(19|20)\d{2}\b/', $dateRaw, $m)) {
        $year = $m[0];
    }

    $isbnRaw = (string) ($item['ISBN'] ?? '');
    $isbn    = preg_replace('/[^0-9Xx]/', '', $isbnRaw) ?? '';

    $abstract = trim((string) ($item['abstractNote'] ?? ''));

    return [
        'type'      => $type,
        'title'     => trim((string) ($item['title'] ?? '')),
        'authors'   => $authors,
        'year'      => $year,
        'publisher' => trim((string) ($item['publisher'] ?? '')),
        'journal'   => trim((string) (($item['publicationTitle'] ?? '') ?: ($item['bookTitle'] ?? ''))),
        'volume'    => trim((string) ($item['volume'] ?? '')),
        'issue'     => trim((string) ($item['issue'] ?? '')),
        'pages'     => trim((string) ($item['pages'] ?? '')),
        'doi'       => trim((string) ($item['DOI'] ?? '')),
        'isbn'      => $isbn,
        'url'       => $originalUrl,
        'notes'     => $abstract,
    ];
}
