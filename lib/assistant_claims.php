<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/embeddings.php';

function assistant_handle_link_claims(string $draft): array
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $draft) ?: [])));
    $links = [];
    foreach ($lines as $claim) {
        $matches = semantic_search_sources($claim, 3);
        foreach ($matches as $match) {
            $sourceId = (int) ($match['source']['id'] ?? 0);
            $score = (float) ($match['score'] ?? 0.0);
            $stmt = db()->prepare(
                'INSERT INTO draft_claim_links (claim_text, source_id, confidence, rationale)
                 VALUES (:claim_text, :source_id, :confidence, :rationale)'
            );
            $stmt->execute([
                'claim_text' => $claim,
                'source_id' => $sourceId > 0 ? $sourceId : null,
                'confidence' => $score,
                'rationale' => 'Semantic match',
            ]);
        }
        $links[] = ['claim' => $claim, 'matches' => $matches];
    }

    return ['links' => $links];
}
