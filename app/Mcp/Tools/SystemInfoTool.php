<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use PhpMcp\Server\Attributes\McpTool;

class SystemInfoTool
{
    #[McpTool(name: 'ping')]
    public function ping(): string
    {
        return 'pong from MCP helper at ' . now()->toISOString();
    }

    #[McpTool(name: 'laravel_info')]
    public function laravelInfo(): array
    {
        return [
            'version' => app()->version(),
            'environment' => app()->environment(),
            'debug' => config('app.debug'),
        ];
    }
}
