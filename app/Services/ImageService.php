<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class ImageService
{
    protected $s3;

    protected $s3Path;

    public function __construct()
    {
        $this->s3 = Storage::disk('s3');
    }

    /**
     * Get the S3 file prefix from config (e.g., 'dev' or 'production').
     */
    public static function getFilePrefix(): string
    {
        return config('filesystems.disks.s3.file_prefix', '');
    }

    /**
     * Generate a prefixed filename for S3 storage.
     * Prepends the environment prefix with a hyphen to distinguish dev/prod files.
     * e.g., "production-tattoo_123_..." or "dev-profile_456_..."
     */
    public static function prefixFilename(string $filename): string
    {
        $prefix = self::getFilePrefix();
        return $prefix ? $prefix . '-' . $filename : $filename;
    }

    public function processImage(mixed $input, string $filename): ?Image
    {
        try {
            if ($input instanceof UploadedFile) {
                // Handle uploaded file
                $imageData = file_get_contents($input->getRealPath());
            } elseif ($this->isBase64String($input)) {
                // Handle base64 string
                if (preg_match('/^data:image\/(\w+);base64,/', $input)) {
                    $input = substr($input, strpos($input, ',') + 1); // Strip the data URI prefix
                }

                $imageData = base64_decode($input, true);
                if ($imageData === false) {
                    throw new \Exception("Could not decode base64 image data");
                }
            } else {
                throw new \Exception("Invalid image input type");
            }

            $mimeType = $this->verifiedMimeType($imageData);

            // Add environment prefix to filename
            $prefixedFilename = self::prefixFilename($filename);

            $this->s3->put($prefixedFilename, $imageData, [
                'visibility' => 'public',
                'ContentType' => $mimeType,
                'CacheControl' => 'max-age=31536000'
            ]);

            return $this->saveImage($prefixedFilename);

        } catch (\Exception $e) {
            \Log::error(
                $e->getMessage() . " in " . basename($e->getFile()) . " line " . $e->getLine());
            throw $e;
        }
    }

    /**
     * Derive the MIME type from the image bytes themselves.
     *
     * The declared type is never trusted, whether it arrived as a data URI
     * prefix from the client or as the MIME type of an UploadedFile. Anything
     * that does not read back as an image is rejected rather than stored.
     */
    private function verifiedMimeType(string $imageData): string
    {
        if ($imageData === '') {
            throw new \Exception("Image data is empty");
        }

        $info = @getimagesizefromstring($imageData);

        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new \Exception("Image data is not a readable image");
        }

        $mimeType = image_type_to_mime_type($info[2]);

        // getimagesizefromstring also reads non-image formats such as SWF.
        if (!str_starts_with($mimeType, 'image/')) {
            throw new \Exception("Image data is not a readable image");
        }

        return $mimeType;
    }

    private function saveImage(string $filename)
    {
        $image = new Image([
            'filename' => $filename,
            'is_primary' => 1,
        ]);

        $image->setUriAttribute($filename);

        $image->save();

        return $image;
    }

    private function isBase64String($input): bool
    {
        if (!is_string($input)) {
            return false;
        }

        // Detect data URI base64
        if (preg_match('/^data:image\/(\w+);base64,/', $input)) {
            return true;
        }

        // Fallback: clean string and validate
        $decoded = base64_decode($input, true);
        return $decoded !== false && base64_encode($decoded) === $input;
    }

}
