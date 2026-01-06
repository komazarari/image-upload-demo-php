# Development environment configuration for GCS Image Upload Demo
# Update values as needed for your development environment

project_id                     = "your-gcp-project-id"
region                         = "us-central1"
environment                    = "dev"
upload_bucket_name             = "image-upload-demo-uploads-dev"
public_bucket_name             = "image-upload-demo-public-dev"
gcs_storage_class              = "STANDARD"
topic_name                     = "image-upload-events-dev"
subscription_name              = "image-upload-events-subscription-dev"
pubsub_push_endpoint           = "https://your-domain.com/image-event"
pubsub_push_auth_service_account = ""  # Leave empty for unauthenticated push
