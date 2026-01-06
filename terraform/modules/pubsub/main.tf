# Pub/Sub Topic for image processing events
resource "google_pubsub_topic" "image_events" {
  project = var.project_id
  name    = var.topic_name

  labels = {
    environment = "demo"
    purpose     = "image-processing"
  }
}

# Pub/Sub Subscription with push delivery to application endpoint
resource "google_pubsub_subscription" "image_events_push" {
  project = var.project_id
  name    = var.subscription_name
  topic   = google_pubsub_topic.image_events.name

  ack_deadline_seconds       = var.ack_deadline_seconds
  message_retention_duration = "${var.message_retention_duration}s"

  push_config {
    push_endpoint = var.push_endpoint

    # Optional: Add authentication if using OIDC
    # oidc_token_audience = var.push_endpoint
    # service_account_email = var.push_auth_service_account
  }

  labels = {
    environment = "demo"
    purpose     = "push-delivery"
  }
}

# Grant Pub/Sub service account permission to create OIDC tokens (if needed)
# This is required if push_config includes oidc_token_audience
data "google_iam_policy" "admin" {
  count = var.push_auth_service_account != "" ? 1 : 0

  binding {
    role    = "roles/iam.serviceAccountTokenCreator"
    members = [var.push_auth_service_account]
  }
}
