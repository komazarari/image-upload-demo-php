terraform {
  required_version = ">= 1.0"
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 5.0"
    }
  }
  
  # Uncomment for remote state management
  # backend "gcs" {
  #   bucket = "your-terraform-state-bucket"
  #   prefix = "image-upload-demo/prod"
  # }
}

provider "google" {
  project = var.project_id
  region  = var.region
}

# GCS Buckets with stricter settings for production
module "gcs" {
  source = "../../modules/gcs"

  project_id          = var.project_id
  region              = var.region
  environment         = var.environment
  upload_bucket_name  = var.upload_bucket_name
  public_bucket_name  = var.public_bucket_name
  storage_class       = var.gcs_storage_class
  pubsub_topic_id     = module.pubsub.topic_path
  pubsub_topic_name   = var.topic_name
}

# Pub/Sub Topic and Subscription with production settings
module "pubsub" {
  source = "../../modules/pubsub"

  project_id                = var.project_id
  topic_name                = var.topic_name
  subscription_name         = var.subscription_name
  push_endpoint             = var.pubsub_push_endpoint
  push_auth_service_account = var.pubsub_push_auth_service_account
  ack_deadline_seconds      = var.pubsub_ack_deadline_seconds
}

# Outputs
output "gcs_upload_bucket" {
  value = module.gcs.upload_bucket_name
}

output "gcs_public_bucket" {
  value = module.gcs.public_bucket_name
}

output "gcs_public_bucket_url" {
  value = module.gcs.public_bucket_http_url
}

output "pubsub_topic_name" {
  value = module.pubsub.topic_name
}

output "pubsub_subscription_name" {
  value = module.pubsub.subscription_name
}
