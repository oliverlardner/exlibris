<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

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
