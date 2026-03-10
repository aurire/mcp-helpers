<?php

declare(strict_types=1);

namespace App\Service;

use OpenAI\Client;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    // text-embedding-3-small outputs 1536-dim vectors by default
    public const DEFAULT_MODEL = 'text-embedding-3-small';
    public const DIMENSIONS = 1536;

    private const MAX_RETRIES   = 3;
    private const RETRY_DELAY_MS = 2000; // base delay in ms, doubles each retry

    public function __construct(
        private readonly Client $client,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {}

    /**
     * Embed a single string. Returns a float[].
     */
    public function embed(string $text): array
    {
        return $this->withRetry(fn() =>
            $this->client->embeddings()->create([
                'model' => $this->model,
                'input' => $text,
            ])->embeddings[0]->embedding
        );
    }

    /**
     * Embed multiple strings in one API call. Returns float[][].
     */
    public function embedBatch(array $texts): array
    {
        return $this->withRetry(fn() =>
            array_map(
                fn($e) => $e->embedding,
                $this->client->embeddings()->create([
                    'model' => $this->model,
                    'input' => $texts,
                ])->embeddings,
            )
        );
    }

    /**
     * Retry wrapper with exponential backoff.
     * Catches timeouts, connection errors, and 429/5xx from OpenAI.
     */
    private function withRetry(callable $fn): mixed
    {
        $attempt = 0;
        $delayMs = self::RETRY_DELAY_MS;

        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                Log::warning('EmbeddingService: retrying after error', [
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                    'error'   => $e->getMessage(),
                ]);

                usleep($delayMs * 1000);
                $delayMs *= 2; // exponential backoff
            }
        }
    }
}
