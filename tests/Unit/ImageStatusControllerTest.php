<?php
/**
 * Image Status Controller Test
 *
 * Tests for ImageStatusController::getStatus() endpoint
 */

declare(strict_types=1);

namespace ImageUploadDemo\Tests\Unit;

use ImageUploadDemo\Controllers\ImageStatusController;
use ImageUploadDemo\Enums\UploadStatus;
use ImageUploadDemo\Models\UploadRecord;
use ImageUploadDemo\Services\StorageServiceInterface;
use ImageUploadDemo\Implementations\JsonStorageService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class ImageStatusControllerTest extends TestCase
{
    private ImageStatusController $controller;
    private StorageServiceInterface $storageService;
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test storage
        $this->tempDir = sys_get_temp_dir() . '/test_status_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->storageService = new JsonStorageService($this->tempDir);
        $this->controller = new ImageStatusController($this->storageService);
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
     * Test get status for initialized upload
     */
    public function testGetStatusInitialized(): void
    {
        // Create and store a record
        $guid = '550e8400-e29b-41d4-a716-446655440000';
        $record = new UploadRecord($guid, 'user-123', 'initialized', 'test.jpg');
        $this->storageService->save($record);

        // Create request and response
        $request = (new ServerRequestFactory())->createServerRequest('GET', "/images/{$guid}/status");
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->getStatus($request, $response, ['guid' => $guid]);

        // Verify response
        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));

        // Verify response body
        $body = (string)$result->getBody();
        $data = json_decode($body, true);

        $this->assertEquals('initialized', $data['status']);
        $this->assertEquals($guid, $data['guid']);
        $this->assertNotNull($data['createdAt']);
        $this->assertNull($data['processedAt']);
    }

    /**
     * Test get status for completed upload
     */
    public function testGetStatusCompleted(): void
    {
        // Create and store a completed record
        $guid = '550e8400-e29b-41d4-a716-446655440001';
        $record = new UploadRecord($guid, 'user-123', 'initialized', 'test.jpg');
        // Use 'processing' state before completing to satisfy transition rules
        $record->status = UploadStatus::Processing;
        $record->markCompleted('https://public-url.com/image.jpg');
        $record->contentType = 'image/jpeg';
        $record->fileSize = 1024;
        $record->imageDimensions = ['width' => 800, 'height' => 600];
        $this->storageService->save($record);

        // Create request and response
        $request = (new ServerRequestFactory())->createServerRequest('GET', "/images/{$guid}/status");
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->getStatus($request, $response, ['guid' => $guid]);

        // Verify response
        $this->assertEquals(200, $result->getStatusCode());

        // Verify response body
        $body = (string)$result->getBody();
        $data = json_decode($body, true);

        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('https://public-url.com/image.jpg', $data['publicUrl']);
        $this->assertEquals('image/jpeg', $data['contentType']);
        $this->assertEquals(1024, $data['fileSize']);
        $this->assertIsArray($data['imageDimensions']);
        $this->assertEquals(800, $data['imageDimensions']['width']);
        $this->assertEquals(600, $data['imageDimensions']['height']);
        $this->assertNotNull($data['processedAt']);
    }

    /**
     * Test get status for failed upload
     */
    public function testGetStatusFailed(): void
    {
        // Create and store a failed record
        $guid = '550e8400-e29b-41d4-a716-446655440002';
        $record = new UploadRecord($guid, 'user-123', 'initialized', 'test.jpg');
        $record->markFailed('Invalid image format');
        $this->storageService->save($record);

        // Create request and response
        $request = (new ServerRequestFactory())->createServerRequest('GET', "/images/{$guid}/status");
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->getStatus($request, $response, ['guid' => $guid]);

        // Verify response
        $this->assertEquals(200, $result->getStatusCode());

        // Verify response body
        $body = (string)$result->getBody();
        $data = json_decode($body, true);

        $this->assertEquals('failed', $data['status']);
        $this->assertEquals('Invalid image format', $data['error']);
    }

    /**
     * Test get status for non-existent record
     */
    public function testGetStatusNotFound(): void
    {
        // Use non-existent GUID
        $guid = '550e8400-e29b-41d4-a716-446655440099';

        // Create request and response
        $request = (new ServerRequestFactory())->createServerRequest('GET', "/images/{$guid}/status");
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->getStatus($request, $response, ['guid' => $guid]);

        // Verify response
        $this->assertEquals(404, $result->getStatusCode());

        // Verify error message
        $body = (string)$result->getBody();
        $data = json_decode($body, true);
        $this->assertStringContainsString('not found', $data['error']);
    }

    /**
     * Test get status with invalid GUID format
     */
    public function testGetStatusInvalidGuid(): void
    {
        // Use invalid GUID format
        $invalidGuid = 'not-a-valid-uuid';

        // Create request and response
        $request = (new ServerRequestFactory())->createServerRequest('GET', "/images/{$invalidGuid}/status");
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->getStatus($request, $response, ['guid' => $invalidGuid]);

        // Verify response
        $this->assertEquals(400, $result->getStatusCode());

        // Verify error message
        $body = (string)$result->getBody();
        $data = json_decode($body, true);
        $this->assertStringContainsString('Invalid', $data['error']);
    }

    /**
     * Test get status with missing GUID
     */
    public function testGetStatusMissingGuid(): void
    {
        // Create request with no GUID
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/images//status');
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint with empty GUID
        $result = $this->controller->getStatus($request, $response, ['guid' => '']);

        // Verify response is 400
        $this->assertEquals(400, $result->getStatusCode());
    }
}
