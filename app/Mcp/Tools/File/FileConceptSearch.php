<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\ContentIndexing\EmbeddingIndexService;
use App\Service\FileToolService;
use App\Service\VectorStoreService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

/**
 * MCP tool: file_concept_search
 *
 * Semantic / concept-level search over indexed file chunks using embeddings.
 * Complements file_search_content (token/keyword) for higher-level queries:
 *
 *   file_search_content  → "find me files that call processRefund()"
 *   file_concept_search  → "find me files related to refund retry logic"
 *
 * The directory must be indexed first:
 *   php artisan index:embed-build <path>
 *
 * Parameters:
 *   query      – Natural language description of what you are looking for.
 *   baseDir    – Only return results under this directory (optional).
 *   limit      – Max results to return (default 10).
 *   minScore   – Minimum cosine similarity score 0–1 (default 0.3).
 */
class FileConceptSearch
{
    public function __construct(
        private readonly VectorStoreService $vectorStore,
        private readonly FileToolService $fileToolService,
    ) {}

    #[McpTool(name: 'file_concept_search')]
    public function conceptSearch(
        string $query,
        ?string $baseDir = null,
        int $limit = 10,
        float $minScore = 0.30,
    ): array {
        if (trim($query) === '') {
            throw new RuntimeException('query must not be empty');
        }

        if ($baseDir !== null) {
            $allowedPaths = $this->fileToolService->getAllowedPaths();
            if (!$this->fileToolService->isPathAllowed($allowedPaths, $baseDir)) {
                throw new RuntimeException('Access denied: baseDir is not within allowed directories');
            }
        }

        // Build optional Qdrant filter for base_dir
        $filter = $baseDir !== null
            ? ['base_dir' => rtrim($baseDir, '/')]
            : null;

        // Fetch more candidates than limit so we can de-duplicate by file
        $rawLimit = $limit * 4;

        $response = $this->vectorStore->search(
            collection: EmbeddingIndexService::COLLECTION,
            query: $query,
            limit: $rawLimit,
            filter: $filter,
        );

        $hits = $response['result'] ?? [];

        // De-duplicate: keep the best-scoring chunk per file
        $byFile = [];
        foreach ($hits as $hit) {
            $score   = (float) ($hit['score'] ?? 0);
            $payload = $hit['payload'] ?? [];
            $path    = $payload['file_path'] ?? '';

            if ($score < $minScore || $path === '') continue;

            if (!isset($byFile[$path]) || $score > $byFile[$path]['score']) {
                $byFile[$path] = [
                    'score'       => round($score, 4),
                    'file_path'   => $path,
                    'start_line'  => $payload['start_line']  ?? null,
                    'end_line'    => $payload['end_line']    ?? null,
                    'snippet'     => $this->snippet($payload['text'] ?? '', 300),
                    'chunk_index' => $payload['chunk_index'] ?? 0,
                ];
            }
        }

        // Sort descending by score and cap at limit
        usort($byFile, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_values(array_slice($byFile, 0, $limit));

        $topPaths = array_unique(array_column($results, 'file_path'));

        return [
            'query'        => $query,
            'result_count' => count($results),
            'results'      => $results,
            'bulk_hint'    => count($results) >= 2
                ? '💡 TIP: Use bulk_execute(toolName: "file_read", ...) to read the top files at once.'
                : null,
            'note'         => 'Scores are cosine similarity (0–1). Index with: php artisan index:embed-build <dir>',
        ];
    }

    /**
     * Truncate a chunk text to the first ~N chars at a word boundary.
     */
    private function snippet(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) return $text;

        $cut = mb_substr($text, 0, $maxChars);
        $last = mb_strrpos($cut, "\n");
        if ($last !== false && $last > $maxChars * 0.5) {
            return mb_substr($cut, 0, $last) . "\n…";
        }
        return $cut . '…';
    }
}
