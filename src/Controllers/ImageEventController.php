<?php
/**
 * Image Event Controller
 *
 * Handles HTTP POST requests from Google Cloud Pub/Sub.
 * Processes image validation, conversion, and storage based on events.
 * Uses base64-encoded JSON in Pub/Sub message body.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Controllers;

use ImageUploadDemo\Enums\UploadStatus;
use ImageUploadDemo\Services\ImageValidationService;
use ImageUploadDemo\Services\ImageConversionService;
use ImageUploadDemo\Services\StorageServiceInterface;
use ImageUploadDemo\Services\GcsService;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ImageEventController
{
    /**
     * Constructor
     *
     * @param StorageServiceInterface $storageService Service for persisting upload records
     * @param ImageValidationService $validationService Service for validating images
     * @param ImageConversionService $conversionService Service for converting images
     * @param GcsService $gcsService Service for GCS operations
     * @param string $uploadBucket GCS upload bucket name
     * @param string $publicBucket GCS public bucket name
     */
    public function __construct(
        private StorageServiceInterface $storageService,
        private ImageValidationService $validationService,
        private ImageConversionService $conversionService,
        private GcsService $gcsService,
        private string $uploadBucket,
        private string $publicBucket,
    ) {
    }

    /**
     * Handle POST /image-event (from Pub/Sub)
     *
     * Pub/Sub sends events in the following format:
     * {
     *   "message": {
     *     "data": "base64-encoded-json",
     *     "messageId": "...",
     *     "publishTime": "..."
     *   }
     * }
     *
     * The decoded JSON contains:
     * {
     *   "bucket": "uploads",
     *   "name": "guid.jpg"
     * }
     *
     * @param Request $request HTTP request from Pub/Sub
     * @param Response $response HTTP response
     * @return Response JSON response
     */
    public function handlePubSubEvent(Request $request, Response $response): Response
    {
        try {
            // Parse request body
            $body = json_decode((string)$request->getBody(), true);
            error_log('Received Pub/Sub event: ' . json_encode($body));

            if (!isset($body['message']['data'])) {
                error_log('Missing message data in Pub/Sub event');
                return $this->successResponse($response, 'Invalid message format');
            }

            // Decode base64 message data
            $messageData = json_decode(
                base64_decode($body['message']['data']),
                true
            );

            if (!isset($messageData['bucket']) || !isset($messageData['name'])) {
                error_log('Missing bucket or name in Pub/Sub message');
                return $this->successResponse($response, 'Missing required fields');
            }

            $bucket = $messageData['bucket'];
            $objectName = $messageData['name'];

            // Extract GUID from object name (e.g., "550e8400-e29b-41d4-a716-446655440000.jpg")
            $guid = pathinfo($objectName, PATHINFO_FILENAME);

            // Load upload record
            $record = $this->storageService->load($guid);
            if ($record === null) {
                error_log(sprintf('Upload record not found for GUID: %s', $guid));
                return $this->successResponse($response, 'Record not found');
            }

            // Download object to a temporary path for validation/conversion
            $tempPath = sys_get_temp_dir() . '/' . $objectName;
            $tempDir = dirname($tempPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            if (!$this->gcsService->downloadObject($bucket, $objectName, $tempPath)) {
                $record->markFailed('Failed to download object from GCS');
                $this->storageService->save($record);
                error_log(sprintf('Failed to download object %s/%s', $bucket, $objectName));
                return $this->successResponse($response, 'Download failed');
            }

            // Mark as processing
            $record->markProcessing();
            $this->storageService->save($record);

            // Determine content type
            $contentType = $messageData['contentType'] ?? $this->detectMimeType($tempPath) ?? 'application/octet-stream';

            // Validate image
            $validation = $this->validationService->validate($tempPath, $contentType);

            if (!$validation->isValid) {
                // Mark as failed
                $record->markFailed('Image validation failed: ' . implode('; ', $validation->errors));
                $this->storageService->save($record);
                error_log(sprintf('Image validation failed for GUID %s: %s', $guid, implode('; ', $validation->errors)));
                return $this->successResponse($response, 'Validation failed');
            }

            // Convert image (strip EXIF, re-encode)
            $convertedPath = $tempPath . '.converted';
            $conversionResult = $this->conversionService->convert(
                $tempPath,
                $convertedPath,
                $validation->mimeType
            );

            if (!$conversionResult->isValid) {
                $record->markFailed('Image conversion failed: ' . implode('; ', $conversionResult->errors));
                $this->storageService->save($record);
                error_log(sprintf('Image conversion failed for GUID %s', $guid));
                return $this->successResponse($response, 'Conversion failed');
            }

            // Copy converted image to public bucket
            if (!$this->gcsService->copyObject(
                $this->uploadBucket,
                $objectName,
                $this->publicBucket,
                $objectName
            )) {
                $record->markFailed('Failed to copy image to public bucket');
                $this->storageService->save($record);
                error_log(sprintf('Failed to copy %s to public bucket', $objectName));
                return $this->successResponse($response, 'Copy to public failed');
            }

            // Delete original from upload bucket
            if (!$this->gcsService->deleteObject($this->uploadBucket, $objectName)) {
                error_log(sprintf('Failed to delete %s from upload bucket', $objectName));
                // Don't fail - file is already in public bucket
            }

            // Generate public URL
            $publicUrl = sprintf('https://storage.googleapis.com/%s/%s', $this->publicBucket, $objectName);

            // Mark as completed
            $record->markCompleted($publicUrl);
            $record->contentType = $validation->mimeType;
            $record->fileSize = $validation->fileSize;
            $record->imageDimensions = [
                'width' => $validation->width,
                'height' => $validation->height,
            ];

            $this->storageService->save($record);

            // Clean up temp files
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            if (isset($convertedPath) && file_exists($convertedPath)) {
                @unlink($convertedPath);
            }

            error_log(sprintf('Image processing completed for GUID %s: %s', $guid, $publicUrl));

            // Return acknowledgment to Pub/Sub
            return $this->successResponse($response, 'Processing completed');

        } catch (\Exception $e) {
            error_log('Error processing image event: ' . $e->getMessage());
            return $this->successResponse($response, 'Processing error');
        }
    }

    /**
     * Detect MIME type using finfo
     */
    private function detectMimeType(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return $mime !== false ? $mime : null;
    }

    /**
     * Return success response to Pub/Sub
     *
     * Always return 200 to Pub/Sub to prevent re-delivery.
     * Log failures and handle internally.
     *
     * @param Response $response HTTP response
     * @param string $message Response message
     * @return Response JSON success response
     */
    private function successResponse(Response $response, string $message): Response
    {
        $data = [
            'status' => 'acknowledged',
            'message' => $message,
        ];

        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }
}
