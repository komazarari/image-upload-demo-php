<?php
/**
 * GCS Service Implementation
 *
 * Concrete implementation of GcsService using Google Cloud Storage PHP SDK.
 * Provides real operations for signed URL generation, object management, etc.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Implementations;

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\Bucket;
use ImageUploadDemo\Services\GcsService;

class GcsServiceImpl extends GcsService
{
    /**
     * Google Cloud Storage client
     */
    private StorageClient $client;

    /**
     * Constructor
     *
     * @param string|null $projectId GCP project ID (defaults to env or auto-detect)
     * @param string|null $keyFilePath Path to service account JSON key file
     */
    public function __construct(?string $projectId = null, ?string $keyFilePath = null)
    {
        $config = [];

        if ($projectId !== null) {
            $config['projectId'] = $projectId;
        }

        if ($keyFilePath !== null && file_exists($keyFilePath)) {
            $config['keyFilePath'] = $keyFilePath;
        }

        $this->client = new StorageClient($config);
    }

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
    public function generateSignedUrl(
        string $bucket,
        string $objectName,
        int $expirySeconds = 3600,
        string $method = 'PUT',
        ?string $contentType = null
    ): string {
        try {
            $bucketInstance = $this->client->bucket($bucket);
            $object = $bucketInstance->object($objectName);

            $options = [
                'method' => $method,
                'version' => 'v4',  // Use V4 signing for better security
            ];

            // Add content type if provided (required for PUT requests)
            // This will be included in the signature calculation
            if ($contentType !== null) {
                $options['contentType'] = $contentType;
            }

            $signedUrl = $object->signedUrl(
                new \DateTime(sprintf('+%d seconds', $expirySeconds)),
                $options
            );

            // Debug logging
            error_log(sprintf(
                'Generated signed URL - Bucket: %s, Object: %s, Method: %s, ContentType: %s',
                $bucket,
                $objectName,
                $method,
                $contentType ?? 'null'
            ));

            return $signedUrl;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to generate signed URL for %s/%s: %s',
                $bucket,
                $objectName,
                $e->getMessage()
            ));
            throw new \Exception('Failed to generate signed URL: ' . $e->getMessage());
        }
    }

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
    public function copyObject(
        string $sourceBucket,
        string $sourceObject,
        string $destBucket,
        string $destObject
    ): bool {
        try {
            $sourceBucketInstance = $this->client->bucket($sourceBucket);
            $sourceObjectInstance = $sourceBucketInstance->object($sourceObject);

            $destBucketInstance = $this->client->bucket($destBucket);

            $sourceObjectInstance->copy($destBucketInstance, [
                'name' => $destObject,
            ]);

            error_log(sprintf(
                'Copied %s/%s to %s/%s',
                $sourceBucket,
                $sourceObject,
                $destBucket,
                $destObject
            ));

            return true;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to copy %s/%s to %s/%s: %s',
                $sourceBucket,
                $sourceObject,
                $destBucket,
                $destObject,
                $e->getMessage()
            ));
            throw new \Exception('Failed to copy object: ' . $e->getMessage());
        }
    }

    /**
     * Download object from GCS to local file
     *
     * @param string $bucket Bucket name
     * @param string $objectName Object name
     * @param string $localPath Local file path to download to
     * @return bool True if successful
     * @throws \Exception If download fails
     */
    public function downloadObject(
        string $bucket,
        string $objectName,
        string $localPath
    ): bool {
        try {
            $bucketInstance = $this->client->bucket($bucket);
            $object = $bucketInstance->object($objectName);

            // Ensure parent directory exists
            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $object->downloadToFile($localPath);

            error_log(sprintf(
                'Downloaded %s/%s to %s',
                $bucket,
                $objectName,
                $localPath
            ));

            return true;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to download %s/%s to %s: %s',
                $bucket,
                $objectName,
                $localPath,
                $e->getMessage()
            ));
            throw new \Exception('Failed to download object: ' . $e->getMessage());
        }
    }

    /**
     * Delete object from GCS
     *
     * @param string $bucket Bucket name
     * @param string $objectName Object name
     * @return bool True if successful
     * @throws \Exception If deletion fails
     */
    public function deleteObject(
        string $bucket,
        string $objectName
    ): bool {
        try {
            $bucketInstance = $this->client->bucket($bucket);
            $object = $bucketInstance->object($objectName);

            $object->delete();

            error_log(sprintf(
                'Deleted %s/%s',
                $bucket,
                $objectName
            ));

            return true;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to delete %s/%s: %s',
                $bucket,
                $objectName,
                $e->getMessage()
            ));
            throw new \Exception('Failed to delete object: ' . $e->getMessage());
        }
    }

    /**
     * Check if object exists in bucket
     *
     * @param string $bucket Bucket name
     * @param string $objectName Object name
     * @return bool True if exists
     */
    public function objectExists(string $bucket, string $objectName): bool
    {
        try {
            $bucketInstance = $this->client->bucket($bucket);
            $object = $bucketInstance->object($objectName);

            return $object->exists();
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to check existence of %s/%s: %s',
                $bucket,
                $objectName,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Get bucket instance
     *
     * @param string $bucketName Bucket name
     * @return Bucket Bucket instance
     */
    public function getBucket(string $bucketName): Bucket
    {
        return $this->client->bucket($bucketName);
    }
}
