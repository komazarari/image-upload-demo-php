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

# Cloud Storage Notification for upload bucket
# Publishes OBJECT_FINALIZE events to Pub/Sub topic when objects are uploaded
resource "google_storage_notification" "upload_bucket_notification" {
  bucket         = google_storage_bucket.upload_bucket.name
  payload_format = "JSON_API_V1"
  topic          = var.pubsub_topic_id
  
  # Notify on object finalize (upload complete)
  event_types = ["OBJECT_FINALIZE"]
  
  depends_on = [google_pubsub_topic_iam_member.gcs_publisher]
}

# Grant GCS service account permission to publish to Pub/Sub topic
# The GCS service account is: service-PROJECT_NUMBER@gs-project-accounts.iam.gserviceaccount.com
# This allows Cloud Storage Notifications to send events to Pub/Sub
resource "google_pubsub_topic_iam_member" "gcs_publisher" {
  topic   = var.pubsub_topic_name
  role    = "roles/pubsub.publisher"
  member  = "serviceAccount:service-${data.google_project.current.number}@gs-project-accounts.iam.gserviceaccount.com"
  project = var.project_id
}

# Get current project data
data "google_project" "current" {
  project_id = var.project_id
}

# Upload bucket lifecycle policy - delete old files after 7 days
# Lifecycle rule moved into the upload_bucket resource above
