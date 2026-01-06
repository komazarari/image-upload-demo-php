<?php
/**
 * Image Upload Controller Test
 *
 * Tests for ImageUploadController::uploadRequest() endpoint
 */

declare(strict_types=1);

namespace ImageUploadDemo\Tests\Unit;

use ImageUploadDemo\Controllers\ImageUploadController;
use ImageUploadDemo\Enums\UploadStatus;
use ImageUploadDemo\Services\GcsService;
use ImageUploadDemo\Services\StorageService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class ImageUploadControllerTest extends TestCase
{
    private ImageUploadController $controller;
    private StorageService $storageService;
    private GcsService $gcsService;
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test storage
        $this->tempDir = sys_get_temp_dir() . '/test_uploads_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Create services with test directory
        $this->storageService = new StorageService($this->tempDir);

        // Create mock GcsService
        $this->gcsService = new class extends GcsService {
            public function generateSignedUrl(
                string $bucket,
                string $objectName,
                int $expirySeconds = 3600,
                string $method = 'PUT'
            ): string {
                return sprintf('https://storage.googleapis.com/test/%s?signature=test', $objectName);
            }

            public function copyObject(
                string $sourceBucket,
                string $sourceObject,
                string $destBucket,
                string $destObject
            ): bool {
                return true;
            }

            public function downloadObject(
                string $bucket,
                string $objectName,
                string $localPath
            ): bool {
                return true;
            }

            public function deleteObject(
                string $bucket,
                string $objectName
            ): bool {
                return true;
            }
        };

        // Create controller
        $this->controller = new ImageUploadController($this->storageService, $this->gcsService);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*.json'));
            rmdir($this->tempDir);
        }
    }

    /**
     * Test successful upload request
     */
    public function testUploadRequestSuccessful(): void
    {
        // Create request and response
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/images/upload-request');
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->uploadRequest($request, $response);

        // Verify response status
        $this->assertEquals(200, $result->getStatusCode());

        // Verify response content type
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));

        // Verify response body
        $body = (string)$result->getBody();
        $data = json_decode($body, true);

        // Check required fields
        $this->assertArrayHasKey('guid', $data);
        $this->assertArrayHasKey('signedUrl', $data);
        $this->assertArrayHasKey('expiresAt', $data);

        // Verify GUID format (UUID v4)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $data['guid']
        );

        // Verify signed URL
        $this->assertStringStartsWith('https://storage.googleapis.com/', $data['signedUrl']);

        // Verify expiration time is in future
        $this->assertGreaterThan(time(), $data['expiresAt']);

        // Verify record was persisted
        $this->assertTrue($this->storageService->exists($data['guid']));

        // Load and verify record
        $record = $this->storageService->load($data['guid']);
        $this->assertNotNull($record);
        $this->assertEquals(UploadStatus::Initialized, $record->status);
    }

    /**
     * Test upload request with custom user ID
     */
    public function testUploadRequestWithUserId(): void
    {
        // Create request with user ID header
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/images/upload-request')
            ->withHeader('X-User-ID', 'user-123');
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->uploadRequest($request, $response);

        // Verify success
        $this->assertEquals(200, $result->getStatusCode());

        // Verify user ID was stored
        $body = (string)$result->getBody();
        $data = json_decode($body, true);
        $record = $this->storageService->load($data['guid']);
        $this->assertEquals('user-123', $record->userId);
    }

    /**
     * Test upload request creates record with correct metadata
     */
    public function testUploadRequestMetadata(): void
    {
        // Create request
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/images/upload-request');
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->uploadRequest($request, $response);

        // Extract GUID from response
        $body = (string)$result->getBody();
        $data = json_decode($body, true);
        $guid = $data['guid'];

        // Load and verify record
        $record = $this->storageService->load($guid);
        $this->assertNotNull($record);

        // Verify record fields
        $this->assertEquals(UploadStatus::Initialized, $record->status);
        $this->assertNotNull($record->createdAt);
        $this->assertNull($record->processedAt);
        $this->assertNull($record->errorMessage);
    }
}
