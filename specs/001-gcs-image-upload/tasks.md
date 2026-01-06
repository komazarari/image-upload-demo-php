# Implementation Tasks: GCS Image Upload Demo

**Feature**: `001-gcs-image-upload`  
**Created**: 2026-01-06  
**Status**: Ready for execution  
**Branch**: `001-gcs-image-upload`  

---

## Overview

This document defines all implementation tasks organized by phase and user story. Tasks are ordered to enable parallel work where dependencies allow.

### User Stories & Coverage

- **US1** (P1): Upload Image via Signed URL – 12 tasks
- **US2** (P1): Validate Image & Prevent Fraud – 8 tasks
- **US3** (P2): Status Polling & URL Retrieval – 4 tasks
- **Infrastructure & Polish** – 10 tasks

**Total Tasks**: 34  
**Estimated Duration**: 3-4 weeks (1 developer)  
**Parallel Opportunities**: 8+ tasks can execute simultaneously after foundational setup

---

## Phase 1: Setup & Project Initialization

Initialize project structure, dependencies, testing framework, and Docker environment.

**Goal**: Project boots successfully with working Composer dependency manager, PHP 8.0+ with required extensions, test runner, and development environment.

**Independent Test Criteria**:
- ✅ `composer install` succeeds with zero errors
- ✅ `php -v` reports 8.0+
- ✅ `phpunit --version` reports installed
- ✅ `docker-compose up` runs without errors (if using Docker)
- ✅ `php -S localhost:8000` starts HTTP server

### Tasks

- [ ] T001 Create project directory structure per plan.md in `src/`, `tests/`, `storage/`, `terraform/`, `docker/`
- [ ] T002 Create `composer.json` with dependencies: slim/slim, google/cloud-storage, google/cloud-pubsub, imagick, ramsey/uuid, phpunit/phpunit
- [ ] T003 Run `composer install` and generate `composer.lock`
- [ ] T004 Create `.gitignore` (vendor/, storage/uploads/*.json, .env, .DS_Store)
- [ ] T005 Create `docker/Dockerfile` with PHP 8.0, Slim 4, ImageMagick, Imagick PHP extension
- [ ] T006 Create `docker/docker-compose.yml` with PHP service mapping port 8000 and volume mounts
- [ ] T007 Create `src/App.php` (Slim application bootstrap with middleware configuration)
- [ ] T008 Create `phpunit.xml` configuration file with test directory and coverage settings
- [ ] T009 Create test bootstrap file `tests/bootstrap.php` with autoloading
- [ ] T010 Create `storage/uploads/` directory with `.gitkeep` file
- [ ] T011 Create `storage/logs/` directory with `.gitkeep` file
- [ ] T012 Test: Run `docker-compose up` and verify HTTP server responds on localhost:8000

---

## Phase 2: Foundational Infrastructure & Services

Build core service layer and data models that all user stories depend on.

**Goal**: Service layer abstractions ready, data models defined, storage interface available for all controllers to use.

**Independent Test Criteria**:
- ✅ `ImageValidationService` unit tests pass (>90% coverage)
- ✅ `StorageService` unit tests pass for JSON persistence
- ✅ `GcsService` interface defined (mocked in tests)
- ✅ `UploadRecord` model instantiates with required fields
- ✅ All unit tests combined: >80% coverage

### Tasks

- [ ] T013 [P] Create `src/Models/UploadRecord.php` with properties: guid, userId, status, originalFilename, publicUrl, createdAt, processedAt, errorMessage, signedUrl, contentType, fileSize, imageDimensions
- [ ] T014 [P] Create `src/Models/ImageValidation.php` with properties: isValid, mimeType, width, height, fileSize, errors, conversionApplied, conversionErrors
- [ ] T015 Create `src/Services/ImageValidationService.php` with methods: validateMimeType(), validateDimensions(), validateFileSize(), validate() (combined)
- [ ] T016 [P] Create `tests/Unit/ImageValidationServiceTest.php` with test cases for all validation rules (MIME whitelist, dimension limits, file size limits)
- [ ] T017 Create `src/Services/GcsService.php` (interface/abstract class) with methods: generateSignedUrl(), copyObject(), deleteObject()
- [ ] T018 [P] Create `src/Services/ImageConversionService.php` with method: convertImage() using Imagick to strip EXIF and re-encode
- [ ] T019 Create `src/Services/StorageService.php` with methods: save(UploadRecord), load(guid), update(guid, status), exists(guid)
- [ ] T020 [P] Create `tests/Unit/ImageConversionServiceTest.php` with test cases for image conversion (format preservation, quality, metadata removal)
- [ ] T021 Create `tests/Unit/StorageServiceTest.php` with test cases for JSON file persistence (save, load, update)
- [ ] T022 Create `.env.example` with required variables: GCS_PROJECT_ID, GCS_UPLOAD_BUCKET, GCS_PUBLIC_BUCKET, PUBSUB_TOPIC, SIGNED_URL_EXPIRY, MAX_FILE_SIZE, MAX_IMAGE_DIMENSIONS

---

## Phase 3: User Story 1 – Upload Image via Signed URL

Implement client script and server endpoint for requesting upload permission and generating signed URLs.

**Story Goal**: User can request signed URL from server and receive unique upload identifier (guid).

**Independent Test Criteria**:
- ✅ `POST /images/upload-request` responds with HTTP 200
- ✅ Response includes valid UUID v4 `guid`
- ✅ Response includes valid signed URL (format verified)
- ✅ Signed URL expires in 1 hour (TTL verified)
- ✅ bash script `upload-request.sh` executes without errors
- ✅ bash script parses JSON response correctly
- ✅ curl PUT to signed URL succeeds (integration test with mock GCS)

### Tasks

- [ ] T023 [P] [US1] Create `src/Controllers/ImageUploadController.php` with method: `uploadRequest(Request, Response)` that generates guid and calls GcsService::generateSignedUrl()
- [ ] T024 [US1] Implement guid generation in ImageUploadController using `Ramsey\Uuid\Uuid::uuid4()`
- [ ] T025 [US1] Create `UploadRecord` instance in uploadRequest() and persist via StorageService::save()
- [ ] T026 [US1] Add route `POST /images/upload-request` in `src/App.php` mapped to ImageUploadController::uploadRequest()
- [ ] T027 [P] [US1] Create `tests/Integration/UploadRequestEndpointTest.php` with test cases: valid request, missing userId, signature verification
- [ ] T028 [P] [US1] Create `clients/upload-request.sh` bash script that: accepts userId and image filepath, calls POST /images/upload-request, parses JSON, uploads to signedUrl
- [ ] T029 [US1] Implement GCS signed URL generation in `src/Services/GcsService.php` using Google Cloud Storage client (service account auth)
- [ ] T030 [US1] Test: `bash clients/upload-request.sh user123 test.jpg` uploads successfully to signed URL
- [ ] T031 [P] [US1] Mock GcsService for ImageUploadController tests (no real GCS calls during testing)
- [ ] T032 [US1] Verify uploadRequest() response includes: guid (UUID format), signedUrl (https, GCS path), expiresAt (1 hour from now)

---

## Phase 4: User Story 2 – Validate Image & Prevent Fraud

Implement server endpoint to receive Pub/Sub notifications and validate uploaded images.

**Story Goal**: Server validates image MIME type, dimensions, file size, and content; rejects invalid images; converts valid images to safe format.

**Independent Test Criteria**:
- ✅ `POST /image-event` with valid Pub/Sub payload processes successfully
- ✅ Invalid MIME type rejected (status → failed)
- ✅ Oversized image rejected (status → failed)
- ✅ Valid image converted (EXIF removed, metadata stripped)
- ✅ Converted image copied to public bucket
- ✅ UploadRecord status transitions: initialized → processing → completed/failed
- ✅ Integration test: Upload JPEG, receive Pub/Sub event, verify conversion in public bucket

### Tasks

- [ ] T033 [P] [US2] Create `src/Controllers/ImageEventController.php` with method: `handlePubSubEvent(Request, Response)` to receive and validate Pub/Sub messages
- [ ] T034 [US2] Implement Pub/Sub authentication in ImageEventController using OIDC token validation (Google Cloud PHP library)
- [ ] T035 [US2] Extract GCS metadata (bucket, object name, size, contentType) from Pub/Sub message payload in ImageEventController
- [ ] T036 [US2] Add route `POST /image-event` in `src/App.php` mapped to ImageEventController::handlePubSubEvent()
- [ ] T037 [P] [US2] Create `tests/Integration/ImageEventEndpointTest.php` with test cases: valid Pub/Sub payload, invalid auth token, malformed event
- [ ] T038 [P] [US2] Implement validation workflow in ImageEventController: validate MIME → validate dimensions → validate fileSize → convert image → copy to public bucket
- [ ] T039 [US2] Integrate ImageValidationService into ImageEventController for MIME, dimension, and size validation
- [ ] T040 [US2] Integrate ImageConversionService into ImageEventController to convert image using Imagick (strip metadata, re-encode)
- [ ] T041 [US2] If validation fails: set UploadRecord status to `failed`, log rejection reason, do NOT copy to public bucket
- [ ] T042 [US2] If validation succeeds: copy converted image to public bucket via GcsService::copyObject(), set status to `completed`, generate publicUrl, update UploadRecord
- [ ] T043 [US2] Test: Upload invalid JPEG (wrong extension, spoofed MIME), receive Pub/Sub event, verify status = failed
- [ ] T044 [P] [US2] Test: Upload valid PNG, receive Pub/Sub event, verify conversion (no EXIF), verify status = completed

---

## Phase 5: User Story 3 – Status Polling & URL Retrieval

Implement server endpoint for clients to poll upload status and retrieve public URLs.

**Story Goal**: Client can poll server for upload status and retrieve public URL when processing completes.

**Independent Test Criteria**:
- ✅ `POST /images/status` returns correct status (initialized, processing, completed, failed)
- ✅ Completed status includes publicUrl
- ✅ Failed status does NOT include publicUrl
- ✅ Missing guid returns HTTP 404
- ✅ bash script `status.sh` polls successfully and displays URL or error

### Tasks

- [ ] T045 [P] [US3] Create `src/Controllers/ImageStatusController.php` with method: `getStatus(Request, Response)` that retrieves UploadRecord and returns status
- [ ] T046 [US3] Implement UploadRecord retrieval via StorageService::load(guid) in ImageStatusController
- [ ] T047 [US3] Add route `POST /images/status` in `src/App.php` mapped to ImageStatusController::getStatus()
- [ ] T048 [P] [US3] Create `tests/Integration/StatusEndpointTest.php` with test cases: initialized status, processing status, completed with publicUrl, failed status, missing guid (404)
- [ ] T049 [P] [US3] Create `clients/status.sh` bash script that: accepts userId and guid, polls POST /images/status, displays status and publicUrl (if completed), retries with backoff
- [ ] T050 [US3] Implement response formatting: if completed, include publicUrl; if failed, do NOT include reason (generic "failed" only)
- [ ] T051 [US3] Test: `bash clients/status.sh user123 <guid>` returns status and publicUrl after image processing completes
- [ ] T052 [P] [US3] Mock StorageService for ImageStatusController tests (no file I/O during testing)

---

## Phase 6: Infrastructure & Deployment

Create Terraform modules for cloud resources and Docker configuration for local development.

**Goal**: Reproducible infrastructure as code for GCS buckets, Pub/Sub topic/subscription, and containerized local development environment.

**Independent Test Criteria**:
- ✅ `terraform plan` in `terraform/env/dev` shows expected resources
- ✅ `terraform apply` creates GCS buckets and Pub/Sub resources
- ✅ GCS upload bucket NOT publicly readable
- ✅ GCS public bucket IS publicly readable
- ✅ Pub/Sub push subscription configured to POST to server /image-event endpoint
- ✅ docker-compose up starts PHP server + volume mounts

### Tasks

- [ ] T053 [P] Create `terraform/modules/gcs/main.tf` defining two GCS buckets: upload (transient, not public) and public (public read)
- [ ] T054 [P] Create `terraform/modules/gcs/variables.tf` and `terraform/modules/gcs/outputs.tf` with bucket names, regions, storage class
- [ ] T055 Create `terraform/modules/pubsub/main.tf` defining Pub/Sub topic and push subscription (callback URL configurable)
- [ ] T056 Create `terraform/modules/pubsub/variables.tf` and `terraform/modules/pubsub/outputs.tf` with topic name, subscription name, push endpoint
- [ ] T057 Create `terraform/env/dev/main.tf` that calls gcs and pubsub modules with dev values
- [ ] T058 Create `terraform/env/dev/terraform.tfvars` with dev configuration (bucket names, project ID, region)
- [ ] T059 Create `terraform/shared/variables.tf` with common variables (project_id, region, environment)
- [ ] T060 [P] Create `terraform/env/prod/main.tf` and `terraform/env/prod/terraform.tfvars` for production environment (separate buckets, monitoring)
- [ ] T061 [P] Update `docker/docker-compose.yml` to include: PHP service, volume mounts (src/, storage/), port mapping, environment variables from .env
- [ ] T062 Test: `terraform -chdir=terraform/env/dev plan` validates configuration, `terraform apply` creates resources

---

## Phase 7: Documentation & Integration Testing

Complete documentation and verify end-to-end workflows.

**Goal**: All features documented, integration tests pass, manual testing checklist complete.

**Independent Test Criteria**:
- ✅ All code functions documented with purpose and parameters
- ✅ docs/ARCHITECTURE.md describes complete data flow
- ✅ docs/API.md documents all 3 endpoints with examples
- ✅ docs/TESTING.md covers unit, integration, and manual testing
- ✅ End-to-end test: bash script → signed URL → GCS upload → Pub/Sub → validation → public URL → status poll succeeds
- ✅ Security checklist from SECURITY.md passes all items
- ✅ Code coverage report shows >80% coverage

### Tasks

- [ ] T063 [P] Create `docs/ARCHITECTURE.md` explaining system design: client, server, GCS, Pub/Sub, data flow diagrams
- [ ] T064 [P] Create `docs/API.md` with full OpenAPI specification for all 3 endpoints (request/response examples)
- [ ] T065 Create `docs/TESTING.md` with unit test examples, integration test procedures, manual testing checklist
- [ ] T066 Generate code coverage report: `phpunit --coverage-html coverage/ tests/`
- [ ] T067 [P] Create end-to-end test script `tests/e2e-test.sh` that: calls upload-request, uploads image, polls status until complete, verifies public URL
- [ ] T068 Run all unit tests and verify >80% coverage target: `phpunit tests/Unit/ --coverage-percent`
- [ ] T069 Run all integration tests against running server: `phpunit tests/Integration/`
- [ ] T070 Execute security checklist from docs/SECURITY.md (input validation, error handling, logging, authentication)
- [ ] T071 [P] Create troubleshooting guide in quickstart.md for common issues (ImageMagick missing, GCS auth errors, Pub/Sub delays)
- [ ] T072 Final commit: "feat: complete GCS image upload implementation with tests and documentation"

---

## Dependencies & Execution Order

### Critical Path (Must Complete in Order)

1. **T001-T012**: Setup (no dependencies)
2. **T013-T022**: Foundational Services (depends on Setup)
3. **T023-T044**: Controllers & Business Logic (depends on Setup + Foundational)
4. **T045-T072**: Infrastructure & Documentation (can start after T012, completes in parallel)

### Parallel Execution Opportunities

**After T012 (Setup complete)**:
- **Group A** (T013-T022): Models & Services → can execute in parallel
  - T013, T014: Models (parallel)
  - T015, T016: ImageValidationService (dependent)
  - T017, T018: GcsService, ImageConversionService (parallel)
  - T019, T020, T021: StorageService, conversion tests (parallel)

**After T022 (Services complete)**:
- **Group B** (T023-T044): User Story Controllers → can execute in parallel
  - **US1 tasks** (T023-T032): UploadRequest controller → can start immediately
  - **US2 tasks** (T033-T044): ImageEvent controller → can start immediately (independent)
  - Tests (T027, T037, T048): Integration tests → parallel during controller implementation

**After T044 (Business logic complete)**:
- **Group C** (T045-T072): Status controller + Infrastructure → can execute in parallel
  - T045-T052: Status controller → fast (small controller)
  - T053-T062: Terraform → independent of application code
  - T063-T072: Documentation & E2E testing → final integration

### Recommended Team Distribution (1 developer)

**Week 1**: T001-T022 (Setup + Foundational)
- Days 1-2: T001-T012 (Setup)
- Days 3-5: T013-T022 (Services, parallel where possible)

**Week 2**: T023-T044 (User Stories 1 & 2)
- Days 1-3: T023-T032 (US1: UploadRequest)
- Days 4-5: T033-T044 (US2: Validation)

**Week 3**: T045-T062 (User Story 3 + Infrastructure)
- Days 1-2: T045-T052 (US3: Status polling)
- Days 3-5: T053-T062 (Terraform/Docker)

**Week 4**: T063-T072 (Documentation + Testing)
- Days 1-3: T063-T070 (Docs + coverage verification)
- Days 4-5: T071-T072 (Security checklist + final integration)

---

## Test Coverage Summary

| Phase | Unit Tests | Integration Tests | Coverage Target |
|-------|-----------|------------------|-----------------|
| Phase 2 | 3 suites (Validation, Conversion, Storage) | — | >85% services |
| Phase 3 | 1 suite (UploadRequest controller) | 1 suite (Upload endpoint) | >80% total |
| Phase 4 | — | 1 suite (ImageEvent endpoint) | >80% total |
| Phase 5 | — | 1 suite (Status endpoint) | >80% total |
| **Total** | **3+ suites** | **3+ suites** | **>80% overall** |

---

## Completion Checklist

- [ ] All 72 tasks marked complete
- [ ] All unit tests pass: `phpunit tests/Unit/`
- [ ] All integration tests pass: `phpunit tests/Integration/`
- [ ] Code coverage >80%: `phpunit --coverage-text`
- [ ] End-to-end test passes: `bash tests/e2e-test.sh`
- [ ] Security checklist passed (docs/SECURITY.md)
- [ ] `git log` shows all commits with conventional commit messages
- [ ] Branch `001-gcs-image-upload` ready for PR review

---

## Appendix: Parallelization Examples

### Example 1: Parallel Service Development (Week 1, Days 3-5)

```
Developer A                          Developer B                    Developer C
├─ T013: UploadRecord model         ├─ T017: GcsService            ├─ T018: ImageConversionService
├─ T014: ImageValidation model      └─ (wait for GcsService)       └─ T020: Conversion tests
├─ T015: ValidationService                                         
├─ T016: Validation tests           Developer D
└─ (wait for ValidationService)     ├─ T019: StorageService
                                    ├─ T021: Storage tests
                                    └─ T022: .env.example
```

**Merge Order**: All of the above can merge simultaneously (no conflicts in separate files).

### Example 2: Parallel Controller Development (Week 2, Days 1-5)

```
Developer A (US1)                   Developer B (US2)              Developer C (Documentation)
├─ T023: UploadRequestController    ├─ T033: ImageEventController  ├─ T037: ImageEventTest prep
├─ T024: GUID generation           ├─ T034: OIDC validation        ├─ T027: UploadRequestTest prep
├─ T025: UploadRecord save          ├─ T035: Pub/Sub extraction     └─ T048: StatusTest prep
├─ T026: POST /images/upload-request route ├─ T036: /image-event route
├─ T027: UploadRequest tests        ├─ T038-T044: Validation logic
├─ T028: upload-request.sh script   └─ Integration tests
├─ T029: GCS signed URL gen         
├─ T030: E2E signed URL test        
├─ T031: Mock GcsService            
└─ T032: Response verification      
```

**Merge Order**: US1 and US2 can merge independently; tests verify integration.

### Example 3: Parallel Infrastructure (Week 3, Days 3-5)

```
Developer A (Terraform)             Developer B (Docker)           Developer C (Final tasks)
├─ T053: GCS module (buckets)       ├─ T061: docker-compose.yml    ├─ T063: ARCHITECTURE.md
├─ T054: GCS variables/outputs      └─ T062: Test docker setup     ├─ T064: API.md
├─ T055: Pub/Sub module             
├─ T056: Pub/Sub variables          
├─ T057-T060: Dev/Prod config       
└─ T062: Terraform tests            
```

**Merge Order**: Terraform and Docker are independent; can merge simultaneously.

---

## Success Criteria (Final Validation)

✅ **Completeness**: All 72 tasks marked complete  
✅ **Quality**: >80% code coverage verified  
✅ **Testing**: All unit + integration tests pass  
✅ **Security**: Security checklist from SECURITY.md passes  
✅ **Documentation**: ARCHITECTURE.md, API.md, TESTING.md complete  
✅ **Functionality**: End-to-end test (upload → validation → status) succeeds  
✅ **Git History**: Clean commit history on `001-gcs-image-upload` branch  
✅ **Readability**: Code follows DEVELOPMENT.md guidelines  

---

**Next Step**: Begin Phase 1 setup tasks (T001-T012). Proceed with T001 (create directory structure).
