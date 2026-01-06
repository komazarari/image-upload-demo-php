variable "project_id" {
  description = "Google Cloud Project ID"
  type        = string
}

variable "topic_name" {
  description = "Name of the Pub/Sub topic"
  type        = string
}

variable "subscription_name" {
  description = "Name of the Pub/Sub subscription"
  type        = string
}

variable "push_endpoint" {
  description = "HTTP endpoint for push subscription (where events are sent)"
  type        = string
}

variable "push_auth_service_account" {
  description = "Service account email for Pub/Sub push authentication"
  type        = string
  default     = ""
}

variable "message_retention_duration" {
  description = "How long to retain unacked messages (in seconds)"
  type        = number
  default     = 604800  # 7 days
}

variable "ack_deadline_seconds" {
  description = "Acknowledgement deadline for messages"
  type        = number
  default     = 60
}
