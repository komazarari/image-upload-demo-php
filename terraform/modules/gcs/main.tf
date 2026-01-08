# Upload Bucket (transient, not publicly accessible)
resource "google_storage_bucket" "upload_bucket" {
  project       = var.project_id
  name          = var.upload_bucket_name
  location      = var.region
  storage_class = var.storage_class
  
  uniform_bucket_level_access = true
  
  # Make bucket private (no public access)
  versioning {
    enabled = false
  }

  lifecycle {
    prevent_destroy = false
  }

  # Delete objects older than 7 days
  lifecycle_rule {
    action {
      type = "Delete"
    }
    condition {
      age = 7
    }
  }

  labels = {
    environment = var.environment
    purpose     = "uploads"
  }
}

# Public Bucket (processed images, publicly readable)
resource "google_storage_bucket" "public_bucket" {
  project       = var.project_id
  name          = var.public_bucket_name
  location      = var.region
  storage_class = var.storage_class
  
  uniform_bucket_level_access = true

  lifecycle {
    prevent_destroy = false
  }

  labels = {
    environment = var.environment
    purpose     = "public"
  }
}

# Make public bucket publicly readable
resource "google_storage_bucket_iam_member" "public_bucket_reader" {
  bucket = google_storage_bucket.public_bucket.name
  role   = "roles/storage.objectViewer"
  member = "allUsers"
}

# Upload bucket lifecycle policy - delete old files after 7 days
# Lifecycle rule moved into the upload_bucket resource above
