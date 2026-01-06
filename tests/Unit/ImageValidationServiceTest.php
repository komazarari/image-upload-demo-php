<?php
/**
 * Unit Tests for ImageValidationService
 */

declare(strict_types=1);

namespace ImageUploadDemo\Tests\Unit;

use ImageUploadDemo\Services\ImageValidationService;
use PHPUnit\Framework\TestCase;

class ImageValidationServiceTest extends TestCase
{
    private ImageValidationService $validationService;

    protected function setUp(): void
    {
        $this->validationService = new ImageValidationService();
    }

    /**
     * Test MIME type validation - allowed type
     */
    public function testValidateMimeTypeAllowed(): void
    {
        // Create a test image file
        $testFile = tempnam(sys_get_temp_dir(), 'test_');
        $this->createTestImage($testFile, 100, 100);

        $result = $this->validationService->validate($testFile, 'image/jpeg');

        // Test should pass - it's a valid JPEG
        $this->assertTrue($result->isValid);
        $this->assertFalse(in_array('MIME type not allowed', $result->errors));

        unlink($testFile);
    }

    /**
     * Test MIME type validation - not allowed type
     */
    public function testValidateMimeTypeNotAllowed(): void
    {
        // Create a test image file
        $testFile = tempnam(sys_get_temp_dir(), 'test_');
        $this->createTestImage($testFile, 100, 100);

        try {
            $result = $this->validationService->validate($testFile, 'application/pdf');

            $this->assertFalse($result->isValid);
            $errors = implode(' ', $result->errors);
            $this->assertStringContainsString('MIME type not allowed', $errors);
        } finally {
            @unlink($testFile);
        }
    }

    /**
     * Test file size validation - within limit
     */
    public function testValidateFileSizeWithinLimit(): void
    {
        $testFile = tempnam(sys_get_temp_dir(), 'test_');
        $this->createTestImage($testFile, 100, 100);

        try {
            $result = $this->validationService->validate($testFile, 'image/jpeg');

            $this->assertNotNull($result->fileSize);
            $this->assertLessThan(5242880, $result->fileSize);
        } finally {
            @unlink($testFile);
        }
    }

    /**
     * Test image dimensions validation - within limit
     */
    public function testValidateDimensionsWithinLimit(): void
    {
        $testFile = tempnam(sys_get_temp_dir(), 'test_');
        $this->createTestImage($testFile, 2000, 1500);

        try {
            $result = $this->validationService->validate($testFile, 'image/jpeg');

            $this->assertEquals(2000, $result->width);
            $this->assertEquals(1500, $result->height);
            $this->assertTrue($result->isValid);
        } finally {
            @unlink($testFile);
        }
    }

    /**
     * Test image dimensions validation - exceeds limit
     */
    public function testValidateDimensionsExceedsLimit(): void
    {
        $testFile = tempnam(sys_get_temp_dir(), 'test_');
        $this->createTestImage($testFile, 5000, 3500);

        try {
            $result = $this->validationService->validate($testFile, 'image/jpeg');

            $this->assertFalse($result->isValid);
            $errors = implode(' ', $result->errors);
            $this->assertStringContainsString('dimensions exceed maximum', $errors);
        } finally {
            @unlink($testFile);
        }
    }

    /**
     * Test getAllowedMimeTypes
     */
    public function testGetAllowedMimeTypes(): void
    {
        $types = $this->validationService->getAllowedMimeTypes();

        $this->assertIsArray($types);
        $this->assertContains('image/jpeg', $types);
        $this->assertContains('image/png', $types);
        $this->assertContains('image/gif', $types);
        $this->assertContains('image/webp', $types);
    }

    /**
     * Helper: Create a simple test image
     */
    private function createTestImage(string $path, int $width, int $height): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required for image creation');
        }

        $image = \imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('Failed to create image');
        }

        $color = \imagecolorallocate($image, 255, 0, 0);
        if ($color === false) {
            \imagedestroy($image);
            throw new \RuntimeException('Failed to allocate color');
        }

        \imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);

        if (!\imagejpeg($image, $path, 85)) {
            \imagedestroy($image);
            throw new \RuntimeException('Failed to save JPEG image');
        }

        \imagedestroy($image);
    }
}
