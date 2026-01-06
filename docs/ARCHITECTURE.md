# GCS Image Upload Demo - System Architecture

## Overview

The GCS Image Upload Demo is a secure, serverless image upload system that demonstrates best practices for handling file uploads on Google Cloud Platform. Users upload images via signed URLs, the server validates and processes them, and clients retrieve processed images through status polling.

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         USER / CLIENT                           │
│                  (Bash script + curl)                           │
└────────┬──────────────────────────────────────┬─────────────────┘
         │                                      │
         │ 1. POST /images/upload-request       │
         │    (userId, image metadata)          │
         │                                      │
    ┌────▼──────────────────────────────────────▼────┐
    │           PHP APPLICATION                      │
    │     (Docker Container on Cloud Run/GCP)        │
    │                                                 │
    │  ┌──────────────────────────────────────┐      │
    │  │ ImageUploadController                │      │
    │  │ - Generate UUID (guid)               │      │
    │  │ - Call GcsService::generateSignedUrl │──┐   │
    │  │ - Persist UploadRecord (status: init)│  │   │
    │  └──────────────────────────────────────┘  │   │
    │                                      ◄──────┘   │
    │                                                 │
    │  ┌──────────────────────────────────────┐      │
    │  │ ImageStatusController                │      │
    │  │ - Retrieve UploadRecord by guid      │      │
    │  │ - Return status & publicUrl          │      │
    │  └──────────────────────────────────────┘      │
    │                                                 │
    │  ┌──────────────────────────────────────┐      │
    │  │ ImageEventController                 │      │
    │  │ - Receive Pub/Sub push notification  │      │
    │  │ - Validate image (MIME, size, dims)  │      │
    │  │ - Convert image (EXIF removal)       │      │
    │  │ - Update UploadRecord status         │      │
    │  └──────────────────────────────────────┘      │
    │           ▲              │                     │
    │           │ 4. Pub/Sub   │ 5. Copy            │
    │           │ Event        │ converted          │
    └───────────┼──────────────┼─────────────────────┘
                │              │
         ┌──────┴──────┐       │
         │             │       │
         │ 2. Signed   │       │
         │ URL         │       │
         │             │       │
    ┌────▼─────────────▼───┐   │
    │   Google Cloud       │   │
    │   Storage (GCS)      │   │
    │                      │   │
    │ ┌──────────────────┐ │   │
    │ │ Upload Bucket    │ │   │
    │ │ (transient)      │ │   │
    │ │ 3. curl PUT      │ │   │
    │ │ file contents    │◄┼───┘
    │ └──────────────────┘ │
    │                      │
    │ ┌──────────────────┐ │
    │ │ Public Bucket    │ │
    │ │ (publicly read)  │ │
    │ │ Processed images │ │
    │ └──────────────────┘ │
    └──────────────────────┘
```

## Data Flow

### 1. Upload Request (User Story 1)

1. **User initiates upload**: Bash client script calls `POST /images/upload-request`
   - Body: JSON with `userId` and optional metadata
   
2. **Server generates credentials**:
   - ImageUploadController receives request
   - Generates unique UUID v4 as `guid`
   - Creates UploadRecord (status: `initialized`)
   - Calls GcsService::generateSignedUrl() → 1-hour expiring signed PUT URL
   - Returns: `guid`, `signedUrl`, `expiresAt`
   
3. **User uploads image**: Client uses `curl PUT` to signed URL
   - Request: Binary file data in body
   - GCS stores file: `uploads-bucket/{guid}.jpg`
   - Lifecycle: Auto-deletes after 7 days if not processed

### 2. Image Validation & Processing (User Story 2)

1. **GCS triggers Pub/Sub**: When file uploaded to `uploads-bucket`
   - Notification sent to Pub/Sub topic: `image-upload-events`
   
2. **Server receives event**: `POST /image-event` from Pub/Sub
   - ImageEventController receives base64-encoded JSON
   - Extracts: bucket name, object name (guid)
   - Updates UploadRecord status: `initialized` → `processing`
   
3. **Validation workflow**:
   - ImageValidationService checks:
     - MIME type (whitelist: jpeg, png, webp, gif)
     - Image dimensions (max 4000×3000 px)
     - File size (max 5MB)
   - If validation fails:
     - UploadRecord status: `failed`
     - Error message logged
     - DO NOT copy to public bucket
   
4. **Image conversion** (if validation passes):
   - ImageConversionService uses Imagick to:
     - Strip EXIF metadata (privacy)
     - Re-encode to JPEG (format standardization)
     - Preserve quality (90%)
   - Converted file temporarily stored locally
   
5. **Upload to public bucket**:
   - GcsService::copyObject()
   - Source: `uploads-bucket/{guid}.jpg`
   - Dest: `public-bucket/{guid}_processed.jpg`
   - UploadRecord updated:
     - status: `completed`
     - publicUrl: `https://storage.googleapis.com/public-bucket/{guid}_processed.jpg`
     - contentType: `image/jpeg`
     - fileSize: actual size of processed image
     - imageDimensions: extracted from processed image

### 3. Status Polling (User Story 3)

1. **User polls server**: Client calls `POST /images/status`
   - Body: JSON with `guid`
   
2. **Server returns status**:
   - ImageStatusController retrieves UploadRecord
   - Returns status: `initialized` | `processing` | `completed` | `failed`
   - If `completed`: Include `publicUrl`
   - If `failed`: Do NOT expose error details (return generic "failed")
   
3. **Client receives result**:
   - If `completed`: Display publicUrl
   - If `processing`: Retry with exponential backoff
   - If `failed`: Display error message

## Component Responsibilities

### Client (Bash)

**Role**: Orchestrate upload workflow via HTTP

**Scripts**:
- `clients/upload-request.sh`: Request signed URL and upload image
- `clients/status.sh`: Poll server for upload status

**Responsibilities**:
- Collect user input (userId, image path)
- Call server endpoints
- Parse JSON responses
- Provide user feedback

**Security**:
- Validate file exists before upload
- Verify MIME type locally (informational only)
- Handle errors gracefully

---

### Application Layer (PHP/Slim)

**Role**: REST API endpoints for upload management

**Controllers**:

1. **ImageUploadController**
   - Endpoint: `POST /images/upload-request`
   - Generates UUID, creates UploadRecord
   - Delegates signed URL generation to GcsService
   - Returns 201 Created with guid and signedUrl

2. **ImageEventController**
   - Endpoint: `POST /image-event`
   - Receives Pub/Sub push notifications
   - Orchestrates validation and conversion workflow
   - Updates UploadRecord status
   - Returns 200 OK (Pub/Sub acknowledgment)

3. **ImageStatusController**
   - Endpoint: `POST /images/status`
   - Retrieves UploadRecord by guid
   - Returns current status and publicUrl (if completed)
   - Returns 200 OK or 404 Not Found

---

### Service Layer (Business Logic)

1. **ImageValidationService**
   - Validates MIME type against whitelist
   - Checks image dimensions (max/min)
   - Verifies file size within limits
   - Returns ValidationResult object with errors

2. **ImageConversionService**
   - Uses Imagick to process images
   - Strips EXIF metadata
   - Re-encodes to JPEG format
   - Returns ConversionResult with new file path

3. **StorageService**
   - Persists UploadRecord as JSON file
   - Loads UploadRecord by guid
   - Updates record status
   - Checks existence

4. **GcsService**
   - Generates signed URLs (1-hour TTL)
   - Copies objects between buckets
   - Deletes objects after processing
   - Authenticates using service account

---

### Data Layer

**Models**:

1. **UploadRecord** (JSON file)
   ```json
   {
     "guid": "550e8400-e29b-41d4-a716-446655440000",
     "userId": "user-123",
     "status": "completed",
     "originalFilename": "vacation.jpg",
     "publicUrl": "https://storage.googleapis.com/public-bucket/550e8400_processed.jpg",
     "createdAt": "2024-01-06T12:00:00Z",
     "processedAt": "2024-01-06T12:01:00Z",
     "errorMessage": null,
     "signedUrl": "https://storage.googleapis.com/...",
     "contentType": "image/jpeg",
     "fileSize": 2048576,
     "imageDimensions": {
       "width": 1920,
       "height": 1080
     }
   }
   ```

2. **ImageValidation**
   ```php
   {
     "isValid": true,
     "mimeType": "image/jpeg",
     "width": 1920,
     "height": 1080,
     "fileSize": 2500000,
     "errors": []
   }
   ```

---

### Infrastructure Layer

**Google Cloud Platform**:

1. **Cloud Storage (GCS)**
   - **Upload Bucket**: `uploads-bucket` (transient, private)
     - Stores raw user uploads
     - Lifecycle: Auto-delete after 7 days
     - Access: Service account + signed URLs
   
   - **Public Bucket**: `public-bucket` (public read)
     - Stores processed images
     - Access: World-readable (HTTP)
     - Served via: `https://storage.googleapis.com/public-bucket/...`

2. **Cloud Pub/Sub**
   - **Topic**: `image-upload-events`
     - Triggered by GCS object finalization
     - Messages: bucket name, object name
   
   - **Subscription**: `image-upload-events-subscription`
     - Type: Push subscription
     - Endpoint: Application `POST /image-event`
     - Auth: OIDC token (production only)
     - Ack deadline: 60 seconds (development), 120 seconds (production)

3. **Cloud Run** (optional deployment)
   - Containerized PHP application
   - Environment variables: GCS buckets, Pub/Sub topic
   - Scaling: Automatic based on traffic

---

## Technology Stack

### Application

- **Framework**: Slim 4 (lightweight PHP router)
- **PHP Version**: 8.2+ (readonly properties, Enums)
- **Image Processing**: ImageMagick + Imagick PHP extension
- **UUID Generation**: ramsey/uuid
- **Testing**: PHPUnit 10.5+

### Cloud Services

- **Object Storage**: Google Cloud Storage (GCS)
- **Messaging**: Google Cloud Pub/Sub
- **Compute**: Cloud Run or Cloud Engine

### Infrastructure as Code

- **Terraform 1.0+**
- **Providers**: Google (5.0+)
- **Modules**: Reusable GCS and Pub/Sub modules
- **State**: Local (development) or GCS backend (production)

---

## Security Architecture

### Input Validation

- All user input validated server-side
- MIME type verified using PHP `finfo_file()`
- Image dimensions extracted from metadata (not filename)
- File size checked before processing

### File Upload Security

- Signed URLs limit upload window to 1 hour
- Uploads to private bucket (not publicly accessible)
- Filename not trusted; UUID used as canonical identifier
- Uploaded files deleted after 7 days if not processed

### Image Validation

- MIME type whitelist (only: jpeg, png, webp, gif)
- Dimensions limited to 4000×3000 px (prevent DoS)
- File size limited to 5MB
- Imagick used to verify and re-encode image

### Output Security

- EXIF metadata stripped (privacy)
- Only JPEG served (known format)
- Processed images in public bucket (expected)
- publicUrl returned only for completed uploads

### Authentication & Authorization

- GCS: Service account with bucket-scoped permissions
- Pub/Sub: OIDC token validation (production)
- Application: Status endpoint returns public data only

### Logging & Monitoring

- All uploads logged with timestamp, userId, guid, status
- Validation failures logged with reason
- Pub/Sub errors logged with context
- Error messages do NOT expose internal details

---

## Deployment Architecture

### Development (Local Docker)

```
┌─────────────────┐
│ Local Machine   │
├─────────────────┤
│ Docker Compose  │
│                 │
│ ┌─────────────┐ │
│ │ PHP 8.2     │ │ ← Port 8000
│ │ Slim 4      │ │
│ │ Imagick     │ │
│ │ Tests       │ │
│ └─────────────┘ │
│                 │
│ Volume Mounts:  │
│ - src/          │
│ - tests/        │
│ - storage/      │
└─────────────────┘
```

### Production (Google Cloud)

```
┌──────────────────────────────┐
│    Google Cloud Project      │
├──────────────────────────────┤
│  Cloud Run                   │
│  ┌────────────────────────┐  │
│  │ PHP Image Processor    │  │
│  │ (Containerized)        │  │
│  │ Port: 8000             │  │
│  └────────────────────────┘  │
│           ▲      │            │
│           │      │            │
│       Cloud Pub/Sub Topic     │
│       ┌─────────────┐         │
│       │ Subscription│         │
│       │ (Push)      │         │
│       └─────────────┘         │
│            ▲                  │
│            │                  │
│ ┌──────────┴───────┐          │
│ │ Cloud Storage    │          │
│ │ Upload bucket    │          │
│ │ Public bucket    │          │
│ └──────────────────┘          │
└──────────────────────────────┘
```

---

## Scaling Considerations

### Horizontal Scaling

- **Cloud Run**: Automatically scales instances based on request volume
- **GCS**: Unlimited scalability (no action required)
- **Pub/Sub**: Scales to handle message throughput
- **Local development**: Single Docker instance sufficient

### Vertical Scaling

- **Cloud Run memory**: Default 256MB sufficient for Imagick operations
- **GCS operations**: Resumable uploads for large files (future enhancement)
- **Pub/Sub**: Increase max outstanding messages for higher throughput

### Performance Optimization

- **Caching**: CloudFlare or Cloud CDN for public bucket
- **Image optimization**: Serve WebP for modern browsers (future)
- **Batch processing**: Pub/Sub batch size tuning (production)

---

## Error Handling & Resilience

### Upload Failures

| Error | Handling | Recovery |
|-------|----------|----------|
| Signed URL expired | Client retries upload-request | Automatic |
| Upload timeout | GCS retry (automatic) | Pub/Sub retries |
| Invalid MIME type | Mark as failed | User re-uploads correct type |
| Oversized image | Mark as failed | User uploads smaller image |
| Conversion failure | Mark as failed | Logged for investigation |

### Processing Failures

- **Validation failures**: UploadRecord status = `failed`, no public URL
- **Conversion failures**: Logged, Pub/Sub redelivery (up to 5 times)
- **Network failures**: Pub/Sub automatic backoff + exponential retry

### Pub/Sub Delivery Guarantees

- **At-least-once**: Messages guaranteed to be delivered (may have duplicates)
- **Idempotency**: Processing same message twice should be safe
  - Implementation: Check UploadRecord status before updating

---

## Monitoring & Debugging

### Logs

- **Application logs**: `storage/logs/` (local), Cloud Logging (production)
- **GCS logs**: Cloud Logging bucket activity
- **Pub/Sub logs**: Cloud Logging topic/subscription activity

### Metrics

- **Upload success rate**: % of uploads reaching completed state
- **Processing latency**: Time from upload to status = completed
- **Validation failure rate**: % of uploads rejected
- **Image dimensions distribution**: Track actual usage patterns

### Health Checks

- Kubernetes/Cloud Run health check: `GET /health`
- Readiness check: Verify GCS connectivity
- Liveness check: Respond to health endpoint

---

## Future Enhancements

1. **OAuth2 Authentication**: User identity verification
2. **Firestore Database**: Persistence instead of JSON files
3. **Cloud Tasks**: Async image processing queue
4. **Cloud Vision API**: Advanced image analysis (NSFW detection, OCR)
5. **Cloud Armor**: DDoS protection and WAF rules
6. **WebP Conversion**: Modern image format support
7. **Progressive Image Serving**: Blur hash + lazy loading
8. **Resumable Uploads**: Support for large file uploads
9. **Batch Processing**: Bulk image upload support
10. **API Versioning**: v1/, v2/ endpoints for backward compatibility

---

## Related Documentation

- **API.md**: Detailed endpoint specifications and examples
- **TESTING.md**: Unit, integration, and E2E test procedures
- **DEVELOPMENT.md**: Development workflow and code standards
- **SECURITY.md**: Security considerations and threat model
- **terraform/README.md**: Infrastructure provisioning guide
