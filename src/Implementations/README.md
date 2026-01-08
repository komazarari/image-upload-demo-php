# Implementation Directory

This directory contains concrete implementations of abstract service interfaces.

## Available Implementations

### Storage Services

#### JsonStorageService
- **Purpose**: Local JSON file storage for development/demo
- **Use case**: Local development, testing, demos
- **Configuration**:
  - `STORAGE_BACKEND=json` (default)
  - `STORAGE_DIR=/var/www/html/storage/uploads` (default)

#### FirestoreStorageService
- **Purpose**: Google Cloud Firestore for production
- **Use case**: Production deployments with persistent, queryable storage
- **Configuration**:
  - `STORAGE_BACKEND=firestore`
  - `GCP_PROJECT_ID=your-project-id`
  - `GCP_KEY_FILE=/path/to/key.json` (optional, uses ADC if not set)
  - `FIRESTORE_COLLECTION=upload_records` (default)

### GCS Services

#### GcsServiceImpl
- **Purpose**: Real Google Cloud Storage operations
- **Use case**: Production deployments, integration testing
- **Configuration**:
  - `USE_REAL_GCS=true`
  - `GCP_PROJECT_ID=your-project-id`
  - `GCP_KEY_FILE=/path/to/key.json` (optional, uses ADC if not set)

#### Mock GCS Service (anonymous class in App.php)
- **Purpose**: Development without GCS access
- **Use case**: Local development without GCP credentials
- **Configuration**:
  - `USE_REAL_GCS=false` (default)

## Architecture

### Abstract Base Classes

1. **StorageServiceInterface** (`src/Services/StorageServiceInterface.php`)
   - Defines interface for persisting `UploadRecord` instances
   - Methods: `save()`, `load()`, `exists()`, `delete()`, `getAllGuids()`

2. **GcsService** (`src/Services/GcsService.php`)
   - Defines interface for Google Cloud Storage operations
   - Methods: `generateSignedUrl()`, `copyObject()`, `downloadObject()`, `deleteObject()`

### Implementation Classes

All implementation classes extend their respective abstract base classes and provide concrete functionality.

## Configuration

The application initializes services in `App.php` based on environment variables:

```php
$services = initializeServices($env);
```

### Environment Variables

Create a `.env` file in the project root:

```bash
# Application environment
APP_ENV=development

# Storage backend: json (default) or firestore
STORAGE_BACKEND=json
STORAGE_DIR=/var/www/html/storage/uploads

# GCS configuration
USE_REAL_GCS=false
GCP_PROJECT_ID=your-project-id
GCP_KEY_FILE=/path/to/service-account-key.json

# Firestore configuration (if STORAGE_BACKEND=firestore)
FIRESTORE_COLLECTION=upload_records

# GCS bucket names
UPLOAD_BUCKET=your-upload-bucket
PUBLIC_BUCKET=your-public-bucket
```

## Usage Examples

### Using JSON Storage (Development)

```bash
# .env
STORAGE_BACKEND=json
STORAGE_DIR=/var/www/html/storage/uploads
USE_REAL_GCS=false
```

### Using Firestore + Real GCS (Production)

```bash
# .env
STORAGE_BACKEND=firestore
USE_REAL_GCS=true
GCP_PROJECT_ID=my-project-123
FIRESTORE_COLLECTION=upload_records
UPLOAD_BUCKET=my-upload-bucket
PUBLIC_BUCKET=my-public-bucket
```

## Adding New Implementations

To add a new storage backend:

1. Create a new class in `src/Implementations/`
2. Extend `StorageServiceInterface`
3. Implement all abstract methods
4. Update `initializeServices()` in `App.php` to support the new backend

Example:

```php
class RedisStorageService extends StorageServiceInterface
{
    public function save(UploadRecord $record): bool { /* ... */ }
    public function load(string $guid): ?UploadRecord { /* ... */ }
    // ... other methods
}
```

## Testing

Each implementation should have corresponding unit tests:

- `tests/Unit/JsonStorageServiceTest.php`
- `tests/Unit/FirestoreStorageServiceTest.php`
- `tests/Unit/GcsServiceImplTest.php`

## Dependencies

### For GcsServiceImpl
- `google/cloud-storage` (already in composer.json)

### For FirestoreStorageService
- `google/cloud-firestore` (needs to be added to composer.json)

Add Firestore dependency:
```bash
composer require google/cloud-firestore
```

## Migration Path

1. **Development**: Use `JsonStorageService` + mock GCS
2. **Staging**: Use `FirestoreStorageService` + real GCS in test project
3. **Production**: Use `FirestoreStorageService` + real GCS in production project

## Notes

- The legacy `StorageService` class still exists for backward compatibility but is deprecated
- Controllers receive services via dependency injection in route definitions
- All service initialization is centralized in `initializeServices()` function
- Application Default Credentials (ADC) are used if `GCP_KEY_FILE` is not specified
