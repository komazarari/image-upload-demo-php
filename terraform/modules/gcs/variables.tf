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
}

variable "upload_bucket_name" {
  description = "Name of the GCS bucket for uploads (transient, not public)"
  type        = string
}

variable "public_bucket_name" {
  description = "Name of the GCS bucket for processed images (public read)"
  type        = string
}

variable "storage_class" {
  description = "Storage class for buckets"
  type        = string
  default     = "STANDARD"
}
