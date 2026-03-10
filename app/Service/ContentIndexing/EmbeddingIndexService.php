<?php

declare(strict_types=1);

namespace App\Service\ContentIndexing;

use App\Service\EmbeddingService;
use App\Service\FileToolService;
use App\Service\QdrantService;
use App\Service\VectorStoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Indexes file chunks as embedding vectors in Qdrant.
 *
 * Each file is split into overlapping ~80-line chunks (15-line overlap).
 * Each chunk becomes one Qdrant point with payload:
 *   - file_path, base_dir, chunk_index, start_line, end_line, text
 *
 * Token budget strategy:
 *   Instead of batching by file count, we accumulate chunks until we
 *   approach OpenAI's 300k token/request limit, then flush.
 *   Estimate: 1 token ≈ 4 chars → 300k tokens ≈ 1.2M chars.
 *   We use 800k chars (~200k tokens) as the flush threshold to stay
 *   well clear of the limit even with tokenization variance.
 */
class EmbeddingIndexService
{
    public const COLLECTION = 'file_chunks';

    private const CHUNK_LINES    = 80;
    private const OVERLAP_LINES  = 15;
    private const MAX_FILE_SIZE  = 1_048_576; // 1 MB

    // Flush an OpenAI batch when accumulated chunk chars exceed this.
    // 800k chars ≈ 200k tokens — safe under the 300k token limit.
    private const BATCH_CHAR_BUDGET = 800_000;

    // Hard per-chunk char cap (text-embedding-3-small: 8192 token limit).
    // 8192 tokens * 3 chars/token = ~24k chars conservative cap.
    private const MAX_CHUNK_CHARS = 24_000;

    // Max inputs per single OpenAI embeddings call.
    private const OPENAI_MAX_INPUTS = 2048;

    private const EXCLUDED_DIRS = [
        'node_modules', 'vendor', 'bower_components',
        '.git', '.svn', 'dist', 'build', 'coverage',
        '.cache', '.next', '.nuxt', 'public/build',
        'storage/framework', 'storage/logs',
    ];

    // Exact filenames that must never be indexed regardless of location.
    private const EXCLUDED_FILES = [
        '.env', '.env.local', '.env.testing', '.env.dusk.local',
        '.env.example', '.env.staging', '.env.production', '.env.prod',
        'auth.json',             // Composer auth (registry tokens)
        '.npmrc', '.yarnrc',     // npm/yarn tokens
        'secrets.json', 'credentials.json',
        '.netrc', '.htpasswd',
    ];

    private const BINARY_EXTENSIONS = [
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'svg', 'bmp', 'tiff',
        'mp4', 'webm', 'mov', 'mp3', 'wav', 'ogg',
        'zip', 'tar', 'gz', 'bz2', 'xz', '7z', 'rar',
        'so', 'dylib', 'dll', 'exe', 'bin', 'o', 'a',
        'pyc', 'pyo', 'class',
        'sqlite', 'db', 'dat',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'map', 'lock',
    ];

    public function __construct(
        private readonly VectorStoreService $vectorStore,
        private readonly QdrantService $qdrant,
        private readonly EmbeddingService $embedding,
        private readonly FileToolService $fileToolService,
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public function indexDirectory(string $baseDir, ?callable $progress = null): array
    {
        $this->vectorStore->ensureCollection(self::COLLECTION);

        $files = $this->collectFiles($baseDir);
        $stats = ['total' => count($files), 'indexed' => 0, 'skipped' => 0, 'errors' => 0];

        $this->processFiles($files, $baseDir, $stats, $progress);
        $this->touchMetadata('embedding_last_build');

        return $stats;
    }

    public function syncDirectory(string $baseDir, ?callable $progress = null): array
    {
        $this->vectorStore->ensureCollection(self::COLLECTION);

        $files   = $this->collectFiles($baseDir);
        $changed = array_values(array_filter($files, fn($p) => $this->needsReembedding($p)));

        $stats = ['total' => count($changed), 'indexed' => 0, 'skipped' => 0, 'errors' => 0];

        $this->processFiles($changed, $baseDir, $stats, $progress);
        $this->touchMetadata('embedding_last_sync');

        return $stats;
    }

    public function indexFile(string $filePath, string $baseDir = ''): bool
    {
        if (!$this->isEligible($filePath)) return false;

        $hash = $this->fileHash($filePath);
        if ($this->isEmbeddingUpToDate($filePath, $hash)) return false;

        $chunks = $this->chunksForFile($filePath);
        if (empty($chunks)) return false;

        $vectors = $this->embedding->embedBatch(array_column($chunks, 'text'));
        $this->qdrant->upsert(self::COLLECTION, $this->buildPoints($filePath, $baseDir, $chunks, $vectors));
        $this->recordHash($filePath, $hash);

        return true;
    }

    // ------------------------------------------------------------------
    // Core: char-budget-based batching
    // ------------------------------------------------------------------

    /**
     * Stream through files, accumulating chunks into a budget-bounded
     * buffer, flushing to OpenAI whenever the char budget is reached.
     */
    private function processFiles(array $files, string $baseDir, array &$stats, ?callable $progress): void
    {
        // Buffer: array of ['filePath', 'hash', 'localIdx', 'chunk']
        $buffer     = [];
        $bufferChars = 0;

        $flush = function () use (&$buffer, &$bufferChars, $baseDir, &$stats, $progress) {
            if (empty($buffer)) return;
            $this->flushBuffer($buffer, $baseDir, $stats, $progress);
            $buffer      = [];
            $bufferChars = 0;
        };

        foreach ($files as $filePath) {
            $stats['current_file'] = $filePath;

            if (!$this->isEligible($filePath)) {
                $stats['skipped']++;
                if ($progress) $progress($stats);
                continue;
            }

            $hash = $this->fileHash($filePath);

            if ($this->isEmbeddingUpToDate($filePath, $hash)) {
                $stats['skipped']++;
                if ($progress) $progress($stats);
                continue;
            }

            $chunks = $this->chunksForFile($filePath);

            if (empty($chunks)) {
                $stats['skipped']++;
                if ($progress) $progress($stats);
                continue;
            }

            foreach ($chunks as $localIdx => $chunk) {
                $chunkChars = strlen($chunk['text']);

                // If adding this chunk would exceed budget, flush first
                if ($bufferChars + $chunkChars > self::BATCH_CHAR_BUDGET && !empty($buffer)) {
                    $flush();
                }

                $buffer[]    = ['filePath' => $filePath, 'hash' => $hash, 'localIdx' => $localIdx, 'chunk' => $chunk];
                $bufferChars += $chunkChars;
            }
        }

        // Final flush
        $flush();
    }

    /**
     * Embed and upsert one buffer's worth of chunks.
     */
    private function flushBuffer(array $buffer, string $baseDir, array &$stats, ?callable $progress): void
    {
        $texts = array_map(fn($b) => $b['chunk']['text'], $buffer);

        // Embed in slices of OPENAI_MAX_INPUTS
        $allVectors = [];
        foreach (array_chunk($texts, self::OPENAI_MAX_INPUTS) as $slice) {
            try {
                $vecs       = $this->embedding->embedBatch($slice);
                $allVectors = array_merge($allVectors, $vecs);
            } catch (\Throwable $e) {
                Log::error('EmbeddingIndex: OpenAI batch failed', [
                    'chars'  => array_sum(array_map('strlen', $texts)),
                    'chunks' => count($texts),
                    'error'  => $e->getMessage(),
                ]);
                // Count distinct files in this buffer as errors
                $stats['errors'] += count(array_unique(array_column($buffer, 'filePath')));
                return;
            }
        }

        // Group points by file
        $pointsByFile = [];
        $hashByFile   = [];
        foreach ($buffer as $i => $b) {
            $fp = $b['filePath'];
            $pointsByFile[$fp][] = [
                'id'      => $this->pointId($fp, $b['chunk']['chunk_index']),
                'vector'  => $allVectors[$i],
                'payload' => [
                    'file_path'   => $fp,
                    'base_dir'    => rtrim($baseDir, '/'),
                    'chunk_index' => $b['chunk']['chunk_index'],
                    'start_line'  => $b['chunk']['start_line'],
                    'end_line'    => $b['chunk']['end_line'],
                    'text'        => $b['chunk']['text'],
                ],
            ];
            $hashByFile[$fp] = $b['hash'];
        }

        foreach ($pointsByFile as $fp => $points) {
            try {
                $this->qdrant->upsert(self::COLLECTION, $points);
                $this->recordHash($fp, $hashByFile[$fp]);
                $stats['indexed']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('EmbeddingIndex: upsert failed', ['file' => $fp, 'error' => $e->getMessage()]);
            }

            $stats['current_file'] = $fp;
            if ($progress) $progress($stats);
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function collectFiles(string $baseDir): array
    {
        $result = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $path = $file->getPathname();
            if ($this->shouldExclude($path)) continue;
            if ($this->hasBinaryExtension($path)) continue;
            $result[] = $path;
        }

        return $result;
    }

    private function shouldExclude(string $path): bool
    {
        if (in_array(basename($path), self::EXCLUDED_FILES, true)) {
            return true;
        }
        foreach (self::EXCLUDED_DIRS as $dir) {
            if (str_contains($path, '/' . $dir . '/') || str_ends_with($path, '/' . $dir)) {
                return true;
            }
        }
        return false;
    }

    private function hasBinaryExtension(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::BINARY_EXTENSIONS, true);
    }

    private function isEligible(string $filePath): bool
    {
        return file_exists($filePath)
            && !$this->fileToolService->isBinaryFile($filePath)
            && filesize($filePath) <= self::MAX_FILE_SIZE;
    }

    private function chunksForFile(string $filePath): array
    {
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return [];
        return $this->chunkLines($lines);
    }

    private function chunkLines(array $lines): array
    {
        $chunks = [];
        $total  = count($lines);
        $step   = self::CHUNK_LINES - self::OVERLAP_LINES;
        $idx    = 0;

        for ($start = 0; $start < $total; $start += $step) {
            $end  = min($start + self::CHUNK_LINES - 1, $total - 1);
            $text = implode("\n", array_slice($lines, $start, $end - $start + 1));

            if (trim($text) !== '') {
                if (strlen($text) > self::MAX_CHUNK_CHARS) {
                    $text = substr($text, 0, self::MAX_CHUNK_CHARS);
                }
                $chunks[] = [
                    'chunk_index' => $idx++,
                    'start_line'  => $start + 1,
                    'end_line'    => $end + 1,
                    'text'        => $text,
                ];
            }

            if ($end >= $total - 1) break;
        }

        return $chunks;
    }

    private function buildPoints(string $filePath, string $baseDir, array $chunks, array $vectors): array
    {
        $points = [];
        foreach ($chunks as $i => $chunk) {
            $points[] = [
                'id'      => $this->pointId($filePath, $chunk['chunk_index']),
                'vector'  => $vectors[$i],
                'payload' => [
                    'file_path'   => $filePath,
                    'base_dir'    => rtrim($baseDir, '/'),
                    'chunk_index' => $chunk['chunk_index'],
                    'start_line'  => $chunk['start_line'],
                    'end_line'    => $chunk['end_line'],
                    'text'        => $chunk['text'],
                ],
            ];
        }
        return $points;
    }

    private function pointId(string $filePath, int $chunkIndex): int
    {
        return (abs(crc32($filePath)) * 1000) + $chunkIndex;
    }

    private function fileHash(string $filePath): string
    {
        return hash('xxh3', $filePath . ':' . filesize($filePath) . ':' . filemtime($filePath));
    }

    private function needsReembedding(string $filePath): bool
    {
        return $this->isEligible($filePath)
            && !$this->isEmbeddingUpToDate($filePath, $this->fileHash($filePath));
    }

    private function isEmbeddingUpToDate(string $filePath, string $hash): bool
    {
        return DB::table('indexed_files')
            ->where('file_path', $filePath)
            ->value('embedding_hash') === $hash;
    }

    private function recordHash(string $filePath, string $hash): void
    {
        DB::table('indexed_files')->updateOrInsert(
            ['file_path' => $filePath],
            [
                'file_hash'      => $hash,
                'embedding_hash' => $hash,
                'file_size'      => filesize($filePath),
                'file_mtime'     => filemtime($filePath),
                'indexed_at'     => now(),
            ]
        );
    }

    private function touchMetadata(string $key): void
    {
        DB::table('index_metadata')->updateOrInsert(
            ['key' => $key],
            ['value' => now()->toDateTimeString(), 'updated_at' => now()]
        );
    }
}
