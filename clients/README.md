# GCS Image Upload Demo - Client Scripts

This directory contains bash scripts for interacting with the GCS Image Upload Demo API.

## Scripts

### upload-request.sh

Request a signed URL and upload an image to the server.

**Usage**:
```bash
./clients/upload-request.sh <userId> <imagePath> [serverUrl]
```

**Arguments**:
- `userId` (required): Unique user identifier
- `imagePath` (required): Path to image file to upload
- `serverUrl` (optional): Application server URL (default: `http://localhost:8000`)

**Examples**:

```bash
# Upload image from current directory
./clients/upload-request.sh user-123 vacation.jpg

# Upload image from subdirectory
./clients/upload-request.sh user-456 photos/beach.png

# Upload to different server
./clients/upload-request.sh user-789 image.jpg https://example.com
```

**What it does**:
1. Validates image file exists and is readable
2. Makes POST request to `/images/upload-request` endpoint
3. Receives signed URL with unique GUID
4. Uses signed URL to upload image to GCS
5. Displays GUID and instructions for next steps

**Output**:
```
Starting image upload...
Server URL: http://localhost:8000
User ID: user-123
Image: vacation.jpg
Size: 2500000 bytes
MIME Type: image/jpeg

Step 1: Requesting signed URL...
✓ Got signed URL
  GUID: 550e8400-e29b-41d4-a716-446655440000
  Expires: 2024-01-06T13:00:00Z

Step 2: Uploading image to GCS...
✓ Image uploaded successfully

✓ Upload complete!

Next steps:
1. Wait for image processing (usually 1-5 seconds)
2. Check status with:

   ./clients/status.sh 550e8400-e29b-41d4-a716-446655440000

Or for polling with automatic retry:

   ./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 --wait

Save this GUID for your records:
  550e8400-e29b-41d4-a716-446655440000
```

---

### status.sh

Check the status of an upload and retrieve the public URL when complete.

**Usage**:
```bash
./clients/status.sh <guid> [--wait] [serverUrl]
```

**Arguments**:
- `guid` (required): Upload identifier (UUID format)
- `--wait` (optional): Poll until processing completes
- `serverUrl` (optional): Application server URL (default: `http://localhost:8000`)

**Examples**:

```bash
# Check status once
./clients/status.sh 550e8400-e29b-41d4-a716-446655440000

# Wait for processing to complete
./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 --wait

# Check status on different server
./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 https://example.com

# Wait for processing on different server
./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 --wait https://example.com
```

**What it does**:
1. Validates GUID format (UUID v4)
2. Makes POST request to `/images/status` endpoint
3. Displays current status:
   - `initialized`: Upload received, waiting to be processed
   - `processing`: Image is being validated and converted
   - `completed`: Processing finished, public URL available
   - `failed`: Validation or processing failed
4. If `--wait` flag is used, polls with exponential backoff until complete

**Exit Codes**:
- `0`: Success (completed or in polling mode)
- `1`: Error (failed or invalid input)
- Other: Still processing (when not using `--wait`)

**Output Examples**:

**Status: Initialized**
```
Checking image upload status...
Server URL: http://localhost:8000
GUID: 550e8400-e29b-41d4-a716-446655440000

Status: Initialized
  Waiting for upload to be processed...
  Created: 2024-01-06T12:00:00Z

Still processing...
To wait for completion, use:
  ./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 --wait
```

**Status: Completed**
```
Checking image upload status...
Server URL: http://localhost:8000
GUID: 550e8400-e29b-41d4-a716-446655440000

✓ Status: Completed
  Processing finished successfully!
  Created: 2024-01-06T12:00:00Z
  Processed: 2024-01-06T12:01:30Z

Public URL:
  https://storage.googleapis.com/image-upload-demo-public-dev/550e8400-e29b-41d4-a716-446655440000_processed.jpg

✓ Complete!
```

**Status: Failed**
```
Checking image upload status...
Server URL: http://localhost:8000
GUID: 550e8400-e29b-41d4-a716-446655440000

✗ Status: Failed
  Image validation or processing failed
  Created: 2024-01-06T12:00:00Z
```

---

## Complete Workflow Example

### Step 1: Upload Image

```bash
./clients/upload-request.sh user-123 vacation.jpg
```

Output includes GUID: `550e8400-e29b-41d4-a716-446655440000`

### Step 2: Wait for Processing

```bash
./clients/status.sh 550e8400-e29b-41d4-a716-446655440000 --wait
```

This will poll every 1-10 seconds until processing completes.

### Step 3: Get Public URL

Once `status.sh` completes successfully, use the public URL to access the processed image:

```bash
# Open in browser
open "https://storage.googleapis.com/image-upload-demo-public-dev/550e8400-e29b-41d4-a716-446655440000_processed.jpg"

# Or download
curl -o downloaded.jpg "https://storage.googleapis.com/image-upload-demo-public-dev/550e8400-e29b-41d4-a716-446655440000_processed.jpg"
```

---

## Troubleshooting

### "Image file not found"
- Verify image path is correct
- Use absolute path if relative path doesn't work

```bash
# Absolute path example
./clients/upload-request.sh user-123 /Users/you/photos/vacation.jpg
```

### "Invalid GUID format"
- GUID must be in UUID v4 format: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`
- Copy GUID exactly from upload-request.sh output

### "Upload failed with HTTP status 403"
- Signed URL may have expired (1-hour expiration)
- Request a new signed URL with `upload-request.sh`

### "Connection refused"
- Verify server is running: `docker compose up`
- Verify server URL is correct (default: `http://localhost:8000`)

### "Still processing after many attempts"
- Image processing may take longer than expected
- Increase polling attempts or check server logs
- Check server logs: `docker compose logs php`

---

## Requirements

- `bash` 4.0+
- `curl` for HTTP requests
- `jq` for JSON parsing (optional, scripts use grep fallback)
- `file` command for MIME type detection
- `stat` command for file size
- Network access to running application

## Platform Support

- **macOS**: ✅ All features supported
- **Linux**: ✅ All features supported (uses `stat -c%s` instead of `stat -f%z`)
- **Windows**: Use Git Bash, WSL, or Windows Subsystem for Linux

---

## Security Considerations

- Signed URLs are valid for 1 hour only
- GUID is unique and acts as upload identifier
- Server validates image MIME type and dimensions
- Server strips EXIF metadata from images
- Public bucket objects are world-readable (expected behavior)

---

## Related Documentation

- **API.md**: Detailed endpoint specifications
- **ARCHITECTURE.md**: System design and data flow
- **TESTING.md**: E2E testing procedures
