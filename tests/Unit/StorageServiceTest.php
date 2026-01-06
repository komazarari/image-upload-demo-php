<?php
/**
 * Unit Tests for StorageService
 */

declare(strict_types=1);

namespace ImageUploadDemo\Tests\Unit;

use ImageUploadDemo\Enums\UploadStatus;
use ImageUploadDemo\Models\UploadRecord;
use ImageUploadDemo\Services\StorageService;
use PHPUnit\Framework\TestCase;

class StorageServiceTest extends TestCase
{
    private StorageService $storageService;
    private string $testStorageDir;

    protected function setUp(): void
    {
        $this->testStorageDir = sys_get_temp_dir() . '/image-upload-test-' . uniqid();
        mkdir($this->testStorageDir, 0755, true);
        $this->storageService = new StorageService($this->testStorageDir);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $files = glob($this->testStorageDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->testStorageDir);
    }

    /**
     * Test save and load record
     */
    public function testSaveAndLoadRecord(): void
    {
        $record = new UploadRecord(
            'test-guid-123',
            'user-456',
            'initialized',
            'https://example.com/signed-url'
        );
        $record->originalFilename = 'photo.jpg';

        // Save
        $result = $this->storageService->save($record);
        $this->assertTrue($result);

        // Load
        $loaded = $this->storageService->load('test-guid-123');
        $this->assertNotNull($loaded);
        $this->assertEquals('test-guid-123', $loaded->guid);
        $this->assertEquals('user-456', $loaded->userId);
        $this->assertEquals(UploadStatus::Initialized, $loaded->status);
        $this->assertEquals('photo.jpg', $loaded->originalFilename);
    }

    /**
     * Test load non-existent record
     */
    public function testLoadNonExistentRecord(): void
    {
        $loaded = $this->storageService->load('nonexistent-guid');
        $this->assertNull($loaded);
    }

    /**
     * Test exists check
     */
    public function testExists(): void
    {
        $record = new UploadRecord('test-guid', 'user-id', 'initialized', 'url');
        $this->storageService->save($record);

        $this->assertTrue($this->storageService->exists('test-guid'));
        $this->assertFalse($this->storageService->exists('nonexistent'));
    }

    /**
     * Test delete record
     */
    public function testDeleteRecord(): void
    {
        $record = new UploadRecord('test-guid', 'user-id', 'initialized', 'url');
        $this->storageService->save($record);

        $this->assertTrue($this->storageService->exists('test-guid'));

        $result = $this->storageService->delete('test-guid');
        $this->assertTrue($result);
        $this->assertFalse($this->storageService->exists('test-guid'));
    }

    /**
     * Test getAllGuids
     */
    public function testGetAllGuids(): void
    {
        // Create multiple records with valid UUIDs
        $guids = [
            '550e8400-e29b-41d4-a716-446655440000',
            '550e8400-e29b-41d4-a716-446655440001',
            '550e8400-e29b-41d4-a716-446655440002'
        ];

        foreach ($guids as $guid) {
            $record = new UploadRecord(
                $guid,
                'user-123',
                'initialized',
                'test-file.jpg'
            );
            $this->storageService->save($record);
        }

        $savedGuids = $this->storageService->getAllGuids();

        $this->assertCount(3, $savedGuids);
        foreach ($guids as $guid) {
            $this->assertContains($guid, $savedGuids, "GUID $guid not found in saved guids");
        }
    }

    /**
     * Test record state transitions
     */
    public function testRecordStateTransitions(): void
    {
        $record = new UploadRecord('test-guid', 'user-id', 'initialized', 'url');
        $this->storageService->save($record);

        // Load and update to processing
        $loaded = $this->storageService->load('test-guid');
        $this->assertNotNull($loaded);
        $loaded->markProcessing();
        $this->storageService->save($loaded);

        // Verify state persisted
        $reloaded = $this->storageService->load('test-guid');
        $this->assertNotNull($reloaded);
        $this->assertEquals(UploadStatus::Processing, $reloaded->status);

        // Mark as completed
        $reloaded->markCompleted('https://public-url.com/image.jpg');
        $this->storageService->save($reloaded);

        // Verify completed state
        $final = $this->storageService->load('test-guid');
        $this->assertNotNull($final);
        $this->assertEquals(UploadStatus::Completed, $final->status);
        $this->assertEquals('https://public-url.com/image.jpg', $final->publicUrl);
        $this->assertNotNull($final->processedAt);
    }
}
