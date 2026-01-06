# Feature Specification: GCS Image Upload Demo

**Feature Branch**: `001-gcs-image-upload`  
**Created**: 2026-01-06  
**Status**: Draft  
**Input**: User description: "Google Cloud Storage に画像をアップロードするデモアプリケーション：PHP Slim サーバと shell script クライアント"

## Overview

A secure, two-stage image upload system where:
1. **Client** (bash + curl) requests upload permission from server
2. **Server** (PHP Slim) issues a signed URL for direct upload to Google Cloud Storage
3. **GCS + Pub/Sub** notify server when upload completes
4. **Server** validates and processes image, then copies to public storage bucket
5. **Client** polls server for final public URL

This architecture separates concerns: client initiates, server validates & controls, GCS handles storage.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Upload Image via Signed URL (Priority: P1)

A user (or automated system) initiates image upload by requesting a signed URL from the server, uploads directly to GCS using that URL, and checks the final public URL.

**Why this priority**: This is the core user journey. Without it, the system cannot function. All other features depend on this working correctly.

**Independent Test**: Can be tested by:
1. Calling `/images/upload-request` with a `userId`
2. Receiving a signed URL and `guid`
3. Uploading an image to that signed URL
4. Polling `/images/status` until `completed`
5. Verifying the public URL works

**Acceptance Scenarios**:

1. **Given** a user with valid `userId`, **When** they call `POST /images/upload-request`, **Then** server responds with:
   - `signedUrl` (PUT endpoint for GCS bucket)
   - `guid` (unique identifier for this upload)
   - HTTP 200 status

2. **Given** a `signedUrl` from step 1, **When** user PUTs a valid JPEG/PNG/GIF/WebP image, **Then**:
   - File is stored in GCS upload bucket
   - Pub/Sub triggers a message to server
   - Server receives event and validates the image

3. **Given** server has validated the image, **When** client polls `/images/status` with the `guid`, **Then** server responds with:
   - `status: completed`
   - `publicUrl` pointing to processed image in public bucket
   - HTTP 200 status

---

### User Story 2 - Validate Image & Prevent Fraud (Priority: P1)

Server detects and rejects tampered or invalid images by validating size, type, and content.

**Why this priority**: Security is non-negotiable in the constitution. Malicious images or fraudulent uploads must be rejected at the server level.

**Independent Test**: Can be tested by:
1. Uploading an invalid file (wrong extension, spoofed MIME type, oversized)
2. Polling `/images/status` to confirm rejection
3. Verifying no public URL is generated

**Acceptance Scenarios**:

1. **Given** an uploaded file with:
   - Mismatched extension/MIME type (e.g., `.exe` uploaded as `image/jpeg`)
   - Oversized dimensions (> 4000x3000 pixels)
   - Wrong MIME type in headers

   **When** server processes the Pub/Sub event, **Then** server:
   - Rejects the image
   - Updates status to `failed`
   - Does NOT copy to public bucket
   - Logs the rejection event

2. **Given** a legitimate image in upload bucket, **When** server processes it:
   - Server validates actual image dimensions using image library
   - Server regenerates/converts the image to prevent embedded code
   - Server stores converted image in public bucket
   - Status updated to `completed`

---

### User Story 3 - Status Polling & URL Retrieval (Priority: P2)

Client polls server for upload status and retrieves public URL once processing completes.

**Why this priority**: High priority for user experience. Clients need to know when their upload is ready. Without this, they cannot access their images.

**Independent Test**: Can be tested by:
1. Initiating upload and obtaining `guid`
2. Polling `/images/status` multiple times
3. Verifying status transitions: `initialized` → `processing` → `completed`
4. Confirming public URL appears in final response

**Acceptance Scenarios**:

1. **Given** a `guid` from a recent upload, **When** client calls `POST /images/status` with `userId` and `guid`, **Then**:
   - **Immediate response**: `status: initialized` (record created, waiting for upload)
   - OR `status: processing` (file uploaded, validation in progress)
   - OR `status: completed` with `publicUrl`
   - OR `status: failed` (validation rejected image)

2. **Given** a `guid` for a completed upload, **When** client accesses the `publicUrl`, **Then**:
   - Image is publicly accessible (no authentication)
   - Image is the processed/converted version (not the original)

---

### Edge Cases

- What happens if client never uploads to the signed URL?
  → Record remains in `initialized` state; can be queried but no image exists
  
- What happens if Pub/Sub notification is delayed or never arrives?
  → Server never receives event; status stays in `initialized` until timeout (document TTL policy)
  
- What happens if uploaded image validation fails?
  → Status set to `failed`; no public URL generated; client must retry with valid image
  
- What happens if multiple clients upload simultaneously with same `userId`?
  → Each upload gets unique `guid`; they are processed independently
  
- What happens if a user requests status for a `guid` they did not create?
  → [NEEDS CLARIFICATION: Should userId+guid be validated for ownership, or is guid sufficient as a secret?]

---

## Requirements *(mandatory)*

### Functional Requirements

#### Client Requirements
- **FR-101**: Client application MUST be a standalone bash script executable with `bash upload-request.sh`
- **FR-102**: Client script MUST accept parameters for `userId` and image file path
- **FR-103**: Client script MUST call server `POST /images/upload-request` endpoint
- **FR-104**: Client script MUST parse JSON response to extract `signedUrl` and `guid`
- **FR-105**: Client script MUST upload image to `signedUrl` using curl with PUT method
- **FR-106**: Client MUST poll `POST /images/status` endpoint until status is `completed` or `failed`
- **FR-107**: Client script MUST display final public URL or error message

#### Server: `/images/upload-request` Endpoint
- **FR-201**: Server MUST accept `POST /images/upload-request` with required parameter `userId`
- **FR-202**: Server MUST generate globally unique `guid` (no collisions)
- **FR-203**: Server MUST persist guid record with:
  - `guid`: unique identifier
  - `userId`: requester identifier
  - `status`: initialized (initial state)
  - `createdAt`: timestamp
- **FR-204**: Server MUST generate a signed URL for GCS upload bucket with PUT method only
- **FR-205**: Server MUST set signed URL expiration to reasonable time (suggest 1 hour)
- **FR-206**: Server MUST return JSON: `{ "signedUrl": "...", "guid": "..." }`
- **FR-207**: Server MUST NOT allow GET method on signed URL (PUT only)

#### Server: `POST /image-event` Endpoint (Pub/Sub Callback)
- **FR-301**: Server MUST accept Pub/Sub push requests at `POST /image-event`
- **FR-302**: Server MUST extract GCS object metadata (path, bucket, name, size, contentType)
- **FR-303**: Server MUST verify image MIME type against whitelist: `image/jpeg`, `image/png`, `image/gif`, `image/webp`
- **FR-304**: Server MUST validate image dimensions:
  - Maximum: 4000×3000 pixels
  - File size: ≤ 5MB
- **FR-305**: Server MUST regenerate/convert image using ImageMagick or equivalent:
  - Purpose: Remove any embedded code or metadata that could be exploited
  - Output format: Same as input format
  - Output quality: Lossy compression acceptable for lossy formats
- **FR-306**: If validation fails, server MUST:
  - Set status to `failed`
  - Log rejection reason
  - NOT copy to public bucket
- **FR-307**: If validation succeeds, server MUST:
  - Copy converted image to public GCS bucket (bucket 2)
  - Preserve content type header
  - Generate permanent public URL
  - Update guid record: `status: completed`, `publicUrl: <url>`
  - Log successful processing
- **FR-308**: Server MUST handle Pub/Sub acknowledgment (return 200 OK after processing)

#### Server: `POST /images/status` Endpoint
- **FR-401**: Server MUST accept `POST /images/status` with parameters: `userId`, `guid`
- **FR-402**: Server MUST retrieve guid record from persistent storage
- **FR-403**: If record not found, server MUST return HTTP 404
- **FR-404**: If status is `initialized` or `processing`, return: `{ "status": "..." }`
- **FR-405**: If status is `completed`, return: `{ "status": "completed", "publicUrl": "..." }`
- **FR-406**: If status is `failed`, return: `{ "status": "failed" }`
- **FR-407**: Server MUST NOT expose reasons for failure in API response (log separately)

#### Infrastructure & Storage
- **FR-501**: GCS bucket 1 (upload): Configured to publish messages to Pub/Sub on object creation
- **FR-502**: GCS bucket 2 (public): Public read access; content served with correct MIME types
- **FR-503**: Pub/Sub topic: Push subscription configured to send events to server `/image-event` endpoint
- **FR-504**: Server MUST store guid records persistently (suggest local file-based store for demo)
- **FR-505**: Terraform code MUST create all resources as modular components

### Key Entities

- **UploadRecord**:
  - `guid`: string (unique identifier, e.g., UUID v4)
  - `userId`: string (requester identifier)
  - `status`: enum (`initialized`, `processing`, `completed`, `failed`)
  - `originalFilename`: string (optional, for logging)
  - `publicUrl`: string or null (URL to processed image in bucket 2)
  - `createdAt`: timestamp
  - `processedAt`: timestamp (when validation completed)
  - `errorMessage`: string or null (if status is `failed`)

- **ImageValidation**:
  - `mimeType`: string (detected from file content)
  - `dimensions`: object `{ width: int, height: int }`
  - `fileSize`: int (bytes)
  - `isValid`: boolean
  - `conversionApplied`: boolean (image regenerated)

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Client script executes successfully with syntax validation (bash -n): 0 errors
- **SC-002**: Server accepts upload-request and returns signed URL within 500ms
- **SC-003**: Valid image uploaded via signed URL is processed and public URL available within 5 seconds of Pub/Sub notification
- **SC-004**: Invalid/oversized images are rejected and status correctly reports `failed`
- **SC-005**: 100% of test scenarios in User Scenarios pass with no security violations
- **SC-006**: Image conversion removes all EXIF metadata (verified via `identify` or `exiftool`)
- **SC-007**: Public bucket images are readable via HTTP (no authentication required)
- **SC-008**: Upload bucket is NOT publicly readable (signed URL required)
- **SC-009**: Terraform applies successfully and all resources created in GCP
- **SC-010**: Code coverage of PHP business logic > 80% (validation, conversion, storage)
- **SC-011**: All API responses include appropriate HTTP status codes (200, 404, 400, 500)
- **SC-012**: Performance: Signed URL generation < 500ms; status check < 200ms

---

## Assumptions

Based on the provided requirements, the following reasonable defaults are assumed:

1. **Authentication**: The `userId` parameter is a trusted identifier (no authentication layer assumed for this demo). Production would add OAuth2/JWT.
2. **Storage Duration**: Upload bucket files are deleted after 24 hours (to prevent storage waste); public bucket images remain indefinitely.
3. **Image Dimensions**: Reasonable maximum of 4000×3000 pixels. Smaller limit (e.g., 2000×2000) is acceptable.
4. **Conversion Tool**: ImageMagick or similar (convert, identify) available in container environment.
5. **File Size Limit**: 5MB chosen as reasonable for demo; smaller or larger acceptable based on use case.
6. **Pub/Sub Push**: Server endpoint must be publicly accessible with HTTPS for Pub/Sub to deliver events.
7. **Error Handling**: Generic error messages returned to API; detailed logs kept server-side for debugging.
8. **Database**: Local file storage (JSON or similar) for demo; production would use Firestore or BigQuery.
9. **Concurrency**: Assumed reasonable concurrency (< 100 concurrent uploads); no distributed lock required.
10. **GUID Format**: UUID v4 or equivalent; no sequential IDs (security practice).

---

## Security Considerations

Per project constitution (Security by Default, Non-Negotiable):

- ✅ **Signed URLs**: Short-lived, PUT-only URLs issued by server
- ✅ **MIME Type Validation**: Verified against whitelist and actual file content
- ✅ **Image Conversion**: Eliminates embedded exploits, EXIF metadata
- ✅ **File Size Limits**: Enforced to prevent DoS
- ✅ **No Direct GCS Access**: Public bucket via signed URLs only; upload bucket not publicly readable
- ✅ **Logging**: All uploads logged with timestamp, userId, status
- ⚠️  **Guid Ownership**: [NEEDS CLARIFICATION: Should userId + guid match be verified?]
- ✅ **Error Messages**: Generic to prevent information disclosure

---

## Deliverables Summary

### Client Deliverables
- `clients/upload-request.sh`: Bash script to request signed URL and upload image
- `clients/status.sh`: Bash script to poll upload status

### Server Deliverables (PHP)
- `src/Controllers/ImageUploadController.php`: Handles `/images/upload-request`
- `src/Controllers/ImageEventController.php`: Handles `/image-event` (Pub/Sub callback)
- `src/Controllers/ImageStatusController.php`: Handles `/images/status`
- `src/Services/ImageValidationService.php`: Validates image type, size, dimensions
- `src/Services/GcsService.php`: Interface for GCS operations (signing URLs, uploading, copying)
- `src/Services/StorageService.php`: Persists upload records (local file-based)
- `src/Models/UploadRecord.php`: Data model for uploads

### Infrastructure Deliverables
- `terraform/modules/gcs/`: GCS buckets (upload + public)
- `terraform/modules/pubsub/`: Pub/Sub topic and push subscription
- `terraform/env/dev/`: Development environment configuration
- `terraform/env/prod/`: Production environment configuration

### Docker Deliverables
- `Dockerfile`: PHP-FPM with Slim framework, ImageMagick
- `docker-compose.yml`: Local development environment

### Documentation Deliverables
- `README.md`: Overall architecture and setup
- `docs/ARCHITECTURE.md`: System design and data flow
- `docs/API.md`: API endpoint documentation
- `docs/TESTING.md`: Testing procedures and example requests

---

## Next Steps (Planning Phase)

1. **Clarification**: Address [NEEDS CLARIFICATION] item (guid ownership)
2. **Planning**: Design data model, API contracts, GCS bucket structure
3. **Implementation**: Start with client scripts, then server endpoints, then infrastructure
4. **Testing**: Unit tests, integration tests, security validation

### User Story 3 - [Brief Title] (Priority: P3)

[Describe this user journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

[Add more user stories as needed, each with an assigned priority]

### Edge Cases

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right edge cases.
-->

- What happens when [boundary condition]?
- How does system handle [error scenario]?

## Requirements *(mandatory)*

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right functional requirements.
-->

### Functional Requirements

- **FR-001**: System MUST [specific capability, e.g., "allow users to create accounts"]
- **FR-002**: System MUST [specific capability, e.g., "validate email addresses"]  
- **FR-003**: Users MUST be able to [key interaction, e.g., "reset their password"]
- **FR-004**: System MUST [data requirement, e.g., "persist user preferences"]
- **FR-005**: System MUST [behavior, e.g., "log all security events"]

*Example of marking unclear requirements:*

- **FR-006**: System MUST authenticate users via [NEEDS CLARIFICATION: auth method not specified - email/password, SSO, OAuth?]
- **FR-007**: System MUST retain user data for [NEEDS CLARIFICATION: retention period not specified]

### Key Entities *(include if feature involves data)*

- **[Entity 1]**: [What it represents, key attributes without implementation]
- **[Entity 2]**: [What it represents, relationships to other entities]

## Success Criteria *(mandatory)*

<!--
  ACTION REQUIRED: Define measurable success criteria.
  These must be technology-agnostic and measurable.
-->

### Measurable Outcomes

- **SC-001**: [Measurable metric, e.g., "Users can complete account creation in under 2 minutes"]
- **SC-002**: [Measurable metric, e.g., "System handles 1000 concurrent users without degradation"]
- **SC-003**: [User satisfaction metric, e.g., "90% of users successfully complete primary task on first attempt"]
- **SC-004**: [Business metric, e.g., "Reduce support tickets related to [X] by 50%"]
