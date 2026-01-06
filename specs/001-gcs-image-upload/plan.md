# Implementation Plan: GCS Image Upload Demo

**Branch**: `001-gcs-image-upload` | **Date**: 2026-01-06 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/001-gcs-image-upload/spec.md`

## Summary

Build a secure, two-stage image upload system with signed URLs. Client (bash/curl) requests upload permission from PHP Slim server, receives a signed URL for direct GCS upload, server validates image via Pub/Sub events, converts image to prevent embedded exploits, and returns public URL. Architecture separates concerns: client initiates, server validates & controls, GCS handles storage. Aligns with project constitution: human-readable code, security by default (non-negotiable), TDD, secure file handling.

---

## Technical Context

**Language/Version**: PHP 8.0+ (Slim Framework 4.x)  
**Primary Dependencies**: 
  - PHP Slim 4 (routing, middleware)
  - Google Cloud Storage PHP client
  - Google Cloud Pub/Sub PHP client
  - ImageMagick (image validation/conversion)
  - UUID library (guid generation)

**Storage**: 
  - GCS bucket 1 (upload): transient, auto-delete after 24h
  - GCS bucket 2 (public): permanent, public read
  - Local JSON file store for upload records (demo simplicity)

**Testing**: PHPUnit 9+ (unit + integration tests), >80% code coverage required

**Target Platform**: Linux server (Docker container), deployed to Google Cloud Run or similar

**Project Type**: Backend HTTP service (REST API only) + client scripts; no frontend UI

**Performance Goals**:
  - Signed URL generation: < 500ms
  - Status check: < 200ms
  - Image processing (validation + conversion): < 5 seconds
  - Concurrent users: < 100 (demo scale)

**Constraints**:
  - Image dimensions max: 4000×3000 pixels
  - File size max: 5MB
  - Signed URL expiration: 1 hour
  - Pub/Sub notification: must arrive within 30 seconds

**Scale/Scope**: 
  - Single backend service (no microservices)
  - 3 API endpoints (upload-request, image-event, status)
  - 2 client scripts (bash)
  - ~5K-7K LOC expected (PHP business logic)

---

## Constitution Check

**Reference**: [.specify/memory/constitution.md](../../.specify/memory/constitution.md)

### Gate 1: Human-Readable Code First ✅
- **Requirement**: Every line clear; comments explain "why"; no complexity without justification
- **Status**: PASS - Enforced by DEVELOPMENT.md guidelines
- **Evidence**: Project has DEVELOPMENT.md with code readability checklist, naming conventions, comment standards

### Gate 2: Security by Default (Non-Negotiable) ✅
- **Requirement**: Input validation, MIME type verification, signed URLs, secure logging
- **Status**: PASS - Fully implemented in design
- **Evidence**: 
  - Signed URLs for upload (short-lived, PUT-only)
  - MIME type whitelist + magic bytes validation
  - Image conversion (removes EXIF, embedded code)
  - Error messages generic (no info disclosure)
  - Logging all security events (uploads, validations, rejections)

### Gate 3: Test-Driven Development (TDD) ✅
- **Requirement**: Tests before implementation; >80% coverage
- **Status**: PASS - Built into implementation plan
- **Evidence**: tasks.md will include unit + integration tests as first phase

### Gate 4: Secure File Upload Handling ✅
- **Requirement**: Validate type/size/MIME; store outside root; rate limit; log; prevent traversal
- **Status**: PASS - Core to design
- **Evidence**:
  - Type validation in ImageValidationService
  - Size limits enforced (5MB)
  - Stored in GCS (outside app root)
  - No directory traversal (random hash, no user-provided names)
  - All uploads logged

### Gate 5: Simple, Focused Scope ✅
- **Requirement**: Core functionality only; YAGNI; clear separation of concerns
- **Status**: PASS - Architecture is minimal
- **Evidence**:
  - 3 endpoints only (no extras)
  - 7 PHP classes planned (focused responsibilities)
  - No user authentication, no databases, no UI
  - Clear: controllers → services → GCS

**Overall Constitution Status**: ✅ **PASS - All gates satisfied**

### Complexity Justifications

No violations requiring justification. Architecture is minimal and focused.

---

## Project Structure

### Documentation (this feature)

```text
specs/001-gcs-image-upload/
├── spec.md                  # Feature specification ✓
├── plan.md                  # This file (Phase 1 output)
├── research.md              # Phase 0 output (TBD)
├── data-model.md            # Phase 1 output (TBD)
├── quickstart.md            # Phase 1 output (TBD)
├── contracts/               # Phase 1 output (TBD)
│   ├── upload-request-api.yaml
│   ├── image-event-api.yaml
│   └── status-api.yaml
└── checklists/requirements.md
```

### Source Code (repository root)

```text
clients/
├── upload-request.sh        # Client: Request signed URL and upload
└── status.sh                # Client: Poll upload status

src/
├── Controllers/
│   ├── ImageUploadController.php      # POST /images/upload-request
│   ├── ImageEventController.php       # POST /image-event (Pub/Sub)
│   └── ImageStatusController.php      # POST /images/status
├── Services/
│   ├── ImageValidationService.php     # Validate image type/size/dimensions
│   ├── GcsService.php                 # Interface for GCS operations
│   ├── ImageConversionService.php     # Convert image (remove metadata)
│   └── StorageService.php             # Persist upload records
├── Models/
│   ├── UploadRecord.php               # Data model for uploads
│   └── ImageValidation.php            # Validation result model
└── App.php                            # Slim app bootstrap, routes

tests/
├── Unit/
│   ├── ImageValidationServiceTest.php
│   ├── ImageConversionServiceTest.php
│   └── StorageServiceTest.php
└── Integration/
    ├── UploadRequestEndpointTest.php
    ├── ImageEventEndpointTest.php
    └── StatusEndpointTest.php

storage/
├── uploads/                 # Upload records (JSON files)
└── logs/                    # Application logs

terraform/
├── modules/
│   ├── gcs/
│   │   ├── main.tf          # GCS buckets (upload + public)
│   │   ├── variables.tf
│   │   └── outputs.tf
│   └── pubsub/
│       ├── main.tf          # Pub/Sub topic + push subscription
│       ├── variables.tf
│       └── outputs.tf
├── env/
│   ├── dev/
│   │   ├── main.tf
│   │   ├── terraform.tfvars
│   │   └── backend.tf
│   └── prod/
│       ├── main.tf
│       ├── terraform.tfvars
│       └── backend.tf
└── shared/
    ├── variables.tf
    └── outputs.tf

docker/
├── Dockerfile               # PHP 8.0 + Slim + ImageMagick
└── docker-compose.yml       # Local development

docs/
├── ARCHITECTURE.md          # System design, data flow
├── API.md                   # Endpoint documentation
└── TESTING.md               # Testing procedures, examples
```

### Structure Decision

**Selected**: Option 2 (Backend + Client Scripts) - Modified

- **Backend**: PHP Slim service with clear separation (Controllers → Services → GCS)
- **Clients**: Standalone bash scripts (no client library)
- **Infrastructure**: Terraform modules for GCS + Pub/Sub
- **Storage**: Local JSON for demo simplicity (production would use Firestore)

Rationale:
- PHP Slim provides minimal HTTP server (lightweight demo)
- Service layer abstracts GCS (interface for testability)
- Bash clients avoid external dependencies
- Terraform enables reproducible cloud setup
- Local JSON storage keeps demo self-contained

---

## Phase 0 (Research) - Unknowns to Resolve

These items require investigation before detailed design:

1. **Pub/Sub Push Authentication** 
   - How to verify Pub/Sub messages are authentic?
   - Solution: Pub/Sub includes auth token; server verifies

2. **GCS Signed URL Signing Key**
   - Which GCP service account credentials to use?
   - Solution: Use gcp service account with Storage Object Creator role

3. **Image Conversion Library**
   - ImageMagick available in Docker container?
   - Solution: PHP extension pecl/imagick or command-line `/usr/bin/convert`

4. **Upload Record Storage**
   - JSON file location, permissions, cleanup?
   - Solution: `storage/uploads/*.json`, cron job for cleanup, or in-memory for demo

5. **GUID Format & Collision Risk**
   - UUID v4 adequate, or need database?
   - Solution: UUID v4 + Ramsey UUID library (collision risk negligible)

6. **Error Handling & Logging**
   - Where to log (stdout, file, Cloud Logging)?
   - Solution: `error_log()` to stdout for Docker, Cloud Logging in production

---

## Phase 1 (Design) - Detailed Deliverables

### 1a. Data Model (data-model.md) - TBD

Will define:
- **UploadRecord**: guid, userId, status, publicUrl, timestamps, errorMessage
- **ImageValidation**: mimeType, dimensions, fileSize, isValid, conversionApplied
- **Status enum**: initialized, processing, completed, failed

### 1b. API Contracts (contracts/*.yaml) - TBD

Will define:
- **POST /images/upload-request**
  - Input: `{ "userId": "string" }`
  - Output: `{ "signedUrl": "string", "guid": "string" }`
  
- **POST /image-event** (Pub/Sub)
  - Input: Pub/Sub envelope with GCS object metadata
  - Output: `{ "status": "processing|completed|failed" }`
  
- **POST /images/status**
  - Input: `{ "userId": "string", "guid": "string" }`
  - Output: `{ "status": "...", "publicUrl": "..." (optional) }`

### 1c. Quick Start (quickstart.md) - TBD

Will include:
- Setup: Install PHP, Composer, Docker
- Run locally: `docker-compose up`
- Test client: `bash clients/upload-request.sh user-123 test.jpg`
- Monitor: Check storage/uploads/, logs/

### 1d. Agent Context Update - TBD

Will update `.vscode/settings.json` or similar with:
- PHP 8.0 language server settings
- Slim Framework snippets
- Google Cloud SDK setup

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|-----------|
| Pub/Sub delivery delay | Low | Medium | Document TTL, add status polling |
| ImageMagick unavailable | Low | High | Fallback to PHP GD, or external service |
| GCS quota exceeded | Low | Low | Monitor usage, set alerts |
| Timing race condition | Medium | Low | Atomic status updates, database lock |
| Large image processing | Low | Medium | Queue async processing (stretch goal) |

---

## Next Steps (After This Plan)

### Phase 0: Research (TBD)
- Resolve unknowns above → **research.md**
- Verify GCP service account setup
- Test Pub/Sub push authentication
- Document ImageMagick availability

### Phase 1: Design (TBD)
- Finalize **data-model.md** based on research
- Create API contracts in **contracts/***
- Generate **quickstart.md** with step-by-step setup
- Update agent context for IDE support

### Phase 2: Implementation (via /speckit.tasks)
- Use `/speckit.tasks` to generate detailed implementation tasks
- Implement TDD: tests first, then code
- Follow DEVELOPMENT.md and SECURITY.md guidelines
- Achieve >80% code coverage

---

## Dependencies & Prerequisites

Before implementation:
1. ✅ Feature specification complete (spec.md)
2. ✅ Project constitution defined (constitution.md)
3. ✅ Development guidance available (DEVELOPMENT.md, SECURITY.md)
4. ⬜ GCP project setup (manual step before terraform)
5. ⬜ Service account credentials (manual setup)
6. ⬜ PHP 8.0+ environment (Docker or local)
7. ⬜ ImageMagick installed

---

## Success Definition

Plan is successful when:
- ✅ All NEEDS CLARIFICATION items resolved → research.md
- ✅ Data model specified → data-model.md
- ✅ All API contracts defined → contracts/*.yaml
- ✅ Quick start guide created → quickstart.md
- ✅ Implementation tasks generated → tasks.md (via /speckit.tasks)
- ✅ Constitution Check re-evaluated and passed

---

**Status**: 🟡 **In Progress** - Awaiting Phase 0 Research output
