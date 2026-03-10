<?php

declare(strict_types=1);

namespace App\Service;

use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\PointStruct;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\ScrollRequest;
use Qdrant\Models\Request\SearchRequest;
use Qdrant\Models\Request\VectorParams;
use Qdrant\Models\VectorStruct;
use Qdrant\Qdrant;
use Qdrant\Response;

class QdrantService
{
    private Qdrant $client;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {
        $config = new Config("http://{$this->host}:{$this->port}");

        if ($this->apiKey) {
            $config->setApiKey($this->apiKey);
        }

        $this->client = new Qdrant((new Builder())->build($config));
    }

    /**
     * Create a collection with a single named vector.
     */
    public function createCollection(
        string $collection,
        int $vectorSize,
        string $distance = VectorParams::DISTANCE_COSINE,
        string $vectorName = 'content',
    ): Response {
        $request = new CreateCollection();
        $request->addVector(new VectorParams($vectorSize, $distance), $vectorName);

        return $this->client->collections($collection)->create($request);
    }

    /**
     * Delete a collection.
     */
    public function deleteCollection(string $collection): Response
    {
        return $this->client->collections($collection)->delete();
    }

    /**
     * List all collections.
     */
    public function listCollections(): Response
    {
        return $this->client->collections()->list();
    }

    /**
     * Upsert one or more points into a collection.
     *
     * Each point: ['id' => int|string, 'vector' => float[], 'payload' => array]
     */
    public function upsert(string $collection, array $points, string $vectorName = 'content'): Response
    {
        $struct = new PointsStruct();

        foreach ($points as $point) {
            $struct->addPoint(new PointStruct(
                $point['id'],
                new VectorStruct($point['vector'], $vectorName),
                $point['payload'] ?? [],
            ));
        }

        return $this->client->collections($collection)->points()->upsert($struct, ['wait' => 'true']);
    }

    /**
     * Delete points by their IDs.
     */
    public function deletePoints(string $collection, array $ids): Response
    {
        return $this->client->collections($collection)->points()->delete(
            ['points' => $ids],
            ['wait' => 'true'],
        );
    }

    /**
     * Semantic search by vector.
     */
    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        ?array $filter = null,
        bool $withPayload = true,
        string $vectorName = 'content',
    ): Response {
        $request = (new SearchRequest(new VectorStruct($vector, $vectorName)))
            ->setLimit($limit)
            ->setWithPayload($withPayload);

        if ($filter !== null) {
            $f = new Filter();
            foreach ($filter as $field => $value) {
                $f->addMust(new MatchString($field, $value));
            }
            $request->setFilter($f);
        }

        return $this->client->collections($collection)->points()->search($request);
    }

    /**
     * Scroll through all points (no vector needed).
     */
    public function scroll(
        string $collection,
        int $limit = 100,
        mixed $offset = null,
        bool $withPayload = true,
    ): Response {
        $request = new ScrollRequest();
        $request->setLimit($limit);
        $request->setWithPayload($withPayload);

        if ($offset !== null) {
            $request->setOffset($offset);
        }

        return $this->client->collections($collection)->points()->scroll($request);
    }

    /**
     * Check if a collection exists.
     */
    public function collectionExists(string $collection): bool
    {
        try {
            $result = $this->client->collections($collection)->info();
            return isset($result['result']['status']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Expose the raw client for advanced usage.
     */
    public function client(): Qdrant
    {
        return $this->client;
    }
}
