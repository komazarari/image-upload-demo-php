# Data Model: GCS Image Upload Demo

**Feature**: 001-gcs-image-upload  
**Date**: 2026-01-06  
**Purpose**: Define entities, attributes, relationships for implementation

---

## Core Entities

### UploadRecord

Represents a single image upload request lifecycle.

**Purpose**: Track image through states from request → processing → completion or failure

**Attributes**:

| Attribute | Type | Description | Validation |
|-----------|------|-------------|-----------|
| `guid` | `string` (UUID v4) | Unique identifier for this upload | Format: `550e8400-e29b-41d4-a716-446655440000` |
| `userId` | `string` | Requester identifier | Non-empty, max 255 chars |
| `status` | `enum` | Current state of upload | `initialized`, `processing`, `completed`, `failed` |
| `originalFilename` | `string` \| `null` | Original filename provided by client (for logging) | Max 255 chars, stripped of path separators |
| `publicUrl` | `string` \| `null` | URL to processed image in public bucket | Null until status is `completed`; format: `https://storage.googleapis.com/...` |
| `createdAt` | `datetime` | When upload request was initiated | ISO 8601 format, UTC timezone |
| `processedAt` | `datetime` \| `null` | When image processing completed | Set by `/image-event` endpoint |
| `errorMessage` | `string` \| `null` | Human-readable error if validation failed | Only populated if status is `failed`; generic message (no system details) |
| `signedUrl` | `string` | URL for client to upload image to | Generated once; used for client's PUT request; expires after 1 hour |
| `contentType` | `string` \| `null` | MIME type of uploaded image | Detected from Pub/Sub event; e.g., `image/jpeg` |
| `fileSize` | `integer` \| `null` | Size of uploaded file in bytes | Set by Pub/Sub event metadata |
| `imageDimensions` | `object` \| `null` | Width and height of image | `{ "width": 2000, "height": 1500 }` |

**State Transitions**:

```
initialized
  ↓ (client uploads to signedUrl)
processing
  ├─ (validation fails) → failed
  └─ (validation succeeds) → completed
```

**Example JSON**:
```json
{
  "guid": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "userId": "user123",
  "status": "completed",
  "originalFilename": "vacation.jpg",
  "publicUrl": "https://storage.googleapis.com/image-upload-demo-public/f47ac10b-58cc-4372-a567-0e02b2c3d479.jpg",
  "createdAt": "2026-01-06T12:00:00Z",
  "processedAt": "2026-01-06T12:00:05Z",
  "errorMessage": null,
  "signedUrl": "https://storage.googleapis.com/image-upload-demo-upload/...",
  "contentType": "image/jpeg",
  "fileSize": 2048576,
  "imageDimensions": {
    "width": 3000,
    "height": 2000
  }
}
```

**Persistence**:
- Storage: Local JSON file at `storage/uploads/{guid}.json`
- Access: `FileStorageService::load()`, `FileStorageService::save()`
- Cleanup: Records older than 24 hours deleted by cleanup script

---

### ImageValidation

Result of image validation process; not persisted independently (included in logs).

**Purpose**: Capture validation results for logging and debugging

**Attributes**:

| Attribute | Type | Description |
|-----------|------|-------------|
| `isValid` | `boolean` | Whether image passed all validations |
| `mimeType` | `string` | Detected MIME type (from Pub/Sub or file content) |
| `width` | `integer` | Image width in pixels |
| `height` | `integer` | Image height in pixels |
| `fileSize` | `integer` | File size in bytes |
| `errors` | `array<string>` | List of validation failures (if `isValid` is false) |
| `conversionApplied` | `boolean` | Whether image was re-encoded (metadata stripped) |
| `conversionErrors` | `array<string>` | Conversion warnings (e.g., "EXIF removal failed") |

**Validation Rules**:

```
1. MIME Type
   ✓ Must be in whitelist: image/jpeg, image/png, image/gif, image/webp
   ✗ Fail if: text/html, application/exe, application/octet-stream

2. Dimensions
   ✓ Width ≤ 4000 pixels AND Height ≤ 3000 pixels
   ✗ Fail if: Width > 4000 OR Height > 3000
   ✗ Fail if: Width ≤ 0 OR Height ≤ 0

3. File Size
   ✓ Size ≤ 5MB (5242880 bytes)
   ✗ Fail if: Size > 5MB

4. Content Match
   ✓ File content matches MIME type (magic bytes)
   ✗ Fail if: getimagesize() returns false (file is not valid image)

5. Conversion
   ✓ Image can be re-encoded without loss of core content
   ✗ Warning if: EXIF removal fails but image still valid
   ✗ Fail if: Image cannot be read/written by ImageMagick
```

**Example**:
```php
$validation = new ImageValidation(
    isValid: true,
    mimeType: 'image/jpeg',
    width: 3000,
    height: 2000,
    fileSize: 2048576,
    errors: [],
    conversionApplied: true,
    conversionErrors: []
);
```

---

## Related Entities (Value Objects)

### SignedUrl

Represents a GCS signed URL for client upload.

**Attributes**:
- `url`: Full URL for client to PUT to
- `expiresAt`: Expiration time (usually 1 hour from creation)
- `bucket`: Bucket name (for reference)
- `object`: Object name (for reference)

**Example**:
```json
{
  "url": "https://storage.googleapis.com/image-upload-demo-upload/...",
  "expiresAt": "2026-01-06T13:00:00Z",
  "bucket": "image-upload-demo-upload",
  "object": "staging/temp.jpg"
}
```

---

## Relationships

```
UploadRecord (1) ---> (1) ImageValidation
                    (created by /image-event endpoint)

UploadRecord ---> GCS Upload Bucket (object stored here)
                 (client PUTs to signedUrl)

UploadRecord ---> GCS Public Bucket (object stored here after validation)
                 (if validation passes)

UploadRecord ---> Pub/Sub Subscription
                 (notified when object created in upload bucket)
```

---

## State Machine & Timing

### State Transitions Detailed

```
[initialized] (status: "initialized")
├─ Created by: POST /images/upload-request
├─ Duration: Client has 1 hour to upload
├─ Records in state: Short-lived (< 24 hours)
│
├─ Timeout: If client never uploads (> 24h) → cleanup script deletes
│
├─ SUCCESS: Client uploads to signedUrl
│  └─ → [processing] (status: "processing")
│       Created by: POST /image-event (Pub/Sub notification)
│       Duration: ~1 second (validation + conversion)
│
│       ├─ VALIDATION FAILS
│       │  └─ → [failed] (status: "failed")
│       │       Reason: MIME type invalid, dimensions too large, size > 5MB, etc.
│       │       Recorded: errorMessage populated (generic message)
│       │       publicUrl: not set
│       │       End state: Record kept for audit log (cleanup after 24h)
│       │
│       └─ VALIDATION PASSES
│          └─ → [completed] (status: "completed")
│              Processed: Image converted, copied to public bucket
│              Recorded: publicUrl set, processedAt populated
│              End state: Record kept permanently (document owner can access)
```

### Timing Constraints

| Transition | Time Limit | Timeout Handling |
|-----------|-----------|------------------|
| initialized → processing | 1 hour (signed URL lifetime) | Record stays `initialized`; eventual cleanup |
| processing → completed/failed | ~5 seconds (typical; max 30s for Pub/Sub) | Timeout: status stays `processing`; log warning |
| completed → cleanup | 24 hours (demo only) | Script deletes old records; production: permanent |

---

## API Data Flow

### Request: POST /images/upload-request

**Input** (client):
```json
{
  "userId": "user123"
}
```

**Processing**:
1. Create new `UploadRecord` with `guid` (UUID v4), `status: initialized`
2. Generate signed URL via `GcsService::createSignedUrl()`
3. Save record to `storage/uploads/{guid}.json`

**Output**:
```json
{
  "guid": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "signedUrl": "https://storage.googleapis.com/image-upload-demo-upload/...",
  "expiresAt": "2026-01-06T13:00:00Z"
}
```

### Callback: POST /image-event (Pub/Sub)

**Input** (Pub/Sub):
```json
{
  "message": {
    "data": "base64-encoded-json",
    "messageId": "123456789"
  }
}
```

**Decoded data**:
```json
{
  "name": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "bucket": "image-upload-demo-upload",
  "contentType": "image/jpeg",
  "size": 2048576
}
```

**Processing**:
1. Extract metadata from Pub/Sub message
2. Download image from upload bucket
3. Validate using `ImageValidationService::validate()`
4. If valid: Convert via `ImageConversionService::convert()`
5. If valid: Copy to public bucket via `GcsService::copyToBucket()`
6. Update `UploadRecord` → `status: completed`, `publicUrl: ...`
7. If invalid: Update `UploadRecord` → `status: failed`, `errorMessage: ...`
8. Save updated record

**Output** (HTTP):
```http
HTTP 200 OK
{
  "status": "processing"
}
```

### Request: POST /images/status

**Input**:
```json
{
  "userId": "user123",
  "guid": "f47ac10b-58cc-4372-a567-0e02b2c3d479"
}
```

**Processing**:
1. Load `UploadRecord` from `storage/uploads/{guid}.json`
2. Return current state

**Output** (if `completed`):
```json
{
  "status": "completed",
  "publicUrl": "https://storage.googleapis.com/image-upload-demo-public/f47ac10b-58cc-4372-a567-0e02b2c3d479.jpg"
}
```

**Output** (if `processing` or `initialized`):
```json
{
  "status": "processing"
}
```

**Output** (if `failed`):
```json
{
  "status": "failed"
}
```

---

## Validation Rules Summary

### UploadRecord Validation

| Field | Required | Type | Rules |
|-------|----------|------|-------|
| `guid` | Yes | UUID | Must be valid UUID v4; must be unique |
| `userId` | Yes | string | Non-empty; max 255 chars; alphanumeric + underscore |
| `status` | Yes | enum | Must be one of: initialized, processing, completed, failed |
| `originalFilename` | No | string | Max 255 chars; sanitized (no `../` or null bytes) |
| `publicUrl` | No | string (URL) | Must be HTTPS; must be in correct GCS bucket; required if status is completed |
| `createdAt` | Yes | datetime | Must be ISO 8601; must be ≤ now |
| `processedAt` | No | datetime | Must be ≥ createdAt if present |
| `errorMessage` | No | string | Max 255 chars; generic message (no system paths); only if status is failed |

### ImageValidation Validation

| Rule | Condition | Action |
|------|-----------|--------|
| MIME whitelist | Content-Type not in [image/jpeg, image/png, image/gif, image/webp] | Reject |
| Max width | width > 4000 | Reject |
| Max height | height > 3000 | Reject |
| Min size | size < 1024 bytes (1KB) | Reject (likely corrupted) |
| Max size | size > 5242880 bytes (5MB) | Reject |
| Magic bytes | getimagesize() returns false | Reject |

---

## Entity Relationships & Dependencies

```
FileStorageService
  - Loads/saves UploadRecord
  - Uses: GUID to generate file path

GcsService
  - Creates signed URLs (uses: bucket, guid)
  - Downloads image (uses: bucket, guid)
  - Copies image (uses: bucket, original filename)

ImageValidationService
  - Validates image file
  - Returns: ImageValidation result
  - Uses: MIME type, dimensions, file size

ImageConversionService
  - Converts/re-encodes image
  - Uses: ImageMagick library
  - Returns: Path to converted image

ImageEventController
  - Calls: ImageValidationService, ImageConversionService, GcsService, FileStorageService
  - Updates: UploadRecord status
  - Logs: Validation results
```

---

## Type Definitions (PHP)

For implementation reference:

```php
// Enums
enum UploadStatus: string {
    case INITIALIZED = 'initialized';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}

// DTO/Models
class UploadRecord {
    public function __construct(
        public string $guid,
        public string $userId,
        public UploadStatus $status,
        public ?string $originalFilename,
        public ?string $publicUrl,
        public DateTime $createdAt,
        public ?DateTime $processedAt,
        public ?string $errorMessage,
        public string $signedUrl,
        public ?string $contentType,
        public ?int $fileSize,
        public ?array $imageDimensions,
    ) {}
}

class ImageValidation {
    public function __construct(
        public bool $isValid,
        public string $mimeType,
        public int $width,
        public int $height,
        public int $fileSize,
        public array $errors,
        public bool $conversionApplied,
        public array $conversionErrors,
    ) {}
}
```

---

## Summary

**UploadRecord** tracks complete lifecycle of image from request to completion.  
**ImageValidation** captures validation state and used for logging.  
**State machine** ensures proper progression: initialized → processing → completed or failed.  
**Validation rules** enforce MIME types, dimensions, file sizes, content integrity.  
**JSON storage** keeps demo simple; document production path (Firestore).

**Next**: Create API contracts and quickstart based on this data model.
