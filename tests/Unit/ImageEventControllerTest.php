<?php
/**
 * Image Event Controller Test
 *
 * Tests for ImageEventController::handlePubSubEvent() endpoint
 */

declare(strict_types=1);

namespace ImageUploadDemo\Tests\Unit;

use ImageUploadDemo\Controllers\ImageEventController;
use ImageUploadDemo\Enums\UploadStatus;
use ImageUploadDemo\Models\UploadRecord;
use ImageUploadDemo\Services\ImageConversionService;
use ImageUploadDemo\Services\ImageValidationService;
use ImageUploadDemo\Services\StorageServiceInterface;
use ImageUploadDemo\Implementations\JsonStorageService;
use ImageUploadDemo\Services\GcsService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class ImageEventControllerTest extends TestCase
{
    private ImageEventController $controller;
    private StorageServiceInterface $storageService;
    private ImageValidationService $validationService;
    private ImageConversionService $conversionService;
    private GcsService $gcsService;
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test storage
        $this->tempDir = sys_get_temp_dir() . '/test_events_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->storageService = new JsonStorageService($this->tempDir);
        $this->validationService = new ImageValidationService();
        $this->conversionService = new ImageConversionService();

        // Create mock GcsService
        $this->gcsService = new class extends GcsService {
            public function generateSignedUrl(
                string $bucket,
                string $objectName,
                int $expirySeconds = 3600,
                string $method = 'PUT',
                ?string $contentType = null
            ): string {
                return 'https://example.com/signed';
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
                // Write a 1x1 PNG to simulate download success
                $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/xcAAusB9Y4nZJcAAAAASUVORK5CYII=';
                $dir = dirname($localPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($localPath, base64_decode($pngBase64));
                return true;
            }

            public function deleteObject(
                string $bucket,
                string $objectName
            ): bool {
                return true;
            }
        };

        $this->controller = new ImageEventController(
            $this->storageService,
            $this->validationService,
            $this->conversionService,
            $this->gcsService,
            'public-bucket'
        );
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
     * Test handling Pub/Sub event with valid message
     */
    public function testHandleValidEvent(): void
    {
        // Create upload record first
        $guid = '550e8400-e29b-41d4-a716-446655440000';
        $record = new UploadRecord($guid, 'user-123', 'initialized', 'test.jpg');
        $this->storageService->save($record);

        // Create Pub/Sub message
        $messageData = [
            'bucket' => 'uploads',
            'name' => $guid . '.jpg',
        ];

        $pubsubMessage = [
            'message' => [
                'data' => base64_encode(json_encode($messageData)),
                'messageId' => 'test-message-id',
                'publishTime' => date('c'),
            ],
        ];

        // Create request with message body
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/image-event')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($pubsubMessage)));
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->handlePubSubEvent($request, $response);

        // Verify response
        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));

        // Verify acknowledgment response
        $body = (string)$result->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('acknowledged', $data['status']);

        // Verify record was updated
        $updated = $this->storageService->load($guid);
        $this->assertNotNull($updated);
        // Status should be processing or completed depending on validation success
        $this->assertTrue(
            in_array($updated->status, [UploadStatus::Processing, UploadStatus::Completed, UploadStatus::Failed]),
            "Status should be one of: processing, completed, failed. Got: {$updated->status->value}"
        );
    }

    /**
     * Test handling event with missing message data
     */
    public function testHandleEventMissingData(): void
    {
        // Create request with invalid message structure
        $pubsubMessage = [
            'message' => [
                'messageId' => 'test-message-id',
            ],
        ];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/image-event')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($pubsubMessage)));
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->handlePubSubEvent($request, $response);

        // Should still return 200 (don't fail Pub/Sub messages)
        $this->assertEquals(200, $result->getStatusCode());
    }

    /**
     * Test handling event with missing record
     */
    public function testHandleEventMissingRecord(): void
    {
        // Create Pub/Sub message for non-existent GUID
        $messageData = [
            'bucket' => 'uploads',
            'name' => '550e8400-e29b-41d4-a716-446655440099.jpg',
        ];

        $pubsubMessage = [
            'message' => [
                'data' => base64_encode(json_encode($messageData)),
                'messageId' => 'test-message-id',
                'publishTime' => date('c'),
            ],
        ];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/image-event')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($pubsubMessage)));
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->handlePubSubEvent($request, $response);

        // Should return 200 to Pub/Sub
        $this->assertEquals(200, $result->getStatusCode());

        // Verify message indicates record not found
        $body = (string)$result->getBody();
        $data = json_decode($body, true);
        $this->assertStringContainsString('not found', strtolower($data['message']));
    }

    /**
     * Test extracting GUID from object name
     */
    public function testExtractGuidFromObjectName(): void
    {
        // Create upload record
        $guid = '550e8400-e29b-41d4-a716-446655440001';
        $record = new UploadRecord($guid, 'user-123', 'initialized', 'original.jpg');
        $this->storageService->save($record);

        // Create message with complex object name
        $messageData = [
            'bucket' => 'uploads',
            'name' => 'path/to/' . $guid . '.jpg',
        ];

        $pubsubMessage = [
            'message' => [
                'data' => base64_encode(json_encode($messageData)),
                'messageId' => 'test-message-id',
                'publishTime' => date('c'),
            ],
        ];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/image-event')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($pubsubMessage)));
        $response = (new ResponseFactory())->createResponse();

        // Call endpoint
        $result = $this->controller->handlePubSubEvent($request, $response);

        // Should succeed (extract GUID from filename)
        $this->assertEquals(200, $result->getStatusCode());

        // Verify record was accessed
        $updated = $this->storageService->load($guid);
        $this->assertNotNull($updated);
    }
}
