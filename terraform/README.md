# Terraform Infrastructure Configuration

This directory contains Infrastructure-as-Code (IaC) definitions for deploying the GCS Image Upload Demo application to Google Cloud Platform.

## Directory Structure

```
terraform/
├── modules/              # Reusable Terraform modules
│   ├── gcs/             # GCS bucket configuration
│   └── pubsub/          # Pub/Sub topic and subscription
├── env/                 # Environment-specific configurations
│   ├── dev/             # Development environment
│   └── prod/            # Production environment
└── shared/              # Shared variables and configuration
```

## Modules

### GCS Module (`modules/gcs/`)

Manages two Google Cloud Storage buckets:

- **Upload Bucket**: Transient storage for incoming file uploads (not publicly accessible)
  - Lifecycle policy: Auto-delete objects after 7 days
  - Uniform bucket-level access enabled
  
- **Public Bucket**: Processed images (publicly readable)
  - Grant `allUsers` with `objectViewer` role
  - Uniform bucket-level access enabled

**Inputs**:
- `project_id`: Google Cloud Project ID
- `region`: GCS region (default: us-central1)
- `environment`: Environment name (dev/prod)
- `upload_bucket_name`: Name for upload bucket
- `public_bucket_name`: Name for public bucket
- `storage_class`: Storage class (STANDARD, NEARLINE, COLDLINE, ARCHIVE)

**Outputs**:
- `upload_bucket_name`: Upload bucket name
- `public_bucket_name`: Public bucket name
- `public_bucket_http_url`: HTTP URL for accessing public objects

### Pub/Sub Module (`modules/pubsub/`)

Manages Google Cloud Pub/Sub topic and push subscription:

- **Topic**: `image-upload-events-{env}`
  - Receives notifications from GCS for uploaded files
  
- **Push Subscription**: `image-upload-events-subscription-{env}`
  - Configured to push events to application HTTP endpoint
  - Automatically acks messages on successful delivery
  - 7-day message retention (configurable)

**Inputs**:
- `project_id`: Google Cloud Project ID
- `topic_name`: Pub/Sub topic name
- `subscription_name`: Pub/Sub subscription name
- `push_endpoint`: HTTP endpoint for push delivery (must be HTTPS in production)
- `push_auth_service_account`: Service account for OIDC authentication (optional)
- `ack_deadline_seconds`: Message acknowledgement deadline (default: 60)

**Outputs**:
- `topic_path`: Full path to Pub/Sub topic
- `subscription_path`: Full path to subscription
- `push_endpoint`: Configured push endpoint URL

## Environments

### Development (`env/dev/`)

Development configuration for testing locally or in a dev GCP project.

**Configuration**:
- Storage class: STANDARD
- Bucket naming: `*-dev` suffix
- Pub/Sub: `*-dev` suffix
- Push endpoint: Configurable (local tunnel, dev server, etc.)

**Setup**:
```bash
cd terraform/env/dev
terraform init
terraform plan
terraform apply
```

**Output**: Creates dev GCS buckets and Pub/Sub resources. Update `terraform.tfvars` with your project ID and push endpoint.

### Production (`env/prod/`)

Production configuration with enhanced security and cost optimization.

**Configuration**:
- Storage class: NEARLINE (more cost-effective)
- Bucket naming: `*-prod` suffix
- Pub/Sub: `*-prod` suffix
- Push endpoint: MUST be HTTPS
- OIDC authentication: Required for secure push delivery

**Setup**:
```bash
cd terraform/env/prod
terraform init
terraform plan -out=tfplan
terraform apply tfplan  # Review plan before applying
```

**Important**:
- Update `terraform.tfvars` with production project ID, buckets, and endpoint
- Ensure push endpoint is HTTPS
- Configure service account for OIDC authentication
- Use remote state backend (GCS) to prevent accidental deletions
- Require plan review before applying changes

## Usage

### 1. Initialize Terraform

```bash
cd terraform/env/dev  # or prod
terraform init
```

### 2. Review Configuration

Update `terraform.tfvars` with your values:
```bash
# Development
project_id = "your-gcp-project-id"
region = "us-central1"
upload_bucket_name = "image-upload-demo-uploads-dev"
public_bucket_name = "image-upload-demo-public-dev"
topic_name = "image-upload-events-dev"
subscription_name = "image-upload-events-subscription-dev"
pubsub_push_endpoint = "https://localhost:8000/image-event"  # Your app endpoint
```

### 3. Validate Configuration

```bash
terraform fmt -recursive .
terraform validate
```

### 4. Plan Changes

```bash
terraform plan
```

Review output and verify:
- Bucket names are unique globally
- Push endpoint is correct
- All variables are set

### 5. Apply Configuration

```bash
terraform apply
```

### 6. Verify Resources

```bash
# List buckets
gcloud storage buckets list

# List Pub/Sub resources
gcloud pubsub topics list
gcloud pubsub subscriptions list
```

## Environment Variables

Set Google Cloud authentication:

```bash
# Option 1: Application Default Credentials (local development)
gcloud auth application-default login

# Option 2: Service account key file
export GOOGLE_APPLICATION_CREDENTIALS="/path/to/key.json"
```

## Integration with Application

After provisioning infrastructure, update your application's `.env` file:

```bash
GCP_PROJECT_ID=your-project-id
GCS_UPLOAD_BUCKET=image-upload-demo-uploads-dev
GCS_PUBLIC_BUCKET=image-upload-demo-public-dev
PUBSUB_TOPIC=image-upload-events-dev
PUBSUB_SUBSCRIPTION=image-upload-events-subscription-dev
```

## Security Considerations

### Development
- Upload bucket is private (default)
- Public bucket allows world-readable access (for demo purposes)
- Service account: Minimal permissions (Pub/Sub publisher, GCS access)

### Production
- Enable Bucket Lock for compliance
- Use Cloud KMS for encryption at rest
- Enable audit logging
- Restrict public bucket access to CDN/CloudFront
- Use VPC Service Controls to isolate projects
- Enable binary authorization for container images
- Implement monitoring and alerting

## Cleanup

To destroy all resources:

```bash
cd terraform/env/dev  # or prod
terraform destroy
```

**Warning**: This will delete all GCS buckets and Pub/Sub resources. Download/backup any required data first.

## Troubleshooting

### "Bucket name already exists"
GCS bucket names are globally unique. Use a unique suffix or project-specific prefix:
```hcl
upload_bucket_name = "image-upload-${var.project_id}-uploads-dev"
```

### "Permission denied" errors
Ensure your service account has required roles:
- `roles/storage.admin` (GCS bucket management)
- `roles/pubsub.admin` (Pub/Sub management)

```bash
gcloud projects add-iam-policy-binding your-project \
  --member=serviceAccount:your-sa@project.iam.gserviceaccount.com \
  --role=roles/storage.admin
```

### Pub/Sub push delivery not working
1. Verify push endpoint is accessible and returns HTTP 200
2. Check OIDC service account has `roles/iam.serviceAccountTokenCreator`
3. Review subscription push config: `gcloud pubsub subscriptions describe subscription-name --format=json`

## References

- [Terraform Google Provider](https://registry.terraform.io/providers/hashicorp/google/latest/docs)
- [Google Cloud Storage Documentation](https://cloud.google.com/storage/docs)
- [Google Cloud Pub/Sub Documentation](https://cloud.google.com/pubsub/docs)
- [Terraform Best Practices](https://cloud.google.com/docs/terraform/best-practices-for-terraform)
