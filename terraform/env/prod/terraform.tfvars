# Production environment configuration for GCS Image Upload Demo
# IMPORTANT: Review and update all values before applying to production

project_id                     = "your-gcp-project-id-prod"
region                         = "us-central1"
environment                    = "prod"
upload_bucket_name             = "image-upload-demo-uploads-prod"
public_bucket_name             = "image-upload-demo-public-prod"
gcs_storage_class              = "NEARLINE"  # More cost-effective for infrequent access
topic_name                     = "image-upload-events-prod"
subscription_name              = "image-upload-events-subscription-prod"
pubsub_push_endpoint           = "https://yourdomain.com/image-event"  # MUST be HTTPS
pubsub_push_auth_service_account = "service-account@your-project.iam.gserviceaccount.com"
pubsub_ack_deadline_seconds    = 120
