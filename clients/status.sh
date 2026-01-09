#!/bin/bash
#
# GCS Image Upload Demo - Status Polling Client
#
# This script polls the server for upload status and retrieves the public URL.
#
# Usage:
#   ./clients/status.sh <guid> [--wait] [serverUrl]
#
# Examples:
#   ./clients/status.sh 550e8400-e29b-41d4-a716-446655440000
#   ./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 --wait
#   ./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 http://example.com
#

set -e

# Configuration
GUID="$1"
WAIT_MODE=false
SERVER_URL="http://localhost:8000"

# Parse arguments
if [ "$2" = "--wait" ]; then
    WAIT_MODE=true
    SERVER_URL="${3:-$SERVER_URL}"
else
    SERVER_URL="${2:-$SERVER_URL}"
fi

API_ENDPOINT="/images"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Validate arguments
if [ -z "$GUID" ]; then
    echo "Usage: $0 <guid> [--wait] [serverUrl]"
    echo ""
    echo "Arguments:"
    echo "  guid      - Upload identifier (UUID format)"
    echo "  --wait    - Poll until processing completes (optional)"
    echo "  serverUrl - Application server URL (default: http://localhost:8000)"
    echo ""
    echo "Examples:"
    echo "  $0 550e8400-e29b-41d4-a716-446655440000"
    echo "  $0 550e8400-e29b-41d4-a716-446655440000 --wait"
    echo "  $0 550e8400-e29b-41d4-a716-446655440000 http://example.com"
    exit 1
fi

# Validate GUID format (basic UUID v4 check)
if ! echo "$GUID" | grep -qE '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'; then
    echo -e "${RED}✗ Error: Invalid GUID format${NC}"
    echo "Expected format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
    exit 1
fi

echo -e "${BLUE}Checking image upload status...${NC}"
echo "Server URL: $SERVER_URL"
echo "GUID: $GUID"
echo ""

# Function to get status once
get_status() {
    curl -s -X GET "${SERVER_URL}${API_ENDPOINT}/${GUID}/status"
}

# Function to display status
display_status() {
    local response="$1"
    local status=$(echo "$response" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)
    local current_status=$(echo "$response" | grep -o '"status":"[^"]*"' | tail -1 | cut -d'"' -f4)
    local public_url=$(echo "$response" | grep -o '"publicUrl":"[^"]*"' | cut -d'"' -f4 | sed 's/\\\//\//g')
    local created_at=$(echo "$response" | grep -o '"createdAt":"[^"]*"' | cut -d'"' -f4)
    local processed_at=$(echo "$response" | grep -o '"processedAt":"[^"]*"' | cut -d'"' -f4)

    if [ "$status" = "error" ]; then
        local error=$(echo "$response" | grep -o '"error":"[^"]*"' | cut -d'"' -f4)
        echo -e "${RED}✗ Error: $error${NC}"
        return 1
    fi

    case "$current_status" in
        "initialized")
            echo -e "${YELLOW}Status: Initialized${NC}"
            echo "  Waiting for upload to be processed..."
            echo "  Created: $created_at"
            return 2
            ;;
        "processing")
            echo -e "${YELLOW}Status: Processing${NC}"
            echo "  Image is being validated and converted..."
            echo "  Created: $created_at"
            return 2
            ;;
        "completed")
            echo -e "${GREEN}✓ Status: Completed${NC}"
            echo "  Processing finished successfully!"
            echo "  Created: $created_at"
            echo "  Processed: $processed_at"
            echo ""
            echo -e "${GREEN}Public URL:${NC}"
            echo -e "  ${BLUE}$public_url${NC}"
            return 0
            ;;
        "failed")
            echo -e "${RED}✗ Status: Failed${NC}"
            echo "  Image validation or processing failed"
            echo "  Created: $created_at"
            return 1
            ;;
        *)
            echo -e "${RED}✗ Error: Unknown status: $current_status${NC}"
            echo "Response: $response"
            return 1
            ;;
    esac
}

# Get initial status
RESPONSE=$(get_status)
display_status "$RESPONSE"
RESULT=$?

# If waiting mode is enabled, poll until completion
if [ "$WAIT_MODE" = true ] && [ $RESULT -eq 2 ]; then
    echo ""
    echo -e "${YELLOW}Polling for completion...${NC}"
    
    MAX_ATTEMPTS=60
    ATTEMPT=0
    WAIT_TIME=1
    
    while [ $ATTEMPT -lt $MAX_ATTEMPTS ]; do
        sleep $WAIT_TIME
        RESPONSE=$(get_status)
        
        echo -n "Attempt $((ATTEMPT + 1))/$MAX_ATTEMPTS (${WAIT_TIME}s wait): "
        display_status "$RESPONSE"
        RESULT=$?
        
        if [ $RESULT -ne 2 ]; then
            # Status is either success (0) or error (1)
            break
        fi
        
        # Exponential backoff: 1s, 2s, 4s, 8s, max 10s
        WAIT_TIME=$((WAIT_TIME * 2))
        if [ $WAIT_TIME -gt 10 ]; then
            WAIT_TIME=10
        fi
        
        ATTEMPT=$((ATTEMPT + 1))
    done
    
    if [ $ATTEMPT -ge $MAX_ATTEMPTS ]; then
        echo ""
        echo -e "${RED}✗ Timeout: Processing did not complete within ${MAX_ATTEMPTS} attempts${NC}"
        exit 1
    fi
elif [ "$WAIT_MODE" = false ] && [ $RESULT -eq 2 ]; then
    echo ""
    echo -e "${YELLOW}Still processing...${NC}"
    echo "To wait for completion, use:"
    echo "  $0 $GUID --wait"
fi

# Exit with appropriate code
if [ $RESULT -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Complete!${NC}"
    exit 0
elif [ $RESULT -eq 1 ]; then
    exit 1
else
    exit 0
fi
