<?php
/**
 * Image Conversion Service
 *
 * Handles image conversion and sanitization using ImageMagick via Imagick.
 * Removes EXIF metadata and re-encodes images to prevent embedded exploits.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Services;

use ImageUploadDemo\Models\ImageValidation;

class ImageConversionService
{
    /**
     * Image quality for lossy formats (0-100)
     */
    private int $quality;

    /**
     * Constructor
     *
     * @param int $quality JPEG/WebP quality level (default: 85)
     */
    public function __construct(int $quality = 85)
    {
        $this->quality = max(1, min(100, $quality));
    }

    /**
     * Convert and sanitize image
     *
     * Removes EXIF metadata and re-encodes to prevent embedded code.
     *
     * @param string $inputPath Path to input image file
     * @param string $outputPath Path to output image file
     * @param string $mimeType MIME type of image
     * @return ImageValidation Conversion result
     */
    public function convert(string $inputPath, string $outputPath, string $mimeType): ImageValidation
    {
        $validation = new ImageValidation();

        try {
            // Check if Imagick extension is available
            if (!extension_loaded('imagick')) {
                $validation->addConversionError('Imagick extension not loaded');
                return $validation;
            }

            // Create Imagick instance
            $image = new \Imagick($inputPath);

            // Remove EXIF and all profiles (metadata)
            $image->stripImage();

            // Reduce colors for smaller file size (optional, can be tuned)
            // $image->quantizeImage(256, \Imagick::COLORSPACE_RGB, 0, false, false);

            // Set compression quality for lossy formats
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality($this->quality);

            // Determine output format based on MIME type
            $format = $this->getMagickFormat($mimeType);
            $image->setImageFormat($format);

            // Write to output
            if (!$image->writeImage($outputPath)) {
                $validation->addConversionError('Failed to write image to: ' . $outputPath);
                $image->destroy();
                return $validation;
            }

            $validation->conversionApplied = true;
            $image->destroy();

            // Verify output file exists and has content
            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                $validation->addConversionError('Output file is empty or missing');
                return $validation;
            }

            $validation->isValid = true;
            return $validation;
        } catch (\Exception $e) {
            $validation->addConversionError('ImageMagick error: ' . $e->getMessage());
            return $validation;
        }
    }

    /**
     * Get ImageMagick format string from MIME type
     *
     * @param string $mimeType MIME type (e.g., 'image/jpeg')
     * @return string ImageMagick format (e.g., 'JPEG')
     */
    private function getMagickFormat(string $mimeType): string
    {
        $formats = [
            'image/jpeg' => 'JPEG',
            'image/jpg' => 'JPEG',
            'image/png' => 'PNG',
            'image/gif' => 'GIF',
            'image/webp' => 'WEBP',
        ];

        return $formats[$mimeType] ?? 'JPEG';
    }
}
