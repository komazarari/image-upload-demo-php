#!/bin/bash
#
# GCS Image Upload Demo - Upload Request Client
#
# This script requests a signed URL from the server and uploads an image.
#
# Usage:
#   ./clients/upload-request.sh <userId> <imagePath> [serverUrl]
#
# Examples:
#   ./clients/upload-request.sh user-123 vacation.jpg
#   ./clients/upload-request.sh user-123 photos/beach.png http://localhost:8000
#

set -e

# Configuration
SERVER_URL="${3:-http://localhost:8000}"
API_ENDPOINT="/images/upload-request"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Validate arguments
if [ $# -lt 2 ]; then
    echo "Usage: $0 <userId> <imagePath> [serverUrl]"
    echo ""
    echo "Arguments:"
    echo "  userId     - Unique user identifier"
    echo "  imagePath  - Path to image file to upload"
    echo "  serverUrl  - Application server URL (default: http://localhost:8000)"
    echo ""
    echo "Examples:"
    echo "  $0 user-123 vacation.jpg"
    echo "  $0 user-456 photos/beach.png http://example.com"
    exit 1
fi

USER_ID="$1"
IMAGE_PATH="$2"

# Validate image file exists
if [ ! -f "$IMAGE_PATH" ]; then
    echo -e "${RED}✗ Error: Image file not found: $IMAGE_PATH${NC}"
    exit 1
fi

# Validate image file is readable
if [ ! -r "$IMAGE_PATH" ]; then
    echo -e "${RED}✗ Error: Image file is not readable: $IMAGE_PATH${NC}"
    exit 1
fi

# Get file info
FILE_NAME=$(basename "$IMAGE_PATH")
FILE_SIZE=$(stat -f%z "$IMAGE_PATH" 2>/dev/null || stat -c%s "$IMAGE_PATH" 2>/dev/null)
MIME_TYPE=$(file -b --mime-type "$IMAGE_PATH" 2>/dev/null || echo "application/octet-stream")

echo -e "${YELLOW}Starting image upload...${NC}"
echo "Server URL: $SERVER_URL"
echo "User ID: $USER_ID"
echo "Image: $FILE_NAME"
echo "Size: $FILE_SIZE bytes"
echo "MIME Type: $MIME_TYPE"
echo ""

# Step 1: Request signed URL from server
echo -e "${YELLOW}Step 1: Requesting signed URL...${NC}"

UPLOAD_RESPONSE=$(curl -s -X POST "${SERVER_URL}${API_ENDPOINT}" \
  -H "Content-Type: application/json" \
  -d "{
    \"userId\": \"$USER_ID\",
    \"originalFilename\": \"$FILE_NAME\",
    \"imageMetadata\": {
      \"estimatedSize\": $FILE_SIZE,
      \"mimeType\": \"$MIME_TYPE\"
    }
  }")

# Check response status
STATUS=$(echo "$UPLOAD_RESPONSE" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)

if [ "$STATUS" != "success" ]; then
    ERROR=$(echo "$UPLOAD_RESPONSE" | grep -o '"error":"[^"]*"' | head -1 | cut -d'"' -f4)
    echo -e "${RED}✗ Error: Failed to get signed URL${NC}"
    if [ -n "$ERROR" ]; then
        echo "Details: $ERROR"
    fi
    echo "Response: $UPLOAD_RESPONSE"
    exit 1
fi

# Extract upload credentials
GUID=$(echo "$UPLOAD_RESPONSE" | grep -o '"guid":"[^"]*"' | head -1 | cut -d'"' -f4)
SIGNED_URL=$(echo "$UPLOAD_RESPONSE" | grep -o '"signedUrl":"[^"]*"' | head -1 | cut -d'"' -f4 | sed 's/\\\//\//g')
EXPIRES_AT=$(echo "$UPLOAD_RESPONSE" | grep -o '"expiresAt":"[^"]*"' | head -1 | cut -d'"' -f4)

if [ -z "$GUID" ] || [ -z "$SIGNED_URL" ]; then
    echo -e "${RED}✗ Error: Invalid response from server${NC}"
    echo "Response: $UPLOAD_RESPONSE"
    exit 1
fi

echo -e "${GREEN}✓ Got signed URL${NC}"
echo "  GUID: $GUID"
echo "  Expires: $EXPIRES_AT"
echo ""

# Step 2: Upload image using signed URL
echo -e "${YELLOW}Step 2: Uploading image to GCS...${NC}"

UPLOAD_STATUS=$(curl -s -w "%{http_code}" -o /dev/null -X PUT \
  --data-binary @"$IMAGE_PATH" \
  -H "Content-Type: $MIME_TYPE" \
  "$SIGNED_URL")

if [ "$UPLOAD_STATUS" != "200" ]; then
    echo -e "${RED}✗ Error: Upload failed with HTTP status $UPLOAD_STATUS${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Image uploaded successfully${NC}"
echo ""

# Step 3: Provide next steps
echo -e "${GREEN}✓ Upload complete!${NC}"
echo ""
echo "Next steps:"
echo "1. Wait for image processing (usually 1-5 seconds)"
echo "2. Check status with:"
echo ""
echo "   ./clients/status.sh $GUID"
echo ""
echo "Or for polling with automatic retry:"
echo ""
echo "   ./clients/status.sh $GUID --wait"
echo ""
echo "Save this GUID for your records:"
echo -e "  ${YELLOW}$GUID${NC}"
