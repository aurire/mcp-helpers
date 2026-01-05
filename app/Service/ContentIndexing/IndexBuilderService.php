<?php

namespace App\Service\ContentIndexing;

use App\Service\FileToolService;
use App\Service\CachedDirTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IndexBuilderService
{
    private const MAX_FILE_SIZE = 1048576; // 1MB
    private const MAX_TOKENS_PER_FILE = 10000;
    
    // Directories to exclude from indexing (dependencies, build artifacts)
    private const DEFAULT_EXCLUDED_DIRS = [
        'node_modules',
        'vendor',
        'bower_components',
        '.git',
        '.svn',
        'dist',
        'build',
        'coverage',
        '.cache',
        '.next',
        '.nuxt',
        'public/build',
        'storage/framework',
        'storage/logs',
    ];
    
    public function __construct(
        private TokenizerService $tokenizer,
        private FileToolService $fileToolService
    ) {}
    
    public function indexFile(string $filePath): bool
    {
        // Validations
        if (!file_exists($filePath)) return false;
        if ($this->fileToolService->isBinaryFile($filePath)) return false;
        if (filesize($filePath) > self::MAX_FILE_SIZE) return false;
        
        $fileHash = $this->calculateFileHash($filePath);
        
        // Check if already indexed
        if ($this->isFileUpToDate($filePath, $fileHash)) {
            return true;
        }
        
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return false;
        
        return DB::transaction(function() use ($filePath, $fileHash, $lines) {
            // Delete old tokens
            DB::table('content_index')
                ->where('file_path', $filePath)
                ->delete();
            
            $tokenCount = 0;
            $batchSize = 1000;
            $tokenBatch = [];
            
            foreach ($lines as $lineNum => $line) {
                if ($tokenCount >= self::MAX_TOKENS_PER_FILE) break;
                
                $tokens = $this->tokenizer->tokenizeLine($line, $lineNum + 1);
                
                foreach ($tokens as $token) {
                    $tokenBatch[] = [
                        'file_path' => $filePath,
                        'token' => $token['text'],
                        'original_token' => $token['original'],
                        'line_number' => $token['line'],
                        'token_position' => $token['position'],
                        'context' => $token['context'],
                        'token_type' => $token['type'],
                        'created_at' => now(),
                    ];
                    
                    $tokenCount++;
                    
                    // Batch insert for performance
                    if (count($tokenBatch) >= $batchSize) {
                        DB::table('content_index')->insert($tokenBatch);
                        $tokenBatch = [];
                    }
                }
            }
            
            // Insert remaining
            if (!empty($tokenBatch)) {
                DB::table('content_index')->insert($tokenBatch);
            }
            
            // Update file metadata
            DB::table('indexed_files')->updateOrInsert(
                ['file_path' => $filePath],
                [
                    'file_hash' => $fileHash,
                    'file_size' => filesize($filePath),
                    'file_mtime' => filemtime($filePath),
                    'token_count' => $tokenCount,
                    'indexed_at' => now(),
                ]
            );
            
            return true;
        });
    }
    
    
    /**
     * Check if a file path should be excluded from indexing
     */
    private function shouldExclude(string $filePath, array $excludedDirs = []): bool
    {
        $excludeDirs = empty($excludedDirs) ? self::DEFAULT_EXCLUDED_DIRS : $excludedDirs;
        
        foreach ($excludeDirs as $excludeDir) {
            // Check if path contains the excluded directory
            if (str_contains($filePath, '/' . $excludeDir . '/') || 
                str_ends_with($filePath, '/' . $excludeDir)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function indexDirectory(string $baseDir, ?callable $progressCallback = null, array $excludedDirs = []): array
    {
        $cachedTree = new CachedDirTree($baseDir);
        $files = $cachedTree->all();
        
        $stats = [
            'total' => count($files),
            'indexed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
        
        foreach ($files as $file) {
            $filePath = $file['path'];
            
            // Skip excluded directories (node_modules, vendor, etc.)
            if ($this->shouldExclude($filePath, $excludedDirs)) {
                $stats['skipped']++;
                continue;
            }
            
            try {
                if ($this->indexFile($file['path'])) {
                    $stats['indexed']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Index error: ' . $file['path'], ['error' => $e->getMessage()]);
            }
            
            $stats['current_file'] = $filePath;
            
            if ($progressCallback) {
                $progressCallback($stats);
            }
        }
        
        // Update global metadata
        DB::table('index_metadata')
            ->updateOrInsert(
                ['key' => 'total_files'],
                ['value' => (string)$stats['indexed'], 'updated_at' => now()]
            );
        
        DB::table('index_metadata')
            ->updateOrInsert(
                ['key' => 'last_full_index'],
                ['value' => now()->toDateTimeString(), 'updated_at' => now()]
            );
        
        return $stats;
    }
    
    /**
     * Find files that need reindexing (new or changed files)
     */
    public function findChangedFiles(string $baseDir): array
    {
        $cachedTree = new CachedDirTree($baseDir);
        $allFiles = $cachedTree->all();
        
        $changedFiles = [];
        
        foreach ($allFiles as $file) {
            $filePath = $file['path'];
            
            // Skip excluded directories (node_modules, vendor, etc.)
            if ($this->shouldExclude($filePath)) {
                continue;
            }
            
            // Skip if doesn't exist, binary, or too large
            if (!file_exists($filePath)) continue;
            if ($this->fileToolService->isBinaryFile($filePath)) continue;
            if (filesize($filePath) > self::MAX_FILE_SIZE) continue;
            
            $currentHash = $this->calculateFileHash($filePath);
            
            // Check if file needs reindexing
            if (!$this->isFileUpToDate($filePath, $currentHash)) {
                $changedFiles[] = [
                    'path' => $filePath,
                    'hash' => $currentHash,
                ];
            }
        }
        
        return $changedFiles;
    }
    
    /**
     * Incrementally sync only changed files
     */
    public function syncChangedFiles(string $baseDir, ?callable $progressCallback = null): array
    {
        $changedFiles = $this->findChangedFiles($baseDir);
        
        $stats = [
            'total' => count($changedFiles),
            'indexed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
        
        foreach ($changedFiles as $file) {
            try {
                if ($this->indexFile($file['path'])) {
                    $stats['indexed']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Index sync error: ' . $file['path'], ['error' => $e->getMessage()]);
            }
            
            $stats['current_file'] = $file['path'];
            
            if ($progressCallback) {
                $progressCallback($stats);
            }
        }
        
        // Update last sync time
        DB::table('index_metadata')
            ->updateOrInsert(
                ['key' => 'last_sync'],
                ['value' => now()->toDateTimeString(), 'updated_at' => now()]
            );
        
        return $stats;
    }
    
    /**
     * Check if a specific file needs reindexing
     */
    public function needsReindexing(string $filePath): bool
    {
        if (!file_exists($filePath)) return false;
        if ($this->fileToolService->isBinaryFile($filePath)) return false;
        if (filesize($filePath) > self::MAX_FILE_SIZE) return false;
        
        $currentHash = $this->calculateFileHash($filePath);
        return !$this->isFileUpToDate($filePath, $currentHash);
    }
    
    private function calculateFileHash(string $filePath): string
    {
        $size = filesize($filePath);
        $mtime = filemtime($filePath);
        return hash('xxh3', $filePath . ':' . $size . ':' . $mtime);
    }
    
    private function isFileUpToDate(string $filePath, string $currentHash): bool
    {
        $indexed = DB::table('indexed_files')
            ->where('file_path', $filePath)
            ->first();
        
        return $indexed && $indexed->file_hash === $currentHash;
    }
}
