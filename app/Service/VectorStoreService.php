<?php

declare(strict_types=1);

namespace App\Service;

use Qdrant\Response;

class VectorStoreService
{
    public function __construct(
        private readonly QdrantService $qdrant,
        private readonly EmbeddingService $embedding,
    ) {}

    /**
     * Ensure a collection exists for text-embedding-3-small (1536 dims).
     * No-ops if it already exists.
     */
    public function ensureCollection(string $collection): void
    {
        if (!$this->qdrant->collectionExists($collection)) {
            $this->qdrant->createCollection($collection, EmbeddingService::DIMENSIONS);
        }
    }

    /**
     * Embed a text string and insert it as a single point.
     *
     * @param  array  $payload  Any metadata to store alongside the vector.
     *                          The original text is always stored as 'text'.
     */
    public function insertText(
        string $collection,
        int|string $id,
        string $text,
        array $payload = [],
    ): Response {
        $vector = $this->embedding->embed($text);

        return $this->qdrant->upsert($collection, [[
            'id'      => $id,
            'vector'  => $vector,
            'payload' => array_merge(['text' => $text], $payload),
        ]]);
    }

    /**
     * Embed multiple texts and insert them in a single upsert call.
     *
     * Each item: ['id' => int|string, 'text' => string, 'payload' => array]
     */
    public function insertBatch(string $collection, array $items): Response
    {
        $texts = array_column($items, 'text');
        $vectors = $this->embedding->embedBatch($texts);

        $points = [];
        foreach ($items as $i => $item) {
            $points[] = [
                'id'      => $item['id'],
                'vector'  => $vectors[$i],
                'payload' => array_merge(['text' => $item['text']], $item['payload'] ?? []),
            ];
        }

        return $this->qdrant->upsert($collection, $points);
    }

    /**
     * Embed a query string and search for similar points.
     */
    public function search(
        string $collection,
        string $query,
        int $limit = 10,
        ?array $filter = null,
    ): Response {
        $vector = $this->embedding->embed($query);

        return $this->qdrant->search($collection, $vector, $limit, $filter);
    }
}
