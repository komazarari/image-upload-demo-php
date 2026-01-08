<?php
/**
 * Image Upload Controller
 *
 * Handles HTTP POST requests to `/images/upload-request`.
 * Validates the upload request and returns a signed URL and GUID for the client
 * to use for subsequent uploads.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Controllers;

use ImageUploadDemo\Models\UploadRecord;
use ImageUploadDemo\Services\GcsService;
use ImageUploadDemo\Services\StorageServiceInterface;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Ramsey\Uuid\Uuid;

class ImageUploadController
{
    /**
     * Constructor
     *
     * @param StorageServiceInterface $storageService Storage service for upload records
     * @param GcsService $gcsService GCS service for signed URL generation
     * @param string $uploadBucket GCS bucket name
     * @param int $signedUrlExpiry Signed URL expiry time in seconds
     */
    public function __construct(
        private StorageServiceInterface $storageService,
        private GcsService $gcsService,
        private string $uploadBucket,
        private int $signedUrlExpiry
    ) {}

    /**
     * POST /images/upload-request
     *
     * Returns a GUID and signed URL for client to use when uploading image.
     * The signed URL is valid for a limited time and restricted to a specific
     * GCS bucket and object name.
     *
     * Request body (optional):
     * {
     *   "contentType": "image/jpeg"  // Optional, defaults to image/jpeg
     * }
     *
     * @param Request $request HTTP request
     * @param Response $response HTTP response
     * @return Response JSON response with GUID, signedUrl, expiresAt
     */
    public function uploadRequest(Request $request, Response $response): Response
    {
        try {
            // Parse request body
            $body = (string) $request->getBody();
            $data = !empty($body) ? json_decode($body, true) : [];
            
            // Validate content type (allow only images)
            $contentType = $data['contentType'] ?? 'image/jpeg';
            if (!$this->isValidImageContentType($contentType)) {
                return $this->errorResponse($response, 400, 'Invalid content type. Only image/* is allowed.');
            }

            // Generate a unique GUID for this upload session
            $guid = Uuid::uuid4()->toString();

            // Determine file extension from content type
            $extension = $this->getExtensionFromContentType($contentType);
            $objectName = $guid . $extension;

            // Create upload record with initialized status
            $uploadRecord = new UploadRecord(
                $guid,
                $this->getUserId($request),
                'initialized',
                'pending-upload'
            );

            // Persist to storage
            if (!$this->storageService->save($uploadRecord)) {
                error_log(sprintf('Failed to save upload record for GUID: %s', $guid));
                return $this->errorResponse($response, 500, 'Failed to initialize upload');
            }

            // Generate signed URL for GCS bucket
            $signedUrl = $this->gcsService->generateSignedUrl(
                $this->uploadBucket,
                $objectName,
                $this->signedUrlExpiry,
                'PUT'
            );

            $expiresAt = time() + $this->signedUrlExpiry;

            // Log successful URL generation
            error_log(json_encode([
                'event' => 'upload_request_created',
                'guid' => $guid,
                'bucket' => $this->uploadBucket,
                'object' => $objectName,
                'expiresIn' => $this->signedUrlExpiry,
            ]));

            // Return JSON response
            $responseData = [
                'guid' => $guid,
                'signedUrl' => $signedUrl,
                'expiresAt' => $expiresAt,
                'uploadBucket' => $this->uploadBucket,
                'objectName' => $objectName,
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (\Exception $e) {
            error_log(sprintf('Error in uploadRequest: %s', $e->getMessage()));
            error_log(sprintf('Stack trace: %s', $e->getTraceAsString()));
            return $this->errorResponse($response, 500, 'Internal server error');
        }
    }

    /**
     * Validate if content type is a valid image type
     *
     * @param string $contentType Content type to validate
     * @return bool True if valid image content type
     */
    private function isValidImageContentType(string $contentType): bool
    {
        $allowedTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
        ];

        return in_array(strtolower($contentType), $allowedTypes, true);
    }

    /**
     * Get file extension from content type
     *
     * @param string $contentType Content type
     * @return string File extension with leading dot
     */
    private function getExtensionFromContentType(string $contentType): string
    {
        return match (strtolower($contentType)) {
            'image/jpeg', 'image/jpg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            default => '.jpg',
        };
    }

    /**
     * Extract user ID from request (from header or session)
     * TODO: Replace with actual authentication
     *
     * @param Request $request HTTP request
     * @return string User ID
     */
    private function getUserId(Request $request): string
    {
        // In production, this would come from JWT token or session
        $userId = $request->getHeaderLine('X-User-ID');
        return !empty($userId) ? $userId : 'anonymous';
    }

    /**
     * Return error response as JSON
     *
     * @param Response $response HTTP response
     * @param int $statusCode HTTP status code
     * @param string $message Error message
     * @return Response JSON error response
     */
    private function errorResponse(Response $response, int $statusCode, string $message): Response
    {
        $data = [
            'error' => $message,
            'status' => $statusCode,
        ];

        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
}
