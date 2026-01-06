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
  description = "Environment name (dev, staging, prod)"
  type        = string
  validation {
    condition     = can(regex("^(dev|staging|prod)$", var.environment))
    error_message = "Environment must be dev, staging, or prod."
  }
}

variable "gcs_storage_class" {
  description = "GCS storage class for buckets"
  type        = string
  default     = "STANDARD"
  validation {
    condition     = can(regex("^(STANDARD|NEARLINE|COLDLINE|ARCHIVE)$", var.gcs_storage_class))
    error_message = "Storage class must be STANDARD, NEARLINE, COLDLINE, or ARCHIVE."
  }
}

variable "pubsub_push_endpoint" {
  description = "HTTP push endpoint for Pub/Sub subscription"
  type        = string
}
