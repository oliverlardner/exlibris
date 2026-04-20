<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

/**
 * Full-text title (+ optional author) search against Open Library.
 * Chains through lookup_isbn_openlibrary() when the search yields an ISBN
 * so we get the richer /api/books payload.
 */
function search_openlibrary_by_title(string $title, string $author = ''): ?array
{
    $title = trim($title);
    if ($title === '') {
        return null;
    }

    // Open Library's q= combined query beats separate title/author params.
    // Quoted exact title gives most precise results; fall back to looser queries.
    $fields  = 'key,title,author_name,first_publish_year,isbn,publisher';
    $baseParams = ['fields' => $fields, 'limit' => '5'];

    $queries = [];
    if ($author !== '') {
        $queries[] = '"' . $title . '" ' . $author;  // exact title + author
    }
    $queries[] = '"' . $title . '"';                  // exact title only
    $queries[] = $author !== '' ? ($title . ' ' . $author) : $title; // unquoted fallback

    $docs = null;
    foreach ($queries as $query) {
        $url = 'https://openlibrary.org/search.json?' . http_build_query(['q' => $query] + $baseParams);
        try {
            $data = http_get_json($url, ['User-Agent: exlibris/1.0']);
        } catch (Throwable) {
            continue;
        }
        $candidate = $data['docs'] ?? [];
        if (is_array($candidate) && $candidate !== []) {
            $docs = $candidate;
            break;
        }
    }

    if ($docs === null || $docs === []) {
        return null;
    }

    $doc = $docs[0];

    // Pick the first ISBN-13, then fall back to ISBN-10
    $isbn = '';
    foreach ((array) ($doc['isbn'] ?? []) as $raw) {
        $clean = preg_replace('/[^0-9Xx]/', '', (string) $raw) ?? '';
        if (strlen($clean) === 13 && $isbn === '') {
            $isbn = $clean;
        }
    }
    if ($isbn === '') {
        foreach ((array) ($doc['isbn'] ?? []) as $raw) {
            $clean = preg_replace('/[^0-9Xx]/', '', (string) $raw) ?? '';
            if (strlen($clean) === 10 && $isbn === '') {
                $isbn = $clean;
            }
        }
    }

    // Chain to the richer /api/books endpoint when we have an ISBN
    if ($isbn !== '') {
        try {
            $detailed = lookup_isbn_openlibrary($isbn);
            if (is_array($detailed)) {
                return $detailed;
            }
        } catch (Throwable) {}
    }

    // Map search doc directly as a fallback
    $authors    = array_values(array_filter(array_map('trim', (array) ($doc['author_name'] ?? []))));
    $publishers = (array) ($doc['publisher'] ?? []);

    return [
        'type'      => 'book',
        'title'     => (string) ($doc['title'] ?? ''),
        'authors'   => $authors,
        'year'      => (string) ($doc['first_publish_year'] ?? ''),
        'publisher' => trim((string) ($publishers[0] ?? '')),
        'journal'   => '',
        'volume'    => '',
        'issue'     => '',
        'pages'     => '',
        'doi'       => '',
        'isbn'      => $isbn,
        'url'       => isset($doc['key']) ? 'https://openlibrary.org' . (string) $doc['key'] : '',
    ];
}

function lookup_isbn_openlibrary(string $isbn): ?array
{
    $isbn = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
    if ($isbn === '') {
        return null;
    }

    $url = 'https://openlibrary.org/api/books?bibkeys=ISBN:' . rawurlencode($isbn) . '&jscmd=data&format=json';
    $data = http_get_json($url, ['User-Agent: exlibris/1.0']);
    $book = $data['ISBN:' . $isbn] ?? null;
    if (!is_array($book)) {
        return null;
    }

    $authors = [];
    foreach (($book['authors'] ?? []) as $author) {
        $name = trim((string) ($author['name'] ?? ''));
        if ($name !== '') {
            $authors[] = $name;
        }
    }

    $publishDate = (string) ($book['publish_date'] ?? '');
    preg_match('/\b(1[0-9]{3}|20[0-9]{2}|2100)\b/', $publishDate, $matches);

    return [
        'type' => 'book',
        'title' => (string) ($book['title'] ?? ''),
        'authors' => $authors,
        'year' => $matches[0] ?? '',
        'publisher' => (string) (($book['publishers'][0]['name'] ?? '') ?: ''),
        'journal' => '',
        'volume' => '',
        'issue' => '',
        'pages' => '',
        'doi' => '',
        'isbn' => $isbn,
        'url' => (string) ($book['url'] ?? ''),
    ];
}
