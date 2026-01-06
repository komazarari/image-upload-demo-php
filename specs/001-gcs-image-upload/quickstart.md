# Quick Start: GCS Image Upload Demo

**Feature**: 001-gcs-image-upload  
**Updated**: 2026-01-06

Get the GCS Image Upload demo up and running locally in 5 minutes.

---

## Prerequisites

- PHP 8.0+
- Docker & Docker Compose (easiest)
- Google Cloud project with GCS enabled (for integration testing)
- `curl` and `bash` (for running client scripts)
- Git

---

## Option 1: Run with Docker Compose (Recommended)

### Step 1: Clone Repository

```bash
git clone https://github.com/komazarari/image-upload-demo-php.git
cd image-upload-demo-php
git checkout 001-gcs-image-upload
```

### Step 2: Start Services

```bash
docker-compose up --build
```

This will:
- Build PHP image with Slim framework + ImageMagick
- Start HTTP server on `http://localhost:8000`
- Create required directories (`storage/uploads`, `storage/logs`)

**Expected output**:
```
image-upload-demo | [info] HTTP server running on port 8000
image-upload-demo | [info] Upload endpoint: POST http://localhost:8000/images/upload-request
```

### Step 3: Test Upload Endpoint (Mock Mode)

In a new terminal:

```bash
# Request a signed URL (mock - no real GCS bucket)
curl -X POST http://localhost:8000/images/upload-request \
  -H "Content-Type: application/json" \
  -d '{"userId": "test-user"}'
```

**Response** (example):
```json
{
  "guid": "550e8400-e29b-41d4-a716-446655440000",
  "signedUrl": "https://storage.googleapis.com/mock-bucket/...",
  "expiresAt": "2026-01-06T13:00:00Z"
}
```

### Step 4: Test Status Endpoint

```bash
curl -X POST http://localhost:8000/images/status \
  -H "Content-Type: application/json" \
  -d '{
    "userId": "test-user",
    "guid": "550e8400-e29b-41d4-a716-446655440000"
  }'
```

**Response**:
```json
{
  "status": "initialized"
}
```

### Step 5: View Logs

```bash
# Follow live logs
docker-compose logs -f

# View specific service
docker-compose logs image-upload-demo
```

---

## Option 2: Run Locally without Docker

### Step 1: Install PHP & Dependencies

```bash
# macOS with Homebrew
brew install php composer imagemagick

# Ubuntu/Debian
sudo apt-get install php8.0 php8.0-dev composer imagemagick

# Verify installation
php --version
composer --version
convert --version  # ImageMagick
```

### Step 2: Install Project Dependencies

```bash
cd image-upload-demo-php
composer install
```

### Step 3: Create Required Directories

```bash
mkdir -p storage/uploads storage/logs
chmod 755 storage/uploads storage/logs
```

### Step 4: Start Development Server

```bash
# From project root
php -S localhost:8000 -t public/
```

### Step 5: Test Endpoints

Same as Docker Option, Step 3-4 above.

---

## GCP Integration (Optional, for Real Uploads)

To test with real Google Cloud Storage:

### Step 1: Set Up GCP Project

```bash
# Create GCP project
gcloud projects create image-upload-demo-php

# Enable GCS API
gcloud services enable storage-api.googleapis.com pubsub.googleapis.com

# Set project ID
export PROJECT_ID=$(gcloud config get-value project)
```

### Step 2: Create Service Account

```bash
# Create service account
gcloud iam service-accounts create image-upload-demo \
  --display-name="Image Upload Demo App"

# Grant GCS permissions
gcloud projects add-iam-policy-binding $PROJECT_ID \
  --member="serviceAccount:image-upload-demo@$PROJECT_ID.iam.gserviceaccount.com" \
  --role="roles/storage.admin"

# Create key file
gcloud iam service-accounts keys create key.json \
  --iam-account=image-upload-demo@$PROJECT_ID.iam.gserviceaccount.com
```

### Step 3: Create GCS Buckets

```bash
# Upload bucket (private)
gsutil mb -p $PROJECT_ID -l us-central1 \
  gs://image-upload-demo-upload

# Public bucket
gsutil mb -p $PROJECT_ID -l us-central1 \
  gs://image-upload-demo-public

# Make public bucket readable
gsutil iam ch serviceAccount:allUsers:objectViewer \
  gs://image-upload-demo-public
```

### Step 4: Set Environment Variables

```bash
export GOOGLE_APPLICATION_CREDENTIALS=$(pwd)/key.json
export GCP_PROJECT_ID=$PROJECT_ID
export GCS_UPLOAD_BUCKET=image-upload-demo-upload
export GCS_PUBLIC_BUCKET=image-upload-demo-public
```

### Step 5: Configure Pub/Sub (Advanced)

```bash
# Create Pub/Sub topic
gcloud pubsub topics create image-uploads

# Create push subscription (pointing to your server)
gcloud pubsub subscriptions create image-uploads-subscription \
  --topic=image-uploads \
  --push-endpoint=https://your-server.example.com/image-event \
  --push-auth-service-account=image-upload-demo@$PROJECT_ID.iam.gserviceaccount.com

# Configure GCS to publish to Pub/Sub
gsutil notification create -t image-uploads -f json \
  gs://image-upload-demo-upload
```

### Step 6: Run with GCP Integration

```bash
# With Docker
docker-compose up --build

# Or locally
php -S localhost:8000 -t public/
```

---

## Using Client Scripts

Once server is running, use the bash client scripts:

### Upload Image

```bash
bash clients/upload-request.sh user123 /path/to/image.jpg
```

**Script does**:
1. Calls `/images/upload-request` to get signed URL
2. Uploads image to signed URL via curl
3. Outputs guid and polls status every 2 seconds

### Check Status

```bash
bash clients/status.sh user123 f47ac10b-58cc-4372-a567-0e02b2c3d479
```

**Output** (when processing):
```
Status: processing
```

**Output** (when complete):
```
Status: completed
Public URL: https://storage.googleapis.com/image-upload-demo-public/f47ac10b-58cc-4372-a567-0e02b2c3d479.jpg
```

---

## Project Structure

```
image-upload-demo-php/
├── public/
│   └── index.php                 # Entry point
├── src/
│   ├── Controllers/
│   │   ├── ImageUploadController.php
│   │   ├── ImageEventController.php
│   │   └── ImageStatusController.php
│   ├── Services/
│   │   ├── ImageValidationService.php
│   │   ├── ImageConversionService.php
│   │   ├── GcsService.php
│   │   └── StorageService.php
│   └── Models/
│       ├── UploadRecord.php
│       └── ImageValidation.php
├── tests/
│   ├── Unit/
│   │   └── ...
│   └── Integration/
│       └── ...
├── storage/
│   ├── uploads/                 # Upload records (JSON)
│   └── logs/                    # Application logs
├── clients/
│   ├── upload-request.sh
│   └── status.sh
├── terraform/
│   └── ...
├── docker/
│   ├── Dockerfile
│   └── docker-compose.yml
└── specs/001-gcs-image-upload/
    ├── spec.md
    ├── plan.md
    ├── data-model.md
    ├── research.md
    └── contracts/
```

---

## Running Tests

```bash
# All tests
composer test

# Unit tests only
composer test:unit

# Integration tests
composer test:integration

# With coverage
composer test:coverage

# View coverage report
open coverage/index.html
```

---

## Common Tasks

### View Upload Records

```bash
# List all uploads
ls -la storage/uploads/

# View a specific upload
cat storage/uploads/{guid}.json
```

### Check Logs

```bash
# All logs (JSON formatted)
cat storage/logs/app.log | jq .

# Live logs
tail -f storage/logs/app.log
```

### Clear Storage

```bash
# Remove all upload records
rm storage/uploads/*.json

# Remove all logs
rm storage/logs/*.log
```

### Restart Services

```bash
# With Docker
docker-compose restart

# Or full rebuild
docker-compose down
docker-compose up --build
```

---

## Troubleshooting

### Port 8000 already in use

```bash
# Change port
php -S localhost:8080 -t public/

# Or kill existing process
lsof -i :8000
kill -9 <PID>
```

### ImageMagick not found

```bash
# macOS
brew install imagemagick php-imagick

# Ubuntu
sudo apt-get install imagemagick php8.0-imagick

# Verify
php -m | grep imagick
```

### GCS authentication error

```bash
# Verify credentials file
cat $GOOGLE_APPLICATION_CREDENTIALS

# Check permissions
gcloud projects get-iam-policy $PROJECT_ID \
  --flatten="bindings[].members" \
  --filter="bindings.members:image-upload-demo@*"
```

### Pub/Sub not receiving events

```bash
# Check subscription
gcloud pubsub subscriptions describe image-uploads-subscription

# Test message delivery
gcloud pubsub topics publish image-uploads \
  --message='{"test": "message"}'

# View subscription logs
gcloud logging read "resource.type=pubsub_subscription" \
  --limit=10 --format=json
```

---

## Next Steps

1. **Review Code**: Check `src/Controllers/` and `src/Services/` to understand architecture
2. **Run Tests**: `composer test` to see all features working
3. **Modify Code**: Try adding a new validation rule (commit to feature branch)
4. **Deploy**: Use terraform to deploy to Google Cloud Run or App Engine
5. **Read Docs**: Review `docs/DEVELOPMENT.md`, `docs/SECURITY.md`, `docs/ARCHITECTURE.md`

---

## Support & Documentation

- **Architecture**: See [data-model.md](data-model.md) for entity definitions
- **API**: See [contracts/api.openapi.yaml](contracts/api.openapi.yaml) for endpoint specs
- **Development**: See [../../docs/DEVELOPMENT.md](../../docs/DEVELOPMENT.md) for code guidelines
- **Security**: See [../../docs/SECURITY.md](../../docs/SECURITY.md) for security implementation
- **Specification**: See [spec.md](spec.md) for full requirements

---

**Ready?** Let's go! Run `docker-compose up` and test with the client scripts above. 🚀
