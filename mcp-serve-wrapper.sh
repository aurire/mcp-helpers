#!/bin/bash

# Configuration
MAX_RESTARTS=5
RESTART_COUNT=0
PHP_BIN="/opt/homebrew/bin/php"
ARTISAN_PATH="/Users/aurimasrekstys/mine/tools/mcp-helpers/artisan"

while true; do
    echo "[$(date)] Starting MCP server (restart #$RESTART_COUNT)..." >&2
    
    # Start the MCP server with your original settings
    $PHP_BIN \
        -d memory_limit=700M \
        -d max_execution_time=0 \
        "$ARTISAN_PATH" mcp:serve
    
    EXIT_CODE=$?
    
    # If exit code is not 0, something went wrong
    if [ $EXIT_CODE -ne 0 ]; then
        echo "[$(date)] MCP server crashed with exit code $EXIT_CODE. Restarting in 2 seconds..." >&2
        sleep 2
    else
        rem echo "[$(date)] MCP server exited cleanly. Restarting in 0.5 seconds..." >&2
        rem sleep 0.5
    fi
    
    RESTART_COUNT=$((RESTART_COUNT + 1))
    
    # Safety limit: Exit after many restarts
    if [ $RESTART_COUNT -ge $MAX_RESTARTS ]; then
        echo "[$(date)] Reached maximum restart limit ($MAX_RESTARTS). Exiting." >&2
        exit 1
    fi
done
