<?php

namespace App\Services;

use App\Models\ArtistSettings;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

class WatermarkService
{
    protected ImageManager $manager;

    public function __construct()
    {
        // Use Imagick driver if available (better format support), fall back to GD
        $this->manager = extension_loaded('imagick')
            ? new ImageManager(new ImagickDriver())
            : new ImageManager(new GdDriver());
    }

    /**
     * Apply watermark to an image and return a new watermarked image.
     */
    public function applyWatermark(Image $sourceImage, int $artistId): ?Image
    {
        $settings = ArtistSettings::where('artist_id', $artistId)
            ->with('watermarkImage')
            ->first();

        if (!$settings || !$settings->watermark_enabled || !$settings->watermarkImage) {
            return null;
        }

        try {
            // Fetch images
            $sourceData = $this->fetchImageData($sourceImage);
            if (!$sourceData) {
                \Log::error("Could not fetch source image for watermarking", ['image_id' => $sourceImage->id]);
                return null;
            }

            $watermarkData = $this->fetchImageData($settings->watermarkImage);
            if (!$watermarkData) {
                \Log::error("Could not fetch watermark image", ['image_id' => $settings->watermarkImage->id]);
                return null;
            }

            // Convert WebP to PNG if needed (for GD compatibility)
            $sourceFormat = $this->detectImageFormat($sourceData);
            if ($sourceFormat === 'webp') {
                $sourceData = $this->convertWebpToPng($sourceData);
                if (!$sourceData) {
                    return null;
                }
            }

            $watermarkFormat = $this->detectImageFormat($watermarkData);
            if ($watermarkFormat === 'webp') {
                $watermarkData = $this->convertWebpToPng($watermarkData);
                if (!$watermarkData) {
                    return null;
                }
            }

            // Load images
            $tempFile = tempnam(sys_get_temp_dir(), 'watermark_src_');
            file_put_contents($tempFile, $sourceData);
            $image = $this->manager->read($tempFile);
            @unlink($tempFile);

            $watermark = $this->manager->read($watermarkData);

            // Scale watermark to max 20% of source image
            $maxWatermarkWidth = (int) ($image->width() * 0.2);
            $maxWatermarkHeight = (int) ($image->height() * 0.2);
            if ($watermark->width() > $maxWatermarkWidth || $watermark->height() > $maxWatermarkHeight) {
                $watermark->scaleDown($maxWatermarkWidth, $maxWatermarkHeight);
            }

            // Calculate position and apply watermark
            $position = $this->calculatePosition(
                $image->width(),
                $image->height(),
                $watermark->width(),
                $watermark->height(),
                $settings->watermark_position
            );

            $image->place(
                $watermark,
                'top-left',
                $position['x'],
                $position['y'],
                (int) $settings->watermark_opacity
            );

            // Upload watermarked image to S3
            $newFilename = ImageService::prefixFilename("watermarked_{$artistId}_" . date('Ymdhis') . ".jpg");
            $encoded = $image->toJpeg(90);

            Storage::disk('s3')->put($newFilename, (string) $encoded, [
                'visibility' => 'public',
                'ContentType' => 'image/jpeg',
                'CacheControl' => 'max-age=31536000',
            ]);

            // Create and return new image record
            $newImage = new Image([
                'filename' => $newFilename,
                'is_primary' => 0,
            ]);
            $newImage->setUriAttribute($newFilename);
            $newImage->save();

            return $newImage;

        } catch (\Exception $e) {
            \Log::error("Error applying watermark: " . $e->getMessage(), [
                'source_image' => $sourceImage->id,
                'artist_id' => $artistId,
            ]);
            return null;
        }
    }

    /**
     * Fetch image data from URI (HTTP) or S3.
     */
    private function fetchImageData(Image $image): ?string
    {
        // Try HTTP first
        if ($image->uri && str_starts_with($image->uri, 'http')) {
            $context = stream_context_create([
                'http' => ['timeout' => 30],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $data = @file_get_contents($image->uri, false, $context);
            if ($data !== false && strlen($data) > 0) {
                return $data;
            }
        }

        // Fallback to S3
        if ($image->filename) {
            try {
                return Storage::disk('s3')->get($image->filename);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Convert WebP image data to PNG.
     */
    private function convertWebpToPng(string $webpData): ?string
    {
        if (!function_exists('imagecreatefromwebp')) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'webp_');
        file_put_contents($tempFile, $webpData);
        $image = @imagecreatefromwebp($tempFile);
        @unlink($tempFile);

        if (!$image) {
            return null;
        }

        imagesavealpha($image, true);
        imagealphablending($image, false);

        ob_start();
        imagepng($image, null, 9);
        $pngData = ob_get_clean();
        imagedestroy($image);

        return $pngData ?: null;
    }

    /**
     * Detect image format from magic bytes.
     */
    private function detectImageFormat(string $data): string
    {
        $hex = bin2hex(substr($data, 0, 4));

        if (str_starts_with($hex, 'ffd8ff')) {
            return 'jpeg';
        }
        if (str_starts_with($hex, '89504e47')) {
            return 'png';
        }
        if (str_starts_with($hex, '47494638')) {
            return 'gif';
        }
        if (str_starts_with($hex, '52494646') && substr(bin2hex(substr($data, 8, 4)), 0, 8) === '57454250') {
            return 'webp';
        }

        return 'unknown';
    }

    /**
     * Calculate x,y position for watermark.
     */
    private function calculatePosition(
        int $imageWidth,
        int $imageHeight,
        int $watermarkWidth,
        int $watermarkHeight,
        string $position
    ): array {
        $padding = 20;

        return match ($position) {
            'top-left' => ['x' => $padding, 'y' => $padding],
            'top-right' => ['x' => $imageWidth - $watermarkWidth - $padding, 'y' => $padding],
            'bottom-left' => ['x' => $padding, 'y' => $imageHeight - $watermarkHeight - $padding],
            'center' => [
                'x' => (int) (($imageWidth - $watermarkWidth) / 2),
                'y' => (int) (($imageHeight - $watermarkHeight) / 2),
            ],
            default => [ // bottom-right
                'x' => $imageWidth - $watermarkWidth - $padding,
                'y' => $imageHeight - $watermarkHeight - $padding,
            ],
        };
    }
}
