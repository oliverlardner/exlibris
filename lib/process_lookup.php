<?php
declare(strict_types=1);

require_once __DIR__ . '/crossref.php';
require_once __DIR__ . '/openalex.php';
require_once __DIR__ . '/semantic_scholar.php';

/**
 * Cascades through DOI-resolving providers until one returns usable metadata.
 *
 * Order: CrossRef -> OpenAlex -> Semantic Scholar.
 */
function lookup_doi_with_fallbacks(string $doi, array &$trace): ?array
{
    $doi = trim($doi);
    if ($doi === '') {
        return null;
    }

    try {
        $crossref = lookup_doi_crossref($doi);
        $hasTitle = is_array($crossref) && trim((string) ($crossref['title'] ?? '')) !== '';
        $trace[] = [
            'step' => 'crossref',
            'status' => $hasTitle ? 'success' : 'no_result',
            'detail' => $hasTitle ? 'CrossRef returned metadata.' : 'CrossRef returned no result.',
        ];
        if ($hasTitle) {
            return $crossref;
        }
    } catch (Throwable) {
        $trace[] = ['step' => 'crossref', 'status' => 'error', 'detail' => 'CrossRef request failed.'];
    }

    try {
        $openalex = openalex_by_doi($doi);
        $hasTitle = is_array($openalex) && trim((string) ($openalex['title'] ?? '')) !== '';
        $trace[] = [
            'step' => 'openalex',
            'status' => $hasTitle ? 'success' : 'no_result',
            'detail' => $hasTitle ? 'OpenAlex returned metadata for the DOI.' : 'OpenAlex had no result for the DOI.',
        ];
        if ($hasTitle) {
            return $openalex;
        }
    } catch (Throwable) {
        $trace[] = ['step' => 'openalex', 'status' => 'error', 'detail' => 'OpenAlex request failed.'];
    }

    try {
        $ss = semantic_scholar_by_doi($doi);
        $hasTitle = is_array($ss) && trim((string) ($ss['title'] ?? '')) !== '';
        $trace[] = [
            'step' => 'semantic_scholar',
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
