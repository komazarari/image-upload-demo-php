<?php
/**
 * GCS Service Interface
 *
 * Abstract interface for Google Cloud Storage operations.
 * Allows mocking in tests without actual GCS calls.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Services;

abstract class GcsService
{
    /**
     * Generate a signed URL for direct upload to GCS bucket
     *
     * @param string $bucket GCS bucket name
     * @param string $objectName Object name in bucket
     * @param int $expirySeconds Expiration time in seconds
     * @param string $method HTTP method (PUT, GET, etc.)
     * @param string|null $contentType Content type for the upload (required for PUT)
     * @return string Signed URL
     * @throws \Exception If URL generation fails
     */
    abstract public function generateSignedUrl(
        string $bucket,
        string $objectName,
        int $expirySeconds = 3600,
        string $method = 'PUT',
        ?string $contentType = null
    ): string;

    /**
     * Copy object from one bucket to another
     *
     * @param string $sourceBucket Source bucket name
     * @param string $sourceObject Source object name
     * @param string $destBucket Destination bucket name
     * @param string $destObject Destination object name
     * @return bool True if successful
     * @throws \Exception If copy fails
     */
    abstract public function copyObject(
        string $sourceBucket,
        string $sourceObject,
        string $destBucket,
        string $destObject
    ): bool;

    /**
     * Download object from GCS to local file
     *
     * @param string $bucket Bucket name
     * @param string $objectName Object name
     * @param string $localPath Local file path to download to
     * @return bool True if successful
     * @throws \Exception If download fails
     */
    abstract public function downloadObject(
        string $bucket,
        string $objectName,
        string $localPath
    ): bool;

    /**
     * Delete object from GCS
     *
     * @param string $bucket Bucket name
     * @param string $objectName Object name
     * @return bool True if successful
     * @throws \Exception If deletion fails
     */
    abstract public function deleteObject(
        string $bucket,
        string $objectName
    ): bool;

    /**
     * Get public URL for object in public bucket
     *
     * @param string $bucket Bucket name
     * @param string $objectName Object name
     * @return string Public HTTPS URL
     */
    public function getPublicUrl(string $bucket, string $objectName): string
    {
        return sprintf(
            'https://storage.googleapis.com/%s/%s',
            $bucket,
            urlencode($objectName)
        );
    }
}
