# GCS Image Upload Demo - API Reference

## Overview

The GCS Image Upload Demo provides three REST API endpoints for managing image uploads. All requests and responses use JSON format. The API follows RESTful principles and uses conventional HTTP status codes.

## Base URL

```
Development:  http://localhost:8000
Production:   https://yourdomain.com
```

## Common Response Format

All endpoints return JSON responses with the following structure:

```json
{
  "status": "success|error",
  "data": { /* endpoint-specific data */ },
  "error": "error message (only if status=error)"
}
```

---

## Endpoint 1: Upload Request (User Story 1)

### POST /images/upload-request

Request a signed URL to upload an image. The server generates a unique identifier (guid) and returns a signed URL valid for 1 hour.

#### Request

**Method**: `POST`

**Content-Type**: `application/json`

**Body Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `userId` | string | Yes | Unique identifier for the user uploading the image |
| `originalFilename` | string | No | Original filename (for reference, not used for storage) |
| `imageMetadata` | object | No | Metadata about the image being uploaded |
| `imageMetadata.width` | integer | No | Expected image width (pixels) |
| `imageMetadata.height` | integer | No | Expected image height (pixels) |
| `imageMetadata.estimatedSize` | integer | No | Estimated file size (bytes) |

**Example Request**:

```bash
curl -X POST http://localhost:8000/images/upload-request \
  -H "Content-Type: application/json" \
  -d '{
    "userId": "user-123",
    "originalFilename": "vacation-photo.jpg",
    "imageMetadata": {
      "estimatedSize": 2500000
    }
  }'
```

#### Response

**Status Code**: `201 Created`

**Content-Type**: `application/json`

**Body Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | `success` |
| `data.guid` | string | Unique identifier for this upload (UUID v4) |
| `data.signedUrl` | string | Pre-signed URL for uploading the image (PUT request) |
| `data.expiresAt` | string | ISO 8601 timestamp when signed URL expires |
| `data.signedUrlExpirySeconds` | integer | Expiration time in seconds (3600) |

**Example Response (Success)**:

```json
{
  "status": "success",
  "data": {
    "guid": "550e8400-e29b-41d4-a716-446655440000",
    "signedUrl": "https://storage.googleapis.com/image-upload-demo-uploads-dev/550e8400-e29b-41d4-a716-446655440000.jpg?X-Goog-Algorithm=GOOG4-RSA-SHA256&...",
    "expiresAt": "2024-01-06T13:00:00Z",
    "signedUrlExpirySeconds": 3600
  }
}
```

#### Error Cases

**400 Bad Request**: Missing required parameter (`userId`)

```json
{
  "status": "error",
  "error": "userId is required"
}
```

**500 Internal Server Error**: Server failed to generate signed URL

```json
{
  "status": "error",
  "error": "Failed to generate signed URL"
}
```

#### Usage Example (Bash Script)

```bash
#!/bin/bash

# Step 1: Request signed URL
response=$(curl -s -X POST http://localhost:8000/images/upload-request \
  -H "Content-Type: application/json" \
  -d "{\"userId\": \"user-123\"}")

# Step 2: Extract guid and signedUrl
guid=$(echo $response | jq -r '.data.guid')
signedUrl=$(echo $response | jq -r '.data.signedUrl')

echo "Upload guid: $guid"
echo "Signed URL: $signedUrl"

# Step 3: Upload image using signed URL
curl -X PUT \
  --data-binary @vacation-photo.jpg \
  -H "Content-Type: image/jpeg" \
  "$signedUrl"

echo "Upload complete. Check status with:"
echo "curl -X POST http://localhost:8000/images/status -d '{\"guid\": \"$guid\"}'"
```

---

## Endpoint 2: Image Event (User Story 2)

### POST /image-event

Receive notifications from Google Cloud Pub/Sub when images are uploaded. The server validates, converts, and processes the image.

**Note**: This endpoint is called by Google Cloud Pub/Sub automatically. It's not meant to be called directly by clients.

#### Request

**Method**: `POST`

**Content-Type**: `application/json`

**Request Format** (Pub/Sub message format):

```json
{
  "message": {
    "data": "base64-encoded JSON",
    "messageId": "1234567890",
    "publishTime": "2024-01-06T12:00:00Z"
  }
}
```

**Base64-Decoded Data** (object metadata):

```json
{
  "bucket": "image-upload-demo-uploads-dev",
  "name": "550e8400-e29b-41d4-a716-446655440000.jpg",
  "contentType": "image/jpeg",
  "size": "2500000"
}
```

**Example Pub/Sub Payload**:

```bash
curl -X POST http://localhost:8000/image-event \
  -H "Content-Type: application/json" \
  -d '{
    "message": {
      "data": "eyJidWNrZXQiOiAiaW1hZ2UtdXBsb2FkLWRlbW8tdXBsb2Fkcy1kZXYiLCAibmFtZSI6ICI1NTBlODQwMC1lMjliLTQxZDQtYTcxNi00NDY2NTU0NDAwMDAuanBnIn0=",
      "messageId": "1234567890",
      "publishTime": "2024-01-06T12:00:00Z"
    }
  }'
```

#### Response

**Status Code**: `200 OK`

**Content-Type**: `application/json`

**Response**:

```json
{
  "status": "acknowledged"
}
```

**Note**: Always returns `200 OK` to Pub/Sub to prevent message redelivery. Processing errors are logged internally.

#### Processing Workflow

1. **Extract Metadata**: Parse base64 message to get bucket and object name
2. **Load UploadRecord**: Retrieve record by guid (extracted from object name)
3. **Mark Processing**: Set status to `processing`
4. **Validate Image**:
   - Check MIME type (whitelist: jpeg, png, webp, gif)
   - Check dimensions (max 4000×3000 px)
   - Check file size (max 5 MB)
   - If invalid → status = `failed`, return
5. **Convert Image**:
   - Download from upload bucket
   - Strip EXIF metadata using Imagick
   - Re-encode to JPEG format
   - Upload converted file to public bucket
6. **Update UploadRecord**: Set status = `completed`, publicUrl, metadata

#### Error Handling

- **Invalid MIME type**: Mark as `failed`, log reason
- **Oversized image**: Mark as `failed`, log reason
- **Conversion failure**: Mark as `failed`, log reason
- **GCS errors**: Logged; Pub/Sub will retry message

---

## Endpoint 3: Status (User Story 3)

### POST /images/status

Retrieve the current status of an upload and the public URL (if processing is complete).

#### Request

**Method**: `POST`

**Content-Type**: `application/json`

**Body Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `guid` | string | Yes | Upload identifier returned by `/images/upload-request` |

**Example Request**:

```bash
curl -X POST http://localhost:8000/images/status \
  -H "Content-Type: application/json" \
  -d '{"guid": "550e8400-e29b-41d4-a716-446655440000"}'
```

#### Response

**Status Code**: `200 OK` or `404 Not Found`

**Content-Type**: `application/json`

**Body Parameters** (on success):

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | `success` |
| `data.guid` | string | Upload identifier |
| `data.status` | string | Current status: `initialized`, `processing`, `completed`, or `failed` |
| `data.publicUrl` | string | (Optional) URL to access processed image (only if status = `completed`) |
| `data.createdAt` | string | ISO 8601 timestamp when upload was created |
| `data.processedAt` | string | (Optional) ISO 8601 timestamp when processing completed |

**Example Responses**:

**1. Initialized Status**:
```json
{
  "status": "success",
  "data": {
    "guid": "550e8400-e29b-41d4-a716-446655440000",
    "status": "initialized",
    "createdAt": "2024-01-06T12:00:00Z"
  }
}
```

**2. Processing Status**:
```json
{
  "status": "success",
  "data": {
    "guid": "550e8400-e29b-41d4-a716-446655440000",
    "status": "processing",
    "createdAt": "2024-01-06T12:00:00Z"
  }
}
```

**3. Completed Status** (includes publicUrl):
```json
{
  "status": "success",
  "data": {
    "guid": "550e8400-e29b-41d4-a716-446655440000",
    "status": "completed",
    "publicUrl": "https://storage.googleapis.com/image-upload-demo-public-dev/550e8400-e29b-41d4-a716-446655440000_processed.jpg",
    "createdAt": "2024-01-06T12:00:00Z",
    "processedAt": "2024-01-06T12:01:30Z"
  }
}
```

**4. Failed Status** (no publicUrl or error details):
```json
{
  "status": "success",
  "data": {
    "guid": "550e8400-e29b-41d4-a716-446655440000",
    "status": "failed",
    "createdAt": "2024-01-06T12:00:00Z"
  }
}
```

#### Error Cases

**404 Not Found**: GUID does not exist

```json
{
  "status": "error",
  "error": "Upload record not found"
}
```

**400 Bad Request**: Missing guid parameter

```json
{
  "status": "error",
  "error": "guid is required"
}
```

#### Usage Example (Bash Script)

```bash
#!/bin/bash

guid="550e8400-e29b-41d4-a716-446655440000"

# Poll status with exponential backoff
max_attempts=30
attempt=0
wait_time=1

while [ $attempt -lt $max_attempts ]; do
  response=$(curl -s -X POST http://localhost:8000/images/status \
    -H "Content-Type: application/json" \
    -d "{\"guid\": \"$guid\"}")
  
  current_status=$(echo $response | jq -r '.data.status')
  
  if [ "$current_status" = "completed" ]; then
    public_url=$(echo $response | jq -r '.data.publicUrl')
    echo "✓ Upload complete!"
    echo "Public URL: $public_url"
    exit 0
  elif [ "$current_status" = "failed" ]; then
    echo "✗ Upload failed"
    exit 1
  else
    echo "Current status: $current_status (attempt $((attempt + 1))/$max_attempts)"
    sleep $wait_time
    wait_time=$((wait_time * 2))  # Exponential backoff
    attempt=$((attempt + 1))
  fi
done

echo "✗ Timeout waiting for processing"
exit 1
```

---

## Status Code Reference

| Code | Meaning | Common Causes |
|------|---------|---------------|
| 200 | OK | Successful request (status, event) |
| 201 | Created | Resource created (upload-request) |
| 400 | Bad Request | Missing/invalid parameters |
| 404 | Not Found | Resource doesn't exist (guid not found) |
| 500 | Server Error | Unexpected server error |

## Authentication

Currently, the API has **no authentication** (suitable for demo). For production:

- **HTTP Signature Verification**: Validate Pub/Sub requests using `Authorization: Bearer` header
- **OAuth2**: Require user authentication with JWT tokens
- **API Keys**: Issue API keys to clients for rate limiting

See **SECURITY.md** for authentication best practices.

## Rate Limiting

Currently, no rate limiting is enforced. For production, implement:

- **Per-user limits**: 10 uploads/hour
- **Per-IP limits**: 100 requests/minute
- **Pub/Sub subscription**: 100 max outstanding messages

## Pagination

Not applicable (endpoints don't return lists).

## CORS

CORS headers not configured (demo assumes same-origin requests). For production:

```php
header('Access-Control-Allow-Origin: https://yourdomain.com');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
```

## Versioning

The API is currently at **v1** (implicit). For future versioning:

- Use path-based versioning: `/v1/images/upload-request`, `/v2/images/upload-request`
- Maintain backward compatibility for at least 1 year
- Document deprecation timeline

## Examples with Different Languages

### Python

```python
import requests
import json

# Step 1: Request signed URL
response = requests.post('http://localhost:8000/images/upload-request', 
  json={'userId': 'user-123'})
data = response.json()['data']
guid = data['guid']
signed_url = data['signedUrl']

# Step 2: Upload image
with open('photo.jpg', 'rb') as f:
  requests.put(signed_url, data=f)

# Step 3: Poll status
while True:
  response = requests.post('http://localhost:8000/images/status',
    json={'guid': guid})
  status_data = response.json()['data']
  
  if status_data['status'] == 'completed':
    print(f"Success! URL: {status_data['publicUrl']}")
    break
  elif status_data['status'] == 'failed':
    print("Upload failed")
    break
  else:
    print(f"Status: {status_data['status']}")
    time.sleep(2)
```

### JavaScript (Node.js)

```javascript
const fetch = require('node-fetch');
const fs = require('fs');

async function uploadImage(userId, filePath) {
  // Step 1: Request signed URL
  const uploadResponse = await fetch('http://localhost:8000/images/upload-request', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ userId })
  });
  
  const uploadData = await uploadResponse.json();
  const { guid, signedUrl } = uploadData.data;
  
  // Step 2: Upload image
  const fileData = fs.readFileSync(filePath);
  await fetch(signedUrl, {
    method: 'PUT',
    body: fileData,
    headers: { 'Content-Type': 'image/jpeg' }
  });
  
  // Step 3: Poll status
  let status = 'processing';
  while (status === 'processing' || status === 'initialized') {
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    const statusResponse = await fetch('http://localhost:8000/images/status', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ guid })
    });
    
    const statusData = await statusResponse.json();
    status = statusData.data.status;
    
    if (status === 'completed') {
      console.log(`Success! URL: ${statusData.data.publicUrl}`);
      return statusData.data.publicUrl;
    } else if (status === 'failed') {
      throw new Error('Upload failed');
    }
  }
}
```

---

## Related Documentation

- **ARCHITECTURE.md**: System design and component overview
- **TESTING.md**: How to test the API endpoints
- **DEVELOPMENT.md**: Development guidelines
- **SECURITY.md**: Security considerations
