<?php
/**
 * Unit Tests for ImageConversionService
 */

declare(strict_types=1);

namespace ImageUploadDemo\Tests\Unit;

use ImageUploadDemo\Services\ImageConversionService;
use PHPUnit\Framework\TestCase;

class ImageConversionServiceTest extends TestCase
{
    private ImageConversionService $conversionService;

    protected function setUp(): void
    {
        $this->conversionService = new ImageConversionService();
    }

    /**
     * Test conversion with JPEG format
     */
    public function testConvertJpeg(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $inputFile = tempnam(sys_get_temp_dir(), 'input_');
        $outputFile = tempnam(sys_get_temp_dir(), 'output_');

        try {
            $this->createTestImage($inputFile, 200, 200);

            $result = $this->conversionService->convert($inputFile, $outputFile, 'image/jpeg');

            $this->assertTrue($result->isValid);
            $this->assertTrue($result->conversionApplied);
            $this->assertFileExists($outputFile);
            $this->assertGreaterThan(0, filesize($outputFile));
        } finally {
            @unlink($inputFile);
            @unlink($outputFile);
        }
    }

    /**
     * Test conversion with PNG format
     */
    public function testConvertPng(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $inputFile = tempnam(sys_get_temp_dir(), 'input_');
        $outputFile = tempnam(sys_get_temp_dir(), 'output_');

        try {
            $this->createTestImage($inputFile, 200, 200);

            $result = $this->conversionService->convert($inputFile, $outputFile, 'image/png');

            $this->assertTrue($result->isValid);
            $this->assertTrue($result->conversionApplied);
            $this->assertFileExists($outputFile);
        } finally {
            @unlink($inputFile);
            @unlink($outputFile);
        }
    }

    /**
     * Test conversion with non-existent input file
     */
    public function testConvertNonExistentFile(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $result = $this->conversionService->convert(
            '/nonexistent/file.jpg',
            '/tmp/output.jpg',
            'image/jpeg'
        );

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->conversionErrors);
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

        $color = \imagecolorallocate($image, 100, 150, 200);
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
