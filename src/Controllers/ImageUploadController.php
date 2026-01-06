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
use ImageUploadDemo\Services\StorageService;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Ramsey\Uuid\Uuid;

class ImageUploadController
{
    /**
     * Constructor
     *
     * @param StorageService $storageService Service for persisting upload records
     * @param GcsService $gcsService Service for generating signed URLs
     */
    public function __construct(
        private StorageService $storageService,
        private GcsService $gcsService,
    ) {
    }

    /**
     * Handle GET /images/upload-request
     *
     * Returns a GUID and signed URL for client to use when uploading image.
     * The signed URL is valid for a limited time and restricted to a specific
     * GCS bucket and object name.
     *
     * @param Request $request HTTP request
     * @param Response $response HTTP response
     * @return Response JSON response with GUID, signedUrl, expiresAt
     */
    public function uploadRequest(Request $request, Response $response): Response
    {
        try {
            // Generate a unique GUID for this upload session
            $guid = Uuid::uuid4()->toString();

            // Create upload record with initialized status
            $uploadRecord = new UploadRecord(
                $guid,
                $this->getUserId($request),
                'initialized',
                'not-yet-provided'
            );

            // Persist to storage
            if (!$this->storageService->save($uploadRecord)) {
                return $this->errorResponse($response, 500, 'Failed to initialize upload');
            }

            // Generate signed URL for GCS bucket
            // In production, this would be generated via GcsServiceImpl
            // For now, we return a placeholder
            $signedUrl = $this->gcsService->generateSignedUrl(
                getenv('GCS_UPLOAD_BUCKET') ?: 'uploads',
                $guid . '.jpg',
                300 // 5 minutes
            );

            $expiresAt = time() + 300;

            // Return JSON response
            $data = [
                'guid' => $guid,
                'signedUrl' => $signedUrl,
                'expiresAt' => $expiresAt,
            ];

            $response->getBody()->write(json_encode($data));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (\Exception $e) {
            error_log('Error in uploadRequest: ' . $e->getMessage());
            return $this->errorResponse($response, 500, 'Internal server error');
        }
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
