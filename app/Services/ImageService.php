<?php

namespace App\Services;

use App\Enums\UploadPurpose;
use App\Models\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

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

    /**
     * Strip the environment prefix a filename was stored with.
     *
     * Tolerates an unprefixed filename so files written before the prefix
     * existed still parse.
     */
    public static function unprefixFilename(string $filename): string
    {
        $prefix = self::getFilePrefix();

        if ($prefix && str_starts_with($filename, $prefix . '-')) {
            return substr($filename, strlen($prefix) + 1);
        }

        return $filename;
    }

    /**
     * Build the S3 key for a direct upload.
     *
     * The user id is in the key because it is the only thing tying a presigned
     * upload back to the account that asked for it. Nothing else about a
     * direct upload is server-controlled: the client picks the bytes and hands
     * the key back later to have an Image row created for it. Keep this and
     * ownerIdFromFilename() in step.
     *
     * $index separates the files of one batch, which would otherwise collide
     * on the shared second-resolution timestamp.
     */
    public static function uploadFilename(
        UploadPurpose $purpose,
        int $userId,
        string $extension,
        ?int $index = null
    ): string {
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);
        $suffix = $index === null ? $random : "{$index}_{$random}";

        return self::prefixFilename("{$purpose->value}_{$userId}_{$timestamp}_{$suffix}.{$extension}");
    }

    /**
     * The id of the user a direct-upload filename was issued to, or null if
     * the filename was not issued by uploadFilename().
     */
    public static function ownerIdFromFilename(string $filename): ?int
    {
        $purposes = implode('|', array_map(
            fn (string $value) => preg_quote($value, '/'),
            UploadPurpose::values()
        ));

        $pattern = '/^(?:' . $purposes . ')_(\d+)_\d+_/';

        if (! preg_match($pattern, self::unprefixFilename($filename), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Whether a client-supplied filename was issued to this user.
     *
     * A filename is public: it appears in every image URL the API returns. So
     * anything that accepts one from a client has to check it here before
     * treating the underlying object as the caller's to use.
     */
    public static function filenameBelongsToUser(string $filename, int $userId): bool
    {
        return self::ownerIdFromFilename($filename) === $userId;
    }

    /**
     * File extension for an upload content type. Anything unrecognised is
     * treated as a JPEG, which matches what the presign endpoints accept.
     */
    public static function extensionForContentType(string $contentType): string
    {
        return match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    public function processImage(mixed $input, string $filename): ?Image
    {
        try {
            if ($input instanceof UploadedFile) {
                // Handle uploaded file
                $imageData = file_get_contents($input->getRealPath());
                $mimeType = $input->getMimeType();
            } elseif ($this->isBase64String($input)) {
                // Handle base64 string
                if (preg_match('/^data:image\/(\w+);base64,/', $input, $matches)) {
                    $mimeType = 'image/' . $matches[1];
                    $input = substr($input, strpos($input, ',') + 1); // Strip the data URI prefix
                } else {
                    $mimeType = 'image/jpeg'; // Fallback MIME type
                }

                $imageData = base64_decode($input);
                if ($imageData === false) {
                    throw new \Exception("Could not decode base64 image data");
                }
            } else {
                throw new \Exception("Invalid image input type");
            }

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
     * Create Image rows for files a client has already uploaded to S3.
     *
     * Every filename is checked twice: that it was issued to this user, and
     * that the object is actually there. A filename that fails either check is
     * skipped rather than failing the batch, because the clients upload in
     * groups of ten and one bad file should not cost the other nine.
     *
     * @param  list<string>  $filenames
     * @return list<Image>
     */
    public function confirmUploads(int $userId, array $filenames): array
    {
        $images = [];

        foreach ($filenames as $filename) {
            if (! self::filenameBelongsToUser($filename, $userId)) {
                Log::warning('Rejected an upload confirmation for a filename issued to someone else', [
                    'filename' => $filename,
                    'user_id' => $userId,
                    'owner_id' => self::ownerIdFromFilename($filename),
                ]);
                continue;
            }

            if (! $this->s3->exists($filename)) {
                Log::warning('Uploaded file not found in S3', [
                    'filename' => $filename,
                    'user_id' => $userId,
                ]);
                continue;
            }

            $image = new Image([
                'filename' => $filename,
                'is_primary' => 0,
            ]);

            $image->setUriAttribute($filename);
            $image->save();

            $images[] = $image;
        }

        return $images;
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
