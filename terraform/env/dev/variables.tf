variable "project_id" {
  description = "Google Cloud Project ID"
  type        = string
}

variable "region" {
  description = "Google Cloud region"
  type        = string
  default     = "us-central1"
}

variable "environment" {
  description = "Environment name"
  type        = string
  default     = "dev"
}

variable "upload_bucket_name" {
  description = "GCS bucket name for uploads"
  type        = string
}

variable "public_bucket_name" {
  description = "GCS bucket name for public images"
  type        = string
}

variable "gcs_storage_class" {
  description = "GCS storage class"
  type        = string
  default     = "STANDARD"
}

variable "topic_name" {
  description = "Pub/Sub topic name"
  type        = string
}

variable "subscription_name" {
  description = "Pub/Sub subscription name"
  type        = string
}

variable "pubsub_push_endpoint" {
  description = "HTTP endpoint for Pub/Sub push delivery"
  type        = string
}

variable "pubsub_push_auth_service_account" {
  description = "Service account for Pub/Sub authentication (optional)"
  type        = string
  default     = ""
}
