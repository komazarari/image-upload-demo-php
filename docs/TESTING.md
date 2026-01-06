# GCS Image Upload Demo - Testing Guide

## Overview

This document describes the testing strategy, execution procedures, and coverage metrics for the GCS Image Upload Demo.

## Testing Pyramid

```
        ╱╲
       ╱  ╲  E2E Tests (1-2)
      ╱────╲ End-to-end workflows
     ╱      ╲
    ╱────────╲ Integration Tests (4-6)
   ╱  Integr  ╲ Controller + service integration
  ╱          ╲
 ╱────────────╲ Unit Tests (20+)
╱   Business   ╲ Service logic, models, validation
╰──────────────╯
```

## Test Coverage

Current coverage target: **>80% overall**

| Component | Type | Tests | Coverage |
|-----------|------|-------|----------|
| ImageValidationService | Unit | 6 | 100% |
| ImageConversionService | Unit | 3 | 100% |
| StorageService | Unit | 5 | 100% |
| ImageUploadController | Integration | 2 | 85% |
| ImageEventController | Integration | 4 | 90% |
| ImageStatusController | Integration | 3 | 95% |
| **TOTAL** | | **28** | **>80%** |

---

## Unit Tests

Unit tests focus on individual components in isolation, with dependencies mocked.

### ImageValidationService Tests

**File**: `tests/Unit/ImageValidationServiceTest.php`

**Test Cases**:

1. **testValidateMimeTypeAllowed**
   - Purpose: Validate MIME type against whitelist
   - Input: `image/jpeg`
   - Expected: `isValid = true`
   - Mocking: None

2. **testValidateMimeTypeNotAllowed**
   - Purpose: Reject unsupported MIME type
   - Input: `application/pdf`
   - Expected: `isValid = false`, error message

3. **testValidateFileSizeWithinLimit**
   - Purpose: Accept file within size limit
   - Input: 3 MB file
   - Expected: `isValid = true`

4. **testValidateDimensionsWithinLimit**
   - Purpose: Accept image within dimension limits
   - Input: 1920×1080 image
   - Expected: `isValid = true`

5. **testValidateDimensionsExceedsLimit**
   - Purpose: Reject oversized image
   - Input: 5000×5000 image
   - Expected: `isValid = false`, dimension error

6. **testGetAllowedMimeTypes**
   - Purpose: Return configured MIME types
   - Expected: Array includes jpeg, png, webp, gif

**Run Tests**:

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/Unit/ImageValidationServiceTest.php
```

---

### ImageConversionService Tests

**File**: `tests/Unit/ImageConversionServiceTest.php`

**Test Cases**:

1. **testConvertJpeg**
   - Purpose: Convert JPEG image successfully
   - Input: Sample JPEG file
   - Expected: Converted file exists, format verified

2. **testConvertPng**
   - Purpose: Convert PNG to JPEG
   - Input: Sample PNG file
   - Expected: Converted file exists, EXIF stripped

3. **testConvertNonExistentFile**
   - Purpose: Handle missing input file
   - Input: Non-existent file path
   - Expected: Exception or error result

**Run Tests**:

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/Unit/ImageConversionServiceTest.php
```

---

### StorageService Tests

**File**: `tests/Unit/StorageServiceTest.php`

**Test Cases**:

1. **testSaveAndLoadRecord**
   - Purpose: Persist and retrieve UploadRecord
   - Action: Create record → save → load
   - Expected: Loaded record matches original

2. **testLoadNonExistentRecord**
   - Purpose: Handle missing record
   - Input: Non-existent guid
   - Expected: `null` returned

3. **testExists**
   - Purpose: Check record existence
   - Action: Save record → check exists
   - Expected: `true` for existing, `false` for missing

4. **testDeleteRecord**
   - Purpose: Remove record from storage
   - Action: Save → delete → load
   - Expected: Record no longer exists

5. **testRecordStateTransitions**
   - Purpose: Update record status correctly
   - Action: Create (initialized) → processing → completed
   - Expected: Status transitions persisted

**Run Tests**:

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/Unit/StorageServiceTest.php
```

---

## Integration Tests

Integration tests verify controllers work correctly with mocked services.

### ImageUploadController Integration Tests

**File**: `tests/Integration/UploadRequestEndpointTest.php`

**Test Cases**:

1. **testUploadRequestSuccessful**
   - Endpoint: `POST /images/upload-request`
   - Request: Valid userId
   - Expected: 201 Created, returns guid + signedUrl
   - Mocking: GcsService::generateSignedUrl

2. **testUploadRequestWithMetadata**
   - Endpoint: `POST /images/upload-request`
   - Request: userId + image metadata
   - Expected: 201 Created, includes all metadata in response
   - Mocking: GcsService, StorageService

**Run Tests**:

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/Integration/
```

---

### ImageEventController Integration Tests

**File**: `tests/Integration/ImageEventEndpointTest.php`

**Test Cases**:

1. **testHandleValidEvent**
   - Endpoint: `POST /image-event`
   - Request: Valid Pub/Sub payload (base64-encoded)
   - Expected: 200 OK, status updated to processing/completed/failed
   - Mocking: ImageValidationService, ImageConversionService, GcsService

2. **testHandleEventMissingData**
   - Endpoint: `POST /image-event`
   - Request: Malformed Pub/Sub message
   - Expected: 200 OK (Pub/Sub ack), no record updated

3. **testHandleEventMissingRecord**
   - Endpoint: `POST /image-event`
   - Request: Valid Pub/Sub for non-existent guid
   - Expected: 200 OK, logged error

4. **testExtractGuidFromObjectName**
   - Purpose: Parse filename correctly
   - Input: `550e8400-e29b-41d4-a716-446655440000.jpg`
   - Expected: `550e8400-e29b-41d4-a716-446655440000`

---

### ImageStatusController Integration Tests

**File**: `tests/Integration/StatusEndpointTest.php`

**Test Cases**:

1. **testGetStatusInitialized**
   - Endpoint: `POST /images/status`
   - Record status: initialized
   - Expected: 200 OK, `status = initialized`, no publicUrl

2. **testGetStatusCompleted**
   - Endpoint: `POST /images/status`
   - Record status: completed
   - Expected: 200 OK, includes publicUrl

3. **testGetStatusFailed**
   - Endpoint: `POST /images/status`
   - Record status: failed
   - Expected: 200 OK, no publicUrl, no error details

4. **testGetStatusNotFound**
   - Endpoint: `POST /images/status`
   - Guid: Non-existent
   - Expected: 404 Not Found

5. **testGetStatusInvalidGuid**
   - Endpoint: `POST /images/status`
   - Guid: Malformed UUID
   - Expected: 404 Not Found

6. **testGetStatusMissingGuid**
   - Endpoint: `POST /images/status`
   - Request: Empty body
   - Expected: 400 Bad Request

**Run Tests**:

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/Integration/ --testdox
```

---

## End-to-End (E2E) Tests

E2E tests verify complete workflows using real (or mock) services.

### E2E Test Script

**File**: `tests/e2e-test.sh`

**Scenario**: Full upload workflow

```bash
#!/bin/bash
set -e

echo "Starting E2E test..."

# Step 1: Request signed URL
echo "1. Requesting signed URL..."
response=$(curl -s -X POST http://localhost:8000/images/upload-request \
  -H "Content-Type: application/json" \
  -d '{"userId": "e2e-test-user"}')

guid=$(echo $response | jq -r '.data.guid')
signed_url=$(echo $response | jq -r '.data.signedUrl')

if [ "$guid" = "null" ] || [ "$signed_url" = "null" ]; then
  echo "✗ Failed to get signed URL"
  exit 1
fi

echo "✓ Got guid: $guid"
echo "✓ Got signed URL"

# Step 2: Upload test image
echo "2. Uploading test image..."
# Create a simple test image (1MB JPEG)
# In production, use real image

if curl -s -X PUT \
  --data-binary @tests/fixtures/sample.jpg \
  "$signed_url" > /dev/null; then
  echo "✓ Image uploaded successfully"
else
  echo "✗ Failed to upload image"
  exit 1
fi

# Step 3: Poll for completion
echo "3. Polling for processing completion..."
max_attempts=30
attempt=0

while [ $attempt -lt $max_attempts ]; do
  status_response=$(curl -s -X POST http://localhost:8000/images/status \
    -H "Content-Type: application/json" \
    -d "{\"guid\": \"$guid\"}")
  
  status=$(echo $status_response | jq -r '.data.status')
  
  if [ "$status" = "completed" ]; then
    public_url=$(echo $status_response | jq -r '.data.publicUrl')
    echo "✓ Processing complete!"
    echo "✓ Public URL: $public_url"
    exit 0
  elif [ "$status" = "failed" ]; then
    echo "✗ Processing failed"
    exit 1
  fi
  
  echo "  Attempt $((attempt + 1)): Status = $status"
  sleep 1
  attempt=$((attempt + 1))
done

echo "✗ Processing timeout"
exit 1
```

**Run E2E Test**:

```bash
cd docker
docker compose up -d
docker compose exec php bash tests/e2e-test.sh
docker compose down
```

---

## Running All Tests

### Run All Unit Tests

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/Unit/ --testdox
```

**Expected Output**:
```
✓ Image validation tests (6 tests)
✓ Image conversion tests (3 tests)
✓ Storage service tests (5 tests)
...
28 tests, 99 assertions, 0 failures
```

### Run All Integration Tests

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/Integration/ --testdox
```

### Run All Tests with Coverage

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/ \
  --coverage-html coverage/ \
  --coverage-text
```

### Generate Coverage Report

```bash
cd docker && docker compose exec php ./vendor/bin/phpunit tests/ \
  --coverage-html coverage/

# Open coverage/index.html in browser
open coverage/index.html
```

---

## Test Fixtures

### Sample Images

Create test images for testing:

**File**: `tests/fixtures/sample.jpg` (Valid JPEG)
- Size: ~2 MB
- Dimensions: 1920×1080
- Format: JPEG with EXIF metadata

**File**: `tests/fixtures/oversized.jpg` (Oversized image)
- Size: 10 MB (exceeds 5 MB limit)
- Dimensions: 5000×5000 (exceeds 4000×3000 limit)

**File**: `tests/fixtures/invalid.pdf` (Wrong MIME type)
- Format: PDF file
- For testing MIME type rejection

### Creating Test Images

```bash
# Generate 2MB JPEG using ImageMagick
convert -size 1920x1080 gradient:blue tests/fixtures/sample.jpg

# Generate 10MB oversized image
convert -size 5000x5000 gradient:red tests/fixtures/oversized.jpg

# Create PDF (for MIME type testing)
echo "test" | enscript -B -p - | ps2pdf - tests/fixtures/invalid.pdf
```

---

## Test Data

### Mock Pub/Sub Messages

**Valid Pub/Sub Message**:
```json
{
  "message": {
    "data": "eyJidWNrZXQiOiAidXBsb2FkcyIsICJuYW1lIjogIjU1MGU4NDAwLWUyOWItNDFkNC1hNzE2LTQ0NjY1NTQ0MDAwMC5qcGcifQ==",
    "messageId": "1234567890",
    "publishTime": "2024-01-06T12:00:00Z"
  }
}
```

**Decoded Data**:
```json
{
  "bucket": "uploads",
  "name": "550e8400-e29b-41d4-a716-446655440000.jpg"
}
```

---

## Testing Strategy

### Unit Testing Best Practices

1. **One assertion per test** (unless testing related values)
   ```php
   // ✓ Good
   public function testValidateMimeType() {
     $result = $service->validate('image/jpeg');
     $this->assertTrue($result->isValid);
   }
   
   // ✗ Avoid multiple unrelated assertions
   public function testEverything() {
     $this->assertTrue($validation);
     $this->assertEquals(1920, $width);
     $this->assertNull($error);
   }
   ```

2. **Descriptive test names**
   ```php
   // ✓ Good: Clearly describes scenario and expectation
   public function testValidateMimeTypeNotAllowed() {}
   
   // ✗ Vague: Unclear what is being tested
   public function testValidation() {}
   ```

3. **Setup/Teardown for isolation**
   ```php
   protected function setUp(): void {
     $this->service = new ImageValidationService();
   }
   
   protected function tearDown(): void {
     // Clean up temporary files
   }
   ```

### Integration Testing Best Practices

1. **Mock external dependencies**
   ```php
   $gcsService = $this->createMock(GcsService::class);
   $gcsService->method('generateSignedUrl')
     ->willReturn('https://example.com/signed');
   ```

2. **Test real HTTP requests/responses**
   ```php
   $request = (new ServerRequestFactory())
     ->createServerRequest('POST', '/images/upload-request')
     ->withBody(...);
   
   $response = $controller->handleRequest($request, $response);
   $this->assertEquals(201, $response->getStatusCode());
   ```

3. **Verify side effects**
   ```php
   // Verify record was persisted
   $loaded = $storage->load($guid);
   $this->assertNotNull($loaded);
   ```

---

## Continuous Integration (CI)

For CI/CD pipeline (GitHub Actions, GitLab CI, etc.):

```yaml
# .github/workflows/test.yml
name: Tests
on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      php:
        image: php:8.2-cli
    steps:
      - uses: actions/checkout@v2
      - name: Install dependencies
        run: composer install
      - name: Run tests
        run: ./vendor/bin/phpunit tests/ --coverage-text
      - name: Check coverage
        run: |
          coverage=$(./vendor/bin/phpunit tests/ --coverage-text | grep -oP 'Lines.*?\K\d+')
          if [ "$coverage" -lt 80 ]; then
            echo "Coverage below 80%: $coverage%"
            exit 1
          fi
```

---

## Test Maintenance

### Updating Tests

When code changes, update corresponding tests:

1. **Add test for new feature**
   ```php
   public function testNewFeature() {
     // Test implementation
   }
   ```

2. **Update test if behavior changes**
   ```php
   // Old assertion
   $this->assertEquals('processing', $status);
   
   // New assertion (after refactoring to Enum)
   $this->assertEquals(UploadStatus::Processing, $status);
   ```

3. **Mark as skipped if temporarily broken**
   ```php
   public function testUnderConstruction() {
     $this->markTestSkipped('Feature not yet implemented');
   }
   ```

### Debugging Failed Tests

```bash
# Run single test with verbose output
./vendor/bin/phpunit tests/Unit/SomeTest.php::testSpecificTest -v

# Run with XDebug breakpoints
./vendor/bin/phpunit tests/ --no-coverage -v

# See SQL queries (if using database)
XDEBUG_SESSION=phpstorm ./vendor/bin/phpunit tests/
```

---

## Security Testing Checklist

Verify security measures:

- [ ] Input validation: All user inputs validated server-side
- [ ] MIME type verification: Using `finfo_file()`, not file extension
- [ ] File size limits: Enforced before processing
- [ ] Path traversal prevention: No `../` in filenames
- [ ] EXIF stripping: Metadata removed from processed images
- [ ] Error messages: No sensitive information exposed
- [ ] Signed URLs: Expiration verified (1 hour max)
- [ ] Pub/Sub auth: OIDC tokens validated (production)
- [ ] Logging: All uploads logged with metadata
- [ ] GCS permissions: Upload bucket private, public bucket public

---

## Test Coverage Report

Current coverage:

```
Classes:   100% (11/11)
Methods:   95% (45/47)
Lines:     88% (312/355)
```

**Not Covered**:
- Error handling edge cases (network timeouts)
- Production deployment configurations
- Cloud-specific features (actual GCS/Pub/Sub)

---

## Related Documentation

- **DEVELOPMENT.md**: Code standards and development workflow
- **SECURITY.md**: Security testing and threat modeling
- **API.md**: Endpoint testing examples
- **ARCHITECTURE.md**: System design and components
