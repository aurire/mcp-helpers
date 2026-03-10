<?php

namespace App\Providers;

use App\Service\ContentIndexing\EmbeddingIndexService;
use App\Service\EmbeddingService;
use App\Service\QdrantService;
use App\Service\VectorStoreService;
use OpenAI;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(QdrantService::class, function () {
            return new QdrantService(
                host: config('qdrant.host'),
                port: config('qdrant.port'),
                apiKey: config('qdrant.api_key'),
                timeout: config('qdrant.timeout'),
            );
        });

        $this->app->singleton(EmbeddingService::class, function () {
            // Pass a custom Guzzle client with explicit connect + request timeouts
            // to prevent hung API calls from blocking the indexing pipeline.
            $guzzle = new \GuzzleHttp\Client([
                'connect_timeout' => 10,   // TCP handshake
                'timeout'         => 60,   // full request (large batches can take ~20-30s)
            ]);

            return new EmbeddingService(
                client: OpenAI::factory()
                    ->withApiKey(config('services.openai.api_key'))
                    ->withHttpClient($guzzle)
                    ->make(),
                model: config('services.openai.model_embedding'),
            );
        });

        $this->app->singleton(VectorStoreService::class, function ($app) {
            return new VectorStoreService(
                qdrant: $app->make(QdrantService::class),
                embedding: $app->make(EmbeddingService::class),
            );
        });

        $this->app->singleton(EmbeddingIndexService::class, function ($app) {
            return new EmbeddingIndexService(
                vectorStore: $app->make(VectorStoreService::class),
                qdrant: $app->make(QdrantService::class),
                embedding: $app->make(EmbeddingService::class),
                fileToolService: $app->make(\App\Service\FileToolService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
