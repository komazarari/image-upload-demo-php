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
use ImageUploadDemo\Services\StorageService;
use ImageUploadDemo\Services\GcsService;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ImageEventController
{
    /**
     * Constructor
     *
     * @param StorageService $storageService Service for persisting upload records
     * @param ImageValidationService $validationService Service for validating images
     * @param ImageConversionService $conversionService Service for converting images
     * @param GcsService $gcsService Service for GCS operations
     */
    public function __construct(
        private StorageService $storageService,
        private ImageValidationService $validationService,
        private ImageConversionService $conversionService,
        private GcsService $gcsService,
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

            // Mark as processing
            $record->markProcessing();
            $this->storageService->save($record);

            // Validate image (in production, would download from GCS first)
            $validation = $this->validationService->validate(
                $objectName, // Local path (simulated)
                'image/jpeg' // TODO: detect from metadata
            );

            if (!$validation->isValid) {
                // Mark as failed
                $record->markFailed('Image validation failed: ' . implode('; ', $validation->errors));
                $this->storageService->save($record);
                error_log(sprintf('Image validation failed for GUID %s: %s', $guid, implode('; ', $validation->errors)));
                return $this->successResponse($response, 'Validation failed');
            }

            // Convert image (strip EXIF, re-encode)
            $conversionResult = $this->conversionService->convert(
                $objectName,
                $objectName . '.converted',
                'image/jpeg'
            );

            if (!$conversionResult->isValid) {
                $record->markFailed('Image conversion failed: ' . implode('; ', $conversionResult->errors));
                $this->storageService->save($record);
                error_log(sprintf('Image conversion failed for GUID %s', $guid));
                return $this->successResponse($response, 'Conversion failed');
            }

            // In production:
            // 1. Copy converted image to public bucket
            // 2. Delete original from upload bucket
            // 3. Generate signed URL (or public URL if public bucket)
            // 4. Update record with public URL

            // For now, mock the public URL
            $publicUrl = sprintf('https://storage.googleapis.com/public/%s', $objectName);

            // Mark as completed
            $record->markCompleted($publicUrl);
            $record->contentType = 'image/jpeg'; // TODO: detect actual type
            $record->fileSize = 1024; // TODO: get actual size
            $record->imageDimensions = ['width' => 1920, 'height' => 1080]; // TODO: detect actual dimensions

            $this->storageService->save($record);

            error_log(sprintf('Image processing completed for GUID %s: %s', $guid, $publicUrl));

            // Return acknowledgment to Pub/Sub
            return $this->successResponse($response, 'Processing completed');

        } catch (\Exception $e) {
            error_log('Error processing image event: ' . $e->getMessage());
            return $this->successResponse($response, 'Processing error');
        }
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
