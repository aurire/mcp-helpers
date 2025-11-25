<?php

return [
    'allowed_paths' => env('ALLOWED_PATHS_FOR_MCP_TOOLS', ''),
    
    'file_versioning' => [
        'enabled' => env('MCP_FILE_VERSIONING_ENABLED', true),
        'auto_cleanup' => env('MCP_FILE_VERSIONING_AUTO_CLEANUP', true),
        'keep_versions_per_file' => env('MCP_FILE_VERSIONING_KEEP_COUNT', 50),
        'max_file_size' => env('MCP_FILE_VERSIONING_MAX_SIZE', 5 * 1024 * 1024), // 5MB
        'cleanup_frequency' => env('MCP_FILE_VERSIONING_CLEANUP_FREQ', 100), // Every N operations
    ],
];
