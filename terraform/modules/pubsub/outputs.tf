output "topic_name" {
  description = "Name of the created Pub/Sub topic"
  value       = google_pubsub_topic.image_events.name
}

output "topic_path" {
  description = "Full path of the Pub/Sub topic"
  value       = google_pubsub_topic.image_events.id
}

output "subscription_name" {
  description = "Name of the created subscription"
  value       = google_pubsub_subscription.image_events_push.name
}

output "subscription_path" {
  description = "Full path of the subscription"
  value       = google_pubsub_subscription.image_events_push.id
}

output "push_endpoint" {
  description = "Push endpoint URL for subscription"
  value       = var.push_endpoint
}
