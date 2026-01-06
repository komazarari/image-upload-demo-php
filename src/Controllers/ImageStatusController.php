<?php
/**
 * Image Status Controller
 *
 * Handles HTTP GET requests to `/images/{guid}/status`.
 * Returns the current status of an image upload, including whether it's been
 * processed, any errors encountered, and the public URL once available.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Controllers;

use ImageUploadDemo\Enums\UploadStatus;

use ImageUploadDemo\Services\StorageService;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ImageStatusController
{
    /**
     * Constructor
     *
     * @param StorageService $storageService Service for retrieving upload records
     */
    public function __construct(
        private StorageService $storageService,
    ) {
    }

    /**
     * Handle GET /images/{guid}/status
     *
     * Returns the current status of an upload record, including:
     * - Current status (initialized, processing, completed, failed)
     * - Public URL (if completed)
     * - Error message (if failed)
     * - Timestamps
     *
     * @param Request $request HTTP request
     * @param Response $response HTTP response
     * @param array<string, string> $args Route arguments including 'guid'
     * @return Response JSON response with status information
     */
    public function getStatus(Request $request, Response $response, array $args): Response
    {
        try {
            $guid = $args['guid'] ?? '';

            // Validate GUID format (basic check)
            if (empty($guid) || !$this->isValidGuid($guid)) {
                return $this->errorResponse($response, 400, 'Invalid GUID format');
            }

            // Load record from storage
            $record = $this->storageService->load($guid);

            if ($record === null) {
                return $this->errorResponse($response, 404, 'Upload record not found');
            }

            // Build response based on status
            $statusData = [
                'guid' => $record->guid,
                'status' => $record->status->value,
                'createdAt' => $record->createdAt,
                'processedAt' => $record->processedAt,
            ];

            // Add fields based on status
            if ($record->status === UploadStatus::Completed) {
                $statusData['publicUrl'] = $record->publicUrl;
                $statusData['contentType'] = $record->contentType;
                $statusData['fileSize'] = $record->fileSize;
                $statusData['imageDimensions'] = $record->imageDimensions;
            } elseif ($record->status === UploadStatus::Failed) {
                $statusData['error'] = $record->errorMessage;
            }

            $response->getBody()->write(json_encode($statusData));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (\Exception $e) {
            error_log('Error in getStatus: ' . $e->getMessage());
            return $this->errorResponse($response, 500, 'Internal server error');
        }
    }

    /**
     * Validate GUID format (UUID v4)
     *
     * @param string $guid GUID to validate
     * @return bool True if valid UUID v4 format
     */
    private function isValidGuid(string $guid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $guid
        ) === 1;
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
