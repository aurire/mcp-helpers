<?php

return [
    'host' => env('QDRANT_HOST', 'localhost'),
    'port' => (int) env('QDRANT_PORT', 6333),
    'api_key' => env('QDRANT_API_KEY'),
    'timeout' => (int) env('QDRANT_TIMEOUT', 10),
];
