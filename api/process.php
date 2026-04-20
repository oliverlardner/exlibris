<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/crossref.php';
require_once __DIR__ . '/../lib/openlibrary.php';
require_once __DIR__ . '/../lib/openai.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/primo.php';
require_once __DIR__ . '/../lib/zotero_translate.php';
require_once __DIR__ . '/../lib/openalex.php';
require_once __DIR__ . '/../lib/semantic_scholar.php';

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
        $result = lookup_doi_with_fallbacks($doi, $lookupTrace);
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
                    'notes' => 'DOI detected but no provider (CrossRef, OpenAlex, Semantic Scholar) returned metadata. Add details manually.',
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

        // Anna's Archive /md5/ pages → extract ISBN → Open Library
        if (is_annas_archive_url($input)) {
            $inputType = 'url_annas_archive';
            $aaResult  = handle_annas_archive_url($input, $lookupTrace);
            if (is_array($aaResult)) {
                $result                = $aaResult;
                $result['accessed_at'] = gmdate('c');
            }
        }

        // Generic URL handler (also runs as fallback when AA extraction failed)
        if (!is_array($result)) {
            // 1) Zotero translation server — handles publisher SPAs (APA PsycNet,
            //    PubMed, arXiv, Springer, JSTOR, …) where static HTML has no
            //    useful meta tags. When a site-specific translator identifies the
            //    work we trust it outright; but if Zotero fell back to its
            //    generic `embedded-metadata` translator and produced a bare stub
            //    (itemType=webpage/blogPost with no creators/date/publisher),
            //    we treat that as "no translator" and fall through to the og:*/
            //    OpenAI chain, which can extract much richer data from pages
            //    like corporate blog posts. The stub is still kept and merged
            //    in at the end as a low-priority fallback.
            try {
                $ztResult = zotero_translate_url($input);
            } catch (Throwable) {
                $ztResult = null;
            }
            $ztFallback = null;
            $ztIsStrong = is_array($ztResult)
                && trim((string) ($ztResult['title'] ?? '')) !== ''
                && !is_weak_zotero_stub($ztResult);

            if ($ztIsStrong) {
                $lookupTrace[] = [
                    'step'   => 'zotero_translate',
                    'status' => 'success',
                    'detail' => 'Zotero translation server identified the work for this URL.',
                ];
                $ztResult['accessed_at'] = gmdate('c');
                $result = $ztResult;
            } else {
                if (is_array($ztResult) && trim((string) ($ztResult['title'] ?? '')) !== '') {
                    // Weak generic-webpage stub — keep for end-of-chain fallback merge.
                    $ztFallback = $ztResult;
                    $lookupTrace[] = [
                        'step'   => 'zotero_translate',
                        'status' => 'no_result',
                        'detail' => 'Zotero translation server returned a generic webpage stub (no authors/date); falling through to meta tags + AI.',
                    ];
                } else {
                    $lookupTrace[] = [
                        'step'   => 'zotero_translate',
                        'status' => 'no_result',
                        'detail' => 'Zotero translation server had no translator for this URL.',
                    ];
                }

                // 2) OpenAlex / Semantic Scholar direct URL resolution —
                //    covers works where an indexer already knows the landing URL
                //    (arXiv, Semantic Scholar's corpus, some publisher pages).
                $directResult = null;
                try {
                    $oaByUrl = openalex_by_url($input);
                    $hasTitle = is_array($oaByUrl) && trim((string) ($oaByUrl['title'] ?? '')) !== '';
                    $lookupTrace[] = [
                        'step'   => 'openalex_url',
                        'status' => $hasTitle ? 'success' : 'no_result',
                        'detail' => $hasTitle ? 'OpenAlex matched the landing URL to a work.' : 'OpenAlex had no match for this URL.',
                    ];
                    if ($hasTitle) {
                        $directResult = $oaByUrl;
                        $directResult['url'] = $input;
                    }
                } catch (Throwable) {
                    $lookupTrace[] = ['step' => 'openalex_url', 'status' => 'error', 'detail' => 'OpenAlex URL lookup failed.'];
                }

                if (!is_array($directResult)) {
                    try {
                        $ssByUrl = semantic_scholar_by_url($input);
                        $hasTitle = is_array($ssByUrl) && trim((string) ($ssByUrl['title'] ?? '')) !== '';
                        $lookupTrace[] = [
                            'step'   => 'semantic_scholar_url',
                            'status' => $hasTitle ? 'success' : 'no_result',
                            'detail' => $hasTitle ? 'Semantic Scholar matched the URL to a paper.' : 'Semantic Scholar had no match for this URL.',
                        ];
                        if ($hasTitle) {
                            $directResult = $ssByUrl;
                            $directResult['url'] = $input;
                        }
                    } catch (Throwable) {
                        $lookupTrace[] = ['step' => 'semantic_scholar_url', 'status' => 'error', 'detail' => 'Semantic Scholar URL lookup failed.'];
                    }
                }

                if (is_array($directResult)) {
                    $directResult['accessed_at'] = gmdate('c');
                    $result = $directResult;
                } else {
                    $pageHtml = fetch_page_html($input);
                    $pageText = html_to_text($pageHtml);
                    $urlMeta  = extract_url_metadata($input, $pageHtml);
                    $urlMetaDetail = str_contains((string) ($urlMeta['notes'] ?? ''), 'Primo permalink API')
                        ? 'Parsed metadata from Primo permalink API and page tags.'
                        : 'Parsed metadata from URL/oEmbed tags when available.';
                    $lookupTrace[] = [
                        'step'   => 'url_metadata',
                        'status' => 'success',
                        'detail' => $urlMetaDetail,
                    ];
                    $aiResult = null;

                    // URL extraction should still work even if OpenAI is unavailable.
                    try {
                        $aiResult   = openai_extract_source($pageText !== '' ? $pageText : $input, $input);
                        $aiAuthors  = is_array($aiResult) && is_array($aiResult['authors'] ?? null) ? $aiResult['authors'] : [];
                        $aiHasCore  = is_array($aiResult)
                            && (
                                trim((string) ($aiResult['title'] ?? '')) !== ''
                                || trim((string) ($aiResult['year'] ?? '')) !== ''
                                || $aiAuthors !== []
                            );
                        $lookupTrace[] = [
                            'step'   => 'openai_url',
                            'status' => $aiHasCore ? 'success' : 'no_result',
                            'detail' => $aiHasCore ? 'OpenAI returned metadata from page text.' : 'OpenAI returned low-confidence/empty metadata.',
                        ];
                    } catch (Throwable) {
                        $aiResult = null;
                        $lookupTrace[] = [
                            'step'   => 'openai_url',
                            'status' => 'error',
                            'detail' => 'OpenAI URL extraction failed.',
                        ];
                    }

                    // Anti-hallucination guard: when the page content contains no
                    // trace of the AI's claimed DOI/ISBN/title words/author surnames,
                    // the model is fabricating from the URL alone. Discard entirely
                    // — otherwise CrossRef will happily confirm the fake DOI and
                    // present a completely wrong article as authoritative.
                    if (is_array($aiResult)) {
                        $verifiedAi = verify_ai_output_against_page($aiResult, $pageHtml, $pageText);
                        if ($verifiedAi === null) {
                            $lookupTrace[] = [
                                'step'   => 'ai_verification',
                                'status' => 'rejected',
                                'detail' => 'AI-extracted metadata did not appear in the page; discarded to prevent hallucination.',
                            ];
                            $aiResult = null;
                        } else {
                            $aiResult = $verifiedAi;
                        }
                    }

                    // If we have a DOI (from page meta tags OR verified AI output),
                    // cascade through CrossRef → OpenAlex → Semantic Scholar for
                    // authoritative article metadata.
                    $doiResult = null;
                    $aiDoi = is_array($aiResult) ? trim((string) ($aiResult['doi'] ?? '')) : '';
                    $urlHasDoi = trim((string) ($urlMeta['doi'] ?? '')) !== '';
                    $doiToCheck = $urlHasDoi ? trim((string) $urlMeta['doi']) : $aiDoi;
                    if ($doiToCheck !== '' && preg_match('/10\.\d{4,9}\/[-._;()\/:A-Z0-9]+/i', $doiToCheck, $dm)) {
                        $doiResult = lookup_doi_with_fallbacks($dm[0], $lookupTrace);
                    }

                    $urlMetaStrongTitle = (bool) ($urlMeta['_strong_title'] ?? false);
                    $urlMetaIsArticle   = (bool) ($urlMeta['_is_article'] ?? false);
                    unset($urlMeta['_strong_title'], $urlMeta['_is_article']);

                    if (is_array($doiResult)) {
                        // Authoritative article data; retain the original URL.
                        $doiResult['url'] = $input;
                        if (trim((string) ($doiResult['type'] ?? '')) === '' || $doiResult['type'] === 'other') {
                            $doiResult['type'] = 'article';
                        }
                        $result = merge_source_candidates($doiResult, $aiResult, $input);
                    } elseif (is_array($aiResult) && ($aiDoi !== '' || trim((string) ($aiResult['journal'] ?? '')) !== '') && !$urlMetaStrongTitle) {
                        // AI identified a specific work and the URL meta only had
                        // a generic <title>-tag fallback — flip the merge so the
                        // AI's article title/type win over the site's name.
                        $result = merge_source_candidates($aiResult, $urlMeta, $input);
                        if (trim((string) ($result['type'] ?? '')) === '' || $result['type'] === 'website' || $result['type'] === 'other') {
                            $result['type'] = 'article';
                        }
                    } else {
                        $result = merge_source_candidates($urlMeta, $aiResult, $input);
                        if (($urlMetaIsArticle || trim((string) ($result['doi'] ?? '')) !== '' || trim((string) ($result['journal'] ?? '')) !== '')
                            && (trim((string) ($result['type'] ?? '')) === '' || $result['type'] === 'website' || $result['type'] === 'other')
                        ) {
                            $result['type'] = 'article';
                        }
                    }

                    // Last-resort title search: if we still have no title but
                    // scraped/AI extraction gave us *something* to search with,
                    // try OpenAlex's full-text search before giving up.
                    if (trim((string) ($result['title'] ?? '')) === '') {
                        $searchTitle = trim((string) (($aiResult['title'] ?? '') ?: ($urlMeta['title'] ?? '')));
                        $searchAuthor = trim((string) ((($aiResult['authors'][0] ?? '')) ?: ($urlMeta['authors'][0] ?? '')));
                        if ($searchTitle !== '') {
                            try {
                                $oaSearch = openalex_by_title($searchTitle, $searchAuthor !== '' ? $searchAuthor : null);
                                $hasTitle = is_array($oaSearch) && trim((string) ($oaSearch['title'] ?? '')) !== '';
                                $lookupTrace[] = [
                                    'step'   => 'openalex_search',
                                    'status' => $hasTitle ? 'success' : 'no_result',
                                    'detail' => $hasTitle ? 'OpenAlex search found a candidate work.' : 'OpenAlex search returned no usable result.',
                                ];
                                if ($hasTitle) {
                                    $oaSearch['url'] = $input;
                                    $result = merge_source_candidates($oaSearch, $result, $input);
                                }
                            } catch (Throwable) {
                                $lookupTrace[] = ['step' => 'openalex_search', 'status' => 'error', 'detail' => 'OpenAlex search failed.'];
                            }
                        }
                    }

                    // Fold the Zotero generic-webpage stub in as a low-priority
                    // fallback — fills any gaps the higher-confidence sources
                    // left (occasionally Zotero picks up a date or abstract the
                    // meta tags lack).
                    if (is_array($ztFallback)) {
                        $result = merge_source_candidates($result, $ztFallback, $input);
                    }

                    $result['accessed_at'] = gmdate('c');

                    // For generic URLs that resolve to a book (ISBN present), enrich via OL
                    $result = enrich_with_openlibrary($result, $lookupTrace);
                }
            }
        }
    } else {
        // Free-text input: AI recall + Open Library enrichment
        $result = openai_extract_source($input);
        $lookupTrace[] = [
            'step'   => 'openai_extract',
            'status' => is_array($result) ? 'success' : 'no_result',
            'detail' => is_array($result) ? 'AI extracted/identified metadata from input.' : 'AI returned no result.',
        ];
        $result = enrich_with_openlibrary($result, $lookupTrace);

        // When confidence is low (no ISBN/DOI, missing title or year, or title
        // doesn't match the input at all), generate a list of OL candidates.
        if (is_low_confidence_result($result, $input)) {
            $suggestions = generate_suggestions($input, is_array($result) ? $result : []);
            if ($suggestions !== []) {
                $lookupTrace[] = [
                    'step'   => 'suggestions',
                    'status' => 'success',
                    'detail' => count($suggestions) . ' candidate(s) returned from Open Library.',
                ];
            }
        }
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
        'ok'           => true,
        'input_type'   => $inputType,
        'source'       => $result,
        'suggestions'  => $suggestions ?? [],
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

/**
 * Cascades through DOI-resolving providers until one returns usable metadata.
 *
 * Order: CrossRef → OpenAlex → Semantic Scholar. The first provider that
 * yields a title (the minimum useful signal) wins. Every attempt is recorded
 * in $trace so the UI can show the provenance.
 */
function lookup_doi_with_fallbacks(string $doi, array &$trace): ?array
{
    $doi = trim($doi);
    if ($doi === '') {
        return null;
    }

    // 1) CrossRef — the canonical DOI registry for scholarly works.
    try {
        $crossref = lookup_doi_crossref($doi);
        $hasTitle = is_array($crossref) && trim((string) ($crossref['title'] ?? '')) !== '';
        $trace[] = [
            'step'   => 'crossref',
            'status' => $hasTitle ? 'success' : 'no_result',
            'detail' => $hasTitle ? 'CrossRef returned metadata.' : 'CrossRef returned no result.',
        ];
        if ($hasTitle) {
            return $crossref;
        }
    } catch (Throwable) {
        $trace[] = ['step' => 'crossref', 'status' => 'error', 'detail' => 'CrossRef request failed.'];
    }

    // 2) OpenAlex — covers many preprints and OA works CrossRef may miss.
    try {
        $openalex = openalex_by_doi($doi);
        $hasTitle = is_array($openalex) && trim((string) ($openalex['title'] ?? '')) !== '';
        $trace[] = [
            'step'   => 'openalex',
            'status' => $hasTitle ? 'success' : 'no_result',
            'detail' => $hasTitle ? 'OpenAlex returned metadata for the DOI.' : 'OpenAlex had no result for the DOI.',
        ];
        if ($hasTitle) {
            return $openalex;
        }
    } catch (Throwable) {
        $trace[] = ['step' => 'openalex', 'status' => 'error', 'detail' => 'OpenAlex request failed.'];
    }

    // 3) Semantic Scholar — last structured backup before AI.
    try {
        $ss = semantic_scholar_by_doi($doi);
        $hasTitle = is_array($ss) && trim((string) ($ss['title'] ?? '')) !== '';
        $trace[] = [
            'step'   => 'semantic_scholar',
            'status' => $hasTitle ? 'success' : 'no_result',
            'detail' => $hasTitle ? 'Semantic Scholar returned metadata for the DOI.' : 'Semantic Scholar had no result for the DOI.',
        ];
        if ($hasTitle) {
            return $ss;
        }
    } catch (Throwable) {
        $trace[] = ['step' => 'semantic_scholar', 'status' => 'error', 'detail' => 'Semantic Scholar request failed.'];
    }

    return null;
}

function fetch_page_html(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    $html = curl_exec($ch);
    if (!is_string($html) || $html === '') {
        curl_close($ch);
        return '';
    }
    curl_close($ch);

    return $html;
}

// ── Anna's Archive helpers ────────────────────────────────────────────────────

function is_annas_archive_url(string $url): bool
{
    $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
    return (bool) preg_match('/(?:^|\.)annas?-archive\./i', $host);
}

/**
 * Extracts all 13-digit ISBN-13 strings embedded in raw HTML.
 * Looks for both hyphenated and unhyphenated forms.
 *
 * @return string[]
 */
function extract_isbn13s_from_html(string $html): array
{
    $found = [];
    // Match raw 13-digit runs starting with 978/979
    if (preg_match_all('/\b(97[89]\d{10})\b/', $html, $m)) {
        foreach ($m[1] as $isbn) {
            if (!in_array($isbn, $found, true)) {
                $found[] = $isbn;
            }
        }
    }
    // Match hyphenated: 978-0-631-14048-1 style
    if (preg_match_all('/\b(97[89](?:[- ]\d+){3}[- ]\d)\b/', $html, $m)) {
        foreach ($m[1] as $raw) {
            $clean = preg_replace('/[^0-9]/', '', $raw) ?? '';
            if (strlen($clean) === 13 && !in_array($clean, $found, true)) {
                $found[] = $clean;
            }
        }
    }
    return $found;
}

/**
 * Fetches an Anna's Archive /md5/ page, extracts the ISBN(s) it lists,
 * and chains through Open Library for authoritative metadata.
 */
function handle_annas_archive_url(string $url, array &$trace): ?array
{
    $html = fetch_page_html($url);
    if ($html === '') {
        $trace[] = ['step' => 'annas_archive', 'status' => 'error', 'detail' => 'Could not fetch Anna\'s Archive page.'];
        return null;
    }

    $isbns = extract_isbn13s_from_html($html);
    if ($isbns === []) {
        $trace[] = ['step' => 'annas_archive', 'status' => 'no_result', 'detail' => 'No ISBN-13 found on Anna\'s Archive page.'];
        return null;
    }

    $isbn = $isbns[0];
    $trace[] = ['step' => 'annas_archive_isbn', 'status' => 'success', 'detail' => 'Extracted ISBN ' . $isbn . ' from Anna\'s Archive page.'];

    try {
        $olResult = lookup_isbn_openlibrary($isbn);
        if (is_array($olResult)) {
            $trace[] = ['step' => 'openlibrary', 'status' => 'success', 'detail' => 'Open Library returned metadata for ISBN ' . $isbn . '.'];
            $olResult['url'] = $url;
            return $olResult;
        }
    } catch (Throwable) {}

    $trace[] = ['step' => 'openlibrary', 'status' => 'no_result', 'detail' => 'Open Library had no result; returning ISBN stub.'];
    return [
        'type'      => 'book',
        'title'     => '',
        'authors'   => [],
        'year'      => '',
        'publisher' => '',
        'journal'   => '',
        'volume'    => '',
        'issue'     => '',
        'pages'     => '',
        'doi'       => '',
        'isbn'      => $isbn,
        'url'       => $url,
        'notes'     => '',
    ];
}

/**
 * Cross-verifies AI-extracted URL metadata against the raw page content.
 *
 * OpenAI will confidently fabricate a plausible article (title, authors, DOI,
 * journal) when given only a URL and a near-empty page (e.g. JS-rendered SPAs
 * behind anti-bot shields). We mitigate that by requiring *some* trace of the
 * AI's claims in the raw HTML or extracted text:
 *
 *   - DOI field is cleared if the DOI string doesn't appear in the page.
 *   - ISBN field is cleared if the digits don't appear in the page.
 *   - If NONE of the following appear in the page content — AI's DOI, ISBN,
 *     any >4-char title word, or any author surname — the AI is very likely
 *     hallucinating from the URL alone, and the entire result is discarded
 *     (returns null).
 *
 * Returns the (possibly filtered) AI array, or null if the whole thing was
 * rejected as a hallucination.
 */
function verify_ai_output_against_page(array $ai, string $rawHtml, string $pageText): ?array
{
    $combined = strtolower($rawHtml . ' ' . $pageText);
    if (strlen(trim($combined)) < 200) {
        return $ai;
    }
    $digits = preg_replace('/[^0-9Xx]/', '', $combined) ?? '';

    $doi = trim((string) ($ai['doi'] ?? ''));
    $doiPresent = $doi !== '' && str_contains($combined, strtolower($doi));
    if ($doi !== '' && !$doiPresent) {
        $ai['doi'] = '';
    }

    $isbnDigits = preg_replace('/[^0-9Xx]/', '', (string) ($ai['isbn'] ?? '')) ?? '';
    $isbnPresent = $isbnDigits !== '' && strlen($isbnDigits) >= 10 && str_contains($digits, $isbnDigits);
    if ($isbnDigits !== '' && !$isbnPresent) {
        $ai['isbn'] = '';
    }

    $titlePresent = false;
    $title = strtolower(trim((string) ($ai['title'] ?? '')));
    if ($title !== '') {
        $words = array_filter(
            preg_split('/\W+/u', $title) ?: [],
            static fn (string $w): bool => mb_strlen($w) > 4
        );
        foreach ($words as $w) {
            if (str_contains($combined, $w)) {
                $titlePresent = true;
                break;
            }
        }
        // 2-word titles are easy to hallucinate; if nothing >4 chars, accept it.
        if ($words === []) {
            $titlePresent = true;
        }
    }

    $authorPresent = false;
    foreach ((array) ($ai['authors'] ?? []) as $author) {
        $author = (string) $author;
        $surname = strtolower(trim((string) (explode(',', $author)[0] ?? '')));
        $parts = preg_split('/\s+/', $surname) ?: [];
        $last = (string) end($parts);
        if (mb_strlen($last) >= 3 && str_contains($combined, $last)) {
            $authorPresent = true;
            break;
        }
    }

    if (!$doiPresent && !$isbnPresent && !$titlePresent && !$authorPresent) {
        return null;
    }

    return $ai;
}

/**
 * Detects the Zotero translation server's generic-webpage stub.
 *
 * When no site-specific translator matches, Zotero falls back to its
 * `embedded-metadata` translator, which for non-scholarly pages often yields
 * only an itemType of `webpage`/`blogPost` plus the raw HTML `<title>` (and
 * sometimes not even a clean one — "Article title \ Site Name").
 *
 * Returning that stub as authoritative makes us skip the og:* / OpenAI chain
 * and end up with a worse record than we would have assembled ourselves.
 * Anything richer than bare title + itemType (creators, date, publisher, or
 * abstract) counts as a real translator hit.
 */
function is_weak_zotero_stub(array $ztResult): bool
{
    $hasAuthors   = !empty($ztResult['authors'] ?? []);
    $hasYear      = trim((string) ($ztResult['year'] ?? '')) !== '';
    $hasPublisher = trim((string) ($ztResult['publisher'] ?? '')) !== '';
    $hasAbstract  = trim((string) ($ztResult['notes'] ?? '')) !== '';
    $hasDoi       = trim((string) ($ztResult['doi'] ?? '')) !== '';
    $hasIsbn      = trim((string) ($ztResult['isbn'] ?? '')) !== '';

    if ($hasAuthors || $hasYear || $hasPublisher || $hasAbstract || $hasDoi || $hasIsbn) {
        return false;
    }

    $type = strtolower((string) ($ztResult['type'] ?? ''));
    return $type === 'website' || $type === '' || $type === 'other';
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
        // Internal flags stripped by the caller before final output.
        '_strong_title' => false,
        '_is_article'   => false,
    ];

    $primoMeta = primo_fetch_permalink_metadata($url, 'normalize_isbn');
    if (is_array($primoMeta)) {
        $meta = merge_source_candidates($primoMeta, $meta, $url);
        $meta['_strong_title'] = trim((string) ($primoMeta['title'] ?? '')) !== '';
        $meta['_is_article']   = strtolower((string) ($primoMeta['type'] ?? '')) === 'article';
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
            $meta['_strong_title'] = $meta['title'] !== '';
        }
    }

    if ($pageText !== '') {
        // Scholarly Google Scholar `citation_*` tags — when present, this is an
        // article page. These take priority over og:* / <title> because they are
        // specifically about the article, not the site/landing page.
        $citationTitle   = extract_meta_value($pageText, 'name', 'citation_title');
        $citationJournal = extract_meta_value($pageText, 'name', 'citation_journal_title');
        $citationDoi     = extract_meta_value($pageText, 'name', 'citation_doi');
        $citationVolume  = extract_meta_value($pageText, 'name', 'citation_volume');
        $citationIssue   = extract_meta_value($pageText, 'name', 'citation_issue');
        $citationFirst   = extract_meta_value($pageText, 'name', 'citation_firstpage');
        $citationLast    = extract_meta_value($pageText, 'name', 'citation_lastpage');
        $citationPub     = extract_meta_value($pageText, 'name', 'citation_publisher');
        $citationIsbn    = normalize_isbn(extract_meta_value($pageText, 'name', 'citation_isbn'));

        $hasCitationSignal = ($citationTitle !== '' || $citationJournal !== '' || $citationDoi !== '');
        if ($hasCitationSignal) {
            $meta['_is_article'] = true;
            if (trim((string) $meta['type']) === '' || $meta['type'] === 'website' || $meta['type'] === 'other') {
                $meta['type'] = 'article';
            }
        }

        $citationAuthors = extract_meta_values($pageText, 'name', 'citation_author');
        if ($citationAuthors !== []) {
            $meta['authors'] = $citationAuthors;
        }

        if ($citationTitle !== '') {
            $meta['title'] = $citationTitle;
            $meta['_strong_title'] = true;
        }

        if ($meta['title'] === '') {
            $ogTitle = extract_meta_value($pageText, 'property', 'og:title');
            $nmTitle = extract_meta_value($pageText, 'name', 'title');
            if ($ogTitle !== '' || $nmTitle !== '') {
                $meta['title'] = $ogTitle ?: $nmTitle;
                $meta['_strong_title'] = true;
            } else {
                $meta['title'] = extract_title_tag($pageText);
                // bare <title>-tag is a weak signal — often the site name, not the work title
                $meta['_strong_title'] = false;
            }
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

        if ($meta['journal'] === '' && $citationJournal !== '') {
            $meta['journal'] = $citationJournal;
        }

        if ($meta['publisher'] === '') {
            // Prefer a real publisher signal; fall back to og:site_name only when
            // no scholarly publisher is present. Never use citation_journal_title
            // here — that's the journal, not the publisher.
            $meta['publisher'] = $citationPub
                ?: extract_meta_value($pageText, 'property', 'og:site_name');
        }

        if ($meta['doi'] === '' && $citationDoi !== '') {
            $meta['doi'] = $citationDoi;
        }
        if ($meta['volume'] === '' && $citationVolume !== '') {
            $meta['volume'] = $citationVolume;
        }
        if ($meta['issue'] === '' && $citationIssue !== '') {
            $meta['issue'] = $citationIssue;
        }
        if ($meta['pages'] === '' && ($citationFirst !== '' || $citationLast !== '')) {
            $meta['pages'] = $citationFirst . ($citationLast !== '' ? '-' . $citationLast : '');
        }
        if ($meta['isbn'] === '' && $citationIsbn !== '') {
            $meta['isbn'] = $citationIsbn;
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

// ── Suggestion generation ─────────────────────────────────────────────────────

/**
 * Returns true when suggestions would be useful to the user:
 *
 *   1. No canonical identifier (ISBN/DOI) AND missing title or year — clearly
 *      incomplete.
 *   2. Result has an identifier but the extracted title shares NO meaningful
 *      words with the raw input AND the input itself contains no identifier —
 *      signals the AI hallucinated a specific work for an author-name input.
 */
function is_low_confidence_result(?array $result, string $rawInput = ''): bool
{
    if (!is_array($result)) {
        return true;
    }

    $hasIsbn  = trim((string) ($result['isbn'] ?? '')) !== '';
    $hasDoi   = trim((string) ($result['doi'] ?? '')) !== '';
    $hasTitle = trim((string) ($result['title'] ?? '')) !== '';
    $hasYear  = trim((string) ($result['year'] ?? '')) !== '';

    // Case 1: no identifier and incomplete metadata
    if (!$hasIsbn && !$hasDoi && !($hasTitle && $hasYear)) {
        return true;
    }

    // Case 2: identifier present but title doesn't overlap with the raw input,
    // and the input itself doesn't contain a DOI/ISBN (user gave an author name
    // and AI hallucinated a specific work).
    if ($rawInput !== '' && !preg_match('/\b(?:10\.\d{4,9}\/|97[89]\d{10})\b/', $rawInput)) {
        $inputWords = array_filter(
            preg_split('/\W+/', strtolower(trim($rawInput))) ?: [],
            static fn (string $w): bool => strlen($w) > 3
        );
        if ($inputWords !== []) {
            $title    = strtolower(trim((string) ($result['title'] ?? '')));
            $overlaps = array_filter($inputWords, static fn (string $w): bool => str_contains($title, $w));
            if ($overlaps === []) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Searches Open Library for up to 5 candidate works based on what the AI
 * extracted (or the raw input if nothing useful was extracted).
 * Sorts by `editions` so canonical/well-known works float first.
 *
 * @return array<int, array<string, mixed>>
 */
function generate_suggestions(string $rawInput, ?array $aiResult): array
{
    $author = trim((string) (($aiResult['authors'][0] ?? '')));
    $title  = trim((string) ($aiResult['title'] ?? ''));

    // For author-only inputs use the dedicated `author=` field; for everything
    // else use a combined `q=` query. Avoid `sort=editions` — it returns globally
    // popular books unrelated to the query.
    if ($author !== '' && $title === '') {
        $params = [
            'author' => $author,
            'fields' => 'key,title,author_name,first_publish_year,isbn,publisher',
            'limit'  => '5',
        ];
    } elseif ($title !== '') {
        $query  = $author !== '' ? ('"' . $title . '" ' . $author) : ('"' . $title . '"');
        $params = [
            'q'      => $query,
            'fields' => 'key,title,author_name,first_publish_year,isbn,publisher',
            'limit'  => '5',
        ];
    } else {
        $params = [
            'q'      => $rawInput,
            'fields' => 'key,title,author_name,first_publish_year,isbn,publisher',
            'limit'  => '5',
        ];
    }

    $docs = [];
    foreach ([$params, ['q' => $rawInput, 'fields' => $params['fields'], 'limit' => $params['limit']]] as $attempt) {
        if ($docs !== []) {
            break;
        }
        if (($attempt['q'] ?? $attempt['author'] ?? '') === '') {
            continue;
        }
        try {
            $data = http_get_json(
                'https://openlibrary.org/search.json?' . http_build_query($attempt),
                ['User-Agent: exlibris/1.0']
            );
            $candidate = $data['docs'] ?? [];
            if (is_array($candidate) && $candidate !== []) {
                $docs = $candidate;
            }
        } catch (Throwable) {}
    }

    if ($docs === []) {
        return [];
    }

    $suggestions = [];
    foreach ($docs as $doc) {
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
        $publishers = (array) ($doc['publisher'] ?? []);
        $authors    = array_values(array_filter(array_map('trim', (array) ($doc['author_name'] ?? []))));
        $suggestions[] = [
            'type'      => 'book',
            'title'     => (string) ($doc['title'] ?? ''),
            'authors'   => $authors,
            'year'      => (string) ($doc['first_publish_year'] ?? ''),
            'publisher' => trim((string) ($publishers[0] ?? '')),
            'isbn'      => $isbn,
            'doi'       => '',
            'url'       => isset($doc['key']) ? 'https://openlibrary.org' . (string) $doc['key'] : '',
            'journal'   => '',
            'volume'    => '',
            'issue'     => '',
            'pages'     => '',
            'notes'     => '',
        ];
    }

    return $suggestions;
}

// ── Open Library enrichment ───────────────────────────────────────────────────

/**
 * After AI extraction, attempt to enrich the result with authoritative Open
 * Library data.  If the AI produced an ISBN we do a direct lookup; otherwise
 * we try a title/author search.  Only runs for book-like types to avoid false
 * matches on articles, videos, etc.
 */
function enrich_with_openlibrary(?array $result, array &$trace): ?array
{
    if (!is_array($result)) {
        return $result;
    }

    $isbn = trim((string) ($result['isbn'] ?? ''));
    if ($isbn !== '') {
        try {
            $olResult = lookup_isbn_openlibrary($isbn);
            if (is_array($olResult)) {
                $trace[] = ['step' => 'openlibrary_isbn', 'status' => 'success', 'detail' => 'Open Library enriched metadata via ISBN.'];
                return merge_source_candidates($olResult, $result, (string) ($result['url'] ?? ''));
            }
        } catch (Throwable) {}
        // ISBN miss — fall through to title search rather than giving up
        $trace[] = ['step' => 'openlibrary_isbn', 'status' => 'no_result', 'detail' => 'Open Library had no result for ISBN; trying title search.'];
    }

    $type = strtolower(trim((string) ($result['type'] ?? '')));
    if (!in_array($type, ['book', 'other', ''], true)) {
        return $result;
    }

    $title = trim((string) ($result['title'] ?? ''));
    if ($title !== '') {
        $author = trim((string) (($result['authors'][0] ?? '')));
        try {
            $olResult = search_openlibrary_by_title($title, $author);
            if (is_array($olResult)) {
                $trace[] = ['step' => 'openlibrary_search', 'status' => 'success', 'detail' => 'Open Library found a match via title search.'];
                return merge_source_candidates($olResult, $result, (string) ($result['url'] ?? ''));
            }
        } catch (Throwable) {}
        $trace[] = ['step' => 'openlibrary_search', 'status' => 'no_result', 'detail' => 'Open Library title search returned no result.'];
    }

    return $result;
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
