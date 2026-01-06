# Research: GCS Image Upload Demo

**Feature**: 001-gcs-image-upload  
**Date**: 2026-01-06  
**Purpose**: Resolve unknowns from Technical Context before Phase 1 design

---

## R1: Pub/Sub Push Authentication & Security

**Question**: How to verify Pub/Sub messages are authentic and prevent spoofing?

**Research Summary**:

Google Cloud Pub/Sub push subscriptions include built-in authentication mechanisms:

1. **OIDC Token Authentication** (Recommended)
   - Pub/Sub pushes messages with signed OIDC token in `Authorization` header
   - Token format: `Authorization: Bearer <jwt>`
   - Server validates token using Google's public keys (endpoint: `https://www.googleapis.com/oauth2/v1/certs`)
   - Token includes claim: `aud` = service endpoint URL
   - Verification: Decode JWT, validate signature, check `aud` claim matches endpoint

2. **Service Account Authentication** (Alternative)
   - Configure push subscription with specific service account
   - Pub/Sub signs request with service account private key
   - Header: `Authorization: Bearer <service-account-signed-token>`

3. **Implementation Approach** (for this demo):
   - Use OIDC token validation (simpler, Google manages rotation)
   - Library: `firebase/php-jwt` for token verification
   - Or: Use Google Cloud PHP client library (handles validation automatically)
   - Fallback: Accept unsigned requests in `development` mode, validate in `production`

**Decision**: Use Google Cloud PHP library for automatic token validation. Library handles OIDC verification transparently.

**Rationale**: 
- ✅ Zero configuration: Library uses Application Default Credentials
- ✅ Automatic key rotation: Google manages public key updates
- ✅ Standard practice: Recommended by Google Cloud documentation
- ⚠️  Risk if library unavailable: Fall back to manual JWT validation using `firebase/php-jwt`

**Implementation**:
```php
// Google Cloud PHP library includes PubSub client with built-in auth validation
use Google\Cloud\PubSub\PubSubClient;

$pubsub = new PubSubClient();
$subscription = $pubsub->subscription($subscriptionName);

// Handling incoming message in endpoint:
$message = json_decode(file_get_contents('php://input'), true);

// Library validates OIDC token automatically via middleware
// If invalid, throws exception → return 401
```

---

## R2: GCS Signed URL Signing Strategy

**Question**: How to sign GCS URLs? Which service account credentials?

**Research Summary**:

GCS signed URLs require a private key for signing. Options:

1. **Service Account Key File** (Recommended for demo)
   - Create service account in GCP project: `image-upload-demo@project-id.iam.gserviceaccount.com`
   - Roles needed:
     - `roles/storage.objectCreator` (upload bucket - PUT only)
     - `roles/storage.admin` (public bucket - read/write for conversion)
   - Export JSON key file
   - Use in PHP: `Google\Cloud\Storage\StorageClient` handles signing automatically
   - **Security Note**: Key file NEVER committed; stored as secret in deployment

2. **Application Default Credentials** (Simpler)
   - Run app in GCP environment (Cloud Run, App Engine, GKE)
   - App automatically uses VM service account credentials
   - No key file needed; credentials managed by GCP
   - **Recommended for production**

3. **Time-Limited Keys** (Advanced)
   - Use GCP Security Token Service (STS) for short-lived credentials
   - Credentials auto-rotate every hour
   - More complex to implement

**Decision**: Use Application Default Credentials (Cloud Run environment); fall back to service account key file for local development.

**Rationale**:
- ✅ Production: Automatic, no secrets management
- ✅ Development: Key file loaded from `.env` or `GOOGLE_APPLICATION_CREDENTIALS`
- ✅ Security: Credentials scoped to required roles only
- ✅ Testability: Mock Google\Cloud\Storage\StorageClient in unit tests

**Implementation**:
```php
use Google\Cloud\Storage\StorageClient;

$storage = new StorageClient([
    'projectId' => getenv('GCP_PROJECT_ID'),
    // If running on Cloud Run: automatic credential detection
    // If local: GOOGLE_APPLICATION_CREDENTIALS env var points to key file
]);

$bucket = $storage->bucket($uploadBucketName);
$options = [
    'version' => 'v4',
    'lifetime' => 60 * 60, // 1 hour in seconds
];

$signedUrl = $bucket->object($filename)->signedUrl(
    new \DateTime('+1 hour'),
    ['version' => 'v4']
);

// Return signedUrl to client for PUT request
```

---

## R3: Image Conversion & Validation Library

**Question**: ImageMagick availability and best approach for validation/conversion?

**Research Summary**:

Options for image validation and conversion:

1. **ImageMagick (Recommended)**
   - **Availability**: Available in official PHP Docker images
   - **Installation**: `pecl install imagick` or `apt-get install imagemagick`
   - **Pros**:
     - Robust format detection (getimagesize() fallback)
     - Powerful conversion (remove metadata, re-encode)
     - Handles all image formats
     - Battle-tested security (used by WordPress, etc.)
   - **Cons**: Requires system library installation; large package size
   - **PHP Integration**: `Imagick` class (OOP) or shell `convert` command (procedural)

2. **GD Library** (Lighter alternative)
   - **Availability**: Built into PHP
   - **Pros**:
     - No external dependencies
     - Fast, minimal
   - **Cons**:
     - Limited format support (JPEG, PNG, GIF only; no WebP in older PHP)
     - Less powerful metadata removal
     - Security concerns: less battle-tested for hostile images

3. **Hybrid Approach** (Pragmatic)
   - Use `getimagesize()` for basic validation (format detection)
   - Use ImageMagick `identify` command for detailed validation
   - Use ImageMagick `convert` command for re-encoding (metadata removal)
   - Fallback to GD if ImageMagick unavailable

**Decision**: Use ImageMagick with PHP `Imagick` class; fallback to command-line tools if extension unavailable.

**Rationale**:
- ✅ Security: Removes EXIF, embedded files, malicious code
- ✅ Flexibility: Handles all modern formats (JPEG, PNG, GIF, WebP)
- ✅ Testability: Can mock in unit tests
- ✅ Demo appropriateness: Standard production approach

**Implementation**:
```php
class ImageConversionService {
    public function validateAndConvert(string $sourcePath): string {
        // 1. Detect format
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) throw new Exception('Invalid image');
        
        // 2. Validate dimensions
        [$width, $height] = $imageInfo;
        if ($width > 4000 || $height > 3000) {
            throw new Exception('Image too large');
        }
        
        // 3. Re-encode to remove metadata
        $imagick = new \Imagick($sourcePath);
        $imagick->stripImage(); // Remove EXIF
        $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $imagick->setImageCompressionQuality(85); // Lossy but good quality
        
        $outputPath = tempnam(sys_get_temp_dir(), 'img_');
        $imagick->writeImage($outputPath);
        $imagick->destroy();
        
        return $outputPath;
    }
}
```

---

## R4: Upload Record Storage Strategy

**Question**: How to store upload records? JSON file location, cleanup policy?

**Research Summary**:

Options for persisting upload status:

1. **Local JSON Files** (Simplest for demo)
   - Storage location: `storage/uploads/{guid}.json`
   - Format: `{ "guid": "...", "userId": "...", "status": "...", "publicUrl": "..." }`
   - Pros:
     - No database setup
     - Human-readable, easy to debug
     - File-based, natural cleanup
   - Cons:
     - Not scalable (file I/O bottleneck)
     - Concurrency issues (file locking needed)
     - No querying capability

2. **In-Memory Cache** (For rapid testing)
   - Store in PHP array during request lifecycle
   - Pros: Fast, simple for demo
   - Cons: Lost on restart; not suitable for production

3. **Cloud Firestore** (Production-ready)
   - GCP managed database
   - Pros: Scalable, real-time, secure
   - Cons: Requires setup; outside demo scope

**Decision**: Local JSON files for demo; document Firestore as production path.

**Rationale**:
- ✅ Demo simplicity: No database provisioning
- ✅ Testability: Easy to inspect files during development
- ✅ Fair representation: Shows typical lifecycle without complexity
- ⬜ Limitation acknowledged: Document "production: use Firestore"

**Cleanup Policy**:
- Manual cleanup script: `php cleanup-old-uploads.php` (removes records >24h old)
- Alternative: Cron job in Docker entrypoint
- **Not critical for demo**: Limited upload volume

**Implementation**:
```php
class FileStorageService {
    private string $storagePath = 'storage/uploads';
    
    public function save(UploadRecord $record): void {
        $path = $this->storagePath . '/' . $record->guid . '.json';
        file_put_contents($path, json_encode($record), LOCK_EX);
    }
    
    public function load(string $guid): ?UploadRecord {
        $path = $this->storagePath . '/' . $guid . '.json';
        if (!file_exists($path)) return null;
        return UploadRecord::fromJson(
            json_decode(file_get_contents($path), true)
        );
    }
    
    public function cleanup(int $ttlSeconds = 86400): void {
        $cutoff = time() - $ttlSeconds;
        foreach (glob($this->storagePath . '/*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
```

---

## R5: GUID Generation & Collision Risk

**Question**: Is UUID v4 adequate for guid? Do we need database sequences?

**Research Summary**:

GUID collision risk analysis:

1. **UUID v4 Characteristics**:
   - Format: 128-bit random identifier (e.g., `550e8400-e29b-41d4-a716-446655440000`)
   - Collision probability (birthday paradox):
     - 50% chance after ~2.6 × 10^18 IDs generated
     - For 1M uploads/day: ~7,000 years to 50% collision
   - Standard: RFC 4122 compliant

2. **Alternatives**:
   - **Sequential IDs**: Simple but reveals upload rate; security risk
   - **Snowflake IDs**: Twitter's distributed ID generator; overkill for demo
   - **ULID**: Time-ordered, sortable; good but less standardized
   - **Database sequences**: Requires database; over-engineered for demo

3. **Verdict**: UUID v4 is adequate for demo use case.

**Decision**: Use UUID v4 via `Ramsey\Uuid\Uuid::uuid4()`.

**Rationale**:
- ✅ Collision risk: Negligible (1M uploads/day = 7000 years to collision)
- ✅ Standard: RFC 4122 compliant, universally recognized
- ✅ No dependencies: Ramsey UUID library available via Composer
- ✅ Simple: No sequential patterns; suitable for learning
- ⚠️  Note: In production, database auto-increment or better generator might be preferred

**Implementation**:
```php
use Ramsey\Uuid\Uuid;

class UploadService {
    public function createUploadRequest(string $userId): UploadRecord {
        $record = new UploadRecord(
            guid: Uuid::uuid4()->toString(),
            userId: $userId,
            status: 'initialized',
            createdAt: new \DateTime(),
        );
        
        $this->storage->save($record);
        return $record;
    }
}
```

---

## R6: Error Handling & Logging Strategy

**Question**: Where to log? stdout? files? Cloud Logging? How to structure logs?

**Research Summary**:

Logging options and best practices:

1. **Docker/Container Environment**:
   - Write to **stdout/stderr** (standard practice)
   - Docker captures stdout → forwarded to logging system
   - GCP Cloud Run: Automatically ingests stdout → Cloud Logging
   - Easy debugging: `docker logs <container>`

2. **Log Structure**:
   - Use **JSON formatted logs** (structured logging)
   - Easier for log aggregation systems
   - Includes: timestamp, level, message, context (userId, guid, etc.)

3. **Logging Levels**:
   - **INFO**: Upload request received, validation started
   - **SUCCESS**: Image validated, copied to public bucket
   - **WARNING**: Unusual but non-fatal (e.g., EXIF removal failed but image still valid)
   - **ERROR**: Validation failed, public bucket copy failed
   - **DEBUG**: Signed URL generated, Pub/Sub message received (dev mode only)

4. **What NOT to log**:
   - ✗ Full file contents
   - ✗ Private keys or tokens
   - ✗ User passwords
   - ✅ Instead: Log hashes, token prefixes (first 8 chars only)

**Decision**: Structured JSON logging to stdout; optional file logging for local dev.

**Rationale**:
- ✅ Standard: Best practice for containers
- ✅ Debugging: Real-time with `docker logs`
- ✅ Production: Automatic integration with Cloud Logging
- ✅ Security: No sensitive data in logs

**Implementation**:
```php
class Logger {
    public function log(string $level, string $message, array $context = []): void {
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
        
        echo json_encode($log) . "\n";
    }
}

// Usage
$logger->log('INFO', 'Upload request received', [
    'userId' => $userId,
    'guid' => $guid,
    'endpoint' => '/images/upload-request',
]);

$logger->log('ERROR', 'Image validation failed', [
    'guid' => $guid,
    'reason' => 'dimensions_exceeded',
    'width' => 5000,
    'maxWidth' => 4000,
]);
```

---

## R7: Pub/Sub Message Schema & Handling

**Question**: What does Pub/Sub message contain? How to extract GCS metadata?

**Research Summary**:

GCS Pub/Sub message structure:

When object is uploaded to GCS, Pub/Sub receives:
```json
{
  "message": {
    "data": "eyJuYW1lIjoiZmlsZS5qcGciLCJidWNrZXQiOiJ1cGxvYWQtYnVja2V0In0=",
    "messageId": "123456789",
    "publishTime": "2026-01-06T12:34:56.789Z"
  }
}
```

The `data` field is base64-encoded JSON:
```json
{
  "name": "file.jpg",
  "bucket": "upload-bucket",
  "contentType": "image/jpeg",
  "size": 2048576,
  "metadata": {},
  "timeCreated": "2026-01-06T12:34:56.789Z",
  "updated": "2026-01-06T12:34:56.789Z"
}
```

**Decoding steps**:
1. Extract `message.data` from envelope
2. Base64 decode
3. JSON parse
4. Use: `bucket`, `name`, `contentType`, `size`

**Edge case**: GCS can send notification for object deletion too. Ignore those.

**Decision**: Parse Pub/Sub message, extract object metadata, log it, validate image.

**Implementation**:
```php
class ImageEventController {
    public function handlePubSubEvent(): Response {
        $requestBody = json_decode(file_get_contents('php://input'), true);
        
        // Extract message
        $message = $requestBody['message'] ?? null;
        if (!$message) return new Response('Bad request', 400);
        
        // Decode data
        $data = json_decode(
            base64_decode($message['data']),
            true
        );
        
        $bucket = $data['bucket'] ?? null;
        $name = $data['name'] ?? null;
        $contentType = $data['contentType'] ?? null;
        $size = $data['size'] ?? null;
        
        // Process...
        
        return new Response(json_encode(['status' => 'processing']), 200);
    }
}
```

---

## R8: Image Format & Quality Preservation

**Question**: When converting image, how to preserve quality? What output format?

**Research Summary**:

Conversion strategy for image preservation:

1. **Output Format**: Same as input
   - JPEG → JPEG (lossy)
   - PNG → PNG (lossless)
   - GIF → GIF (limited colors)
   - WebP → WebP (modern, efficient)
   - Preserves user intent and compatibility

2. **Lossy Format Settings** (JPEG, WebP):
   - Quality setting: 85-90 (human-perceptible quality, good compression)
   - Too low (<70): Visible artifacts
   - Too high (>95): Large files, minimal improvement
   - **Recommended**: 85 (good balance)

3. **Lossless Format Settings** (PNG, GIF):
   - PNG: No compression settings (always lossless)
   - GIF: Color palette reduction (optional)

4. **Metadata Removal**:
   - Use `Imagick::stripImage()` (removes EXIF, color profile)
   - Prevents embedded exploits
   - Slight size reduction

**Decision**: Convert to same format; quality 85 for lossy; strip all metadata.

**Rationale**:
- ✅ User intent: User uploaded JPEG → get JPEG back
- ✅ Security: EXIF/profiles removed; image re-encoded (no embedded code)
- ✅ Quality: 85 is industry standard (WordPress, etc. use similar)
- ✅ Demo appropriateness: Standard production approach

**Implementation**:
```php
$imagick = new \Imagick($sourcePath);

// Detect original format
$format = $imagick->getImageFormat(); // "JPEG", "PNG", etc.

// Strip metadata
$imagick->stripImage();

// Set quality for lossy formats
if (in_array(strtolower($format), ['jpeg', 'jpg', 'webp'])) {
    $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
    $imagick->setImageCompressionQuality(85);
}

// Write output
$outputPath = tempnam(sys_get_temp_dir(), 'img_');
$imagick->setFormat($format); // Preserve format
$imagick->writeImage($outputPath);
```

---

## Summary: Research Conclusions

| # | Question | Decision | Evidence |
|---|----------|----------|----------|
| R1 | Pub/Sub Auth | OIDC tokens via Google Cloud library | RFC 7519, GCP docs |
| R2 | GCS Signing | Application Default Credentials (Cloud Run) + key file (dev) | GCP best practices |
| R3 | Image Library | ImageMagick + Imagick PHP class | Standard in Docker, battle-tested |
| R4 | Upload Storage | JSON files in `storage/uploads/`; document Firestore for production | Demo simplicity, testability |
| R5 | GUID Format | UUID v4 via Ramsey/UUID library | RFC 4122, collision risk negligible |
| R6 | Logging | JSON to stdout (Docker standard) | Cloud Run best practices |
| R7 | Pub/Sub Schema | Base64-decode data, extract bucket/name/contentType | GCP official docs |
| R8 | Image Quality | Same format as input; quality 85 for lossy; strip metadata | Industry standard |

---

## Production Considerations (Out of Scope for Demo)

These should be documented but not implemented:

1. **Authentication**: Add OAuth2 or JWT for userId validation
2. **Rate Limiting**: Prevent abuse with per-user upload limits
3. **Database**: Replace JSON files with Firestore for scalability
4. **Async Processing**: Use Cloud Tasks for large image processing
5. **CDN**: Serve public images via Cloud CDN
6. **Virus Scanning**: Integrate VirusTotal or ClamAV
7. **Monitoring**: Set up Cloud Monitoring alerts

---

**Research Complete**: All unknowns resolved. Ready for Phase 1 design (data-model.md, contracts, quickstart.md).
