## Features

Several simple mcp tools to improve LLM interactions about codebases:
  * file read
  * optimized quick file search for filename + search in contents
  * file insert/replace/delete lines
  * database memory: save, update, list

## Quick Start

Use local php (I use php 8.3).
I use CACHE_STORE=database  and DB_CONNECTION=sqlite - good enough for local dev.
Important- provide allowed directories, e.g.:
ALLOWED_PATHS_FOR_MCP_TOOLS=/Users/myusername/mcp-helpers;/Users/myusername/apps/myapp

## Claude

To add to claude:

path: `~/Library/Application`
file: `claude_desktop_config.json`
contents:
```
{
  "mcpServers": {
    "mcp-helpers": {
      "command": "/opt/homebrew/bin/php",
      "args": [
        "......fullpathtoproject......./mcp-helpers/artisan", "mcp:serve"
      ],
      "cwd": "......fullpathtoproject......./mcp-helpers"
    }
  }
}
```
