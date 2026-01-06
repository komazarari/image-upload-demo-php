output "upload_bucket_name" {
  description = "Name of the upload bucket"
  value       = google_storage_bucket.upload_bucket.name
}

output "upload_bucket_url" {
  description = "URL of the upload bucket"
  value       = "gs://${google_storage_bucket.upload_bucket.name}"
}

output "public_bucket_name" {
  description = "Name of the public bucket"
  value       = google_storage_bucket.public_bucket.name
}

output "public_bucket_url" {
  description = "URL of the public bucket"
  value       = "gs://${google_storage_bucket.public_bucket.name}"
}

output "public_bucket_http_url" {
  description = "HTTP URL for accessing public bucket objects"
  value       = "https://storage.googleapis.com/${google_storage_bucket.public_bucket.name}"
}
