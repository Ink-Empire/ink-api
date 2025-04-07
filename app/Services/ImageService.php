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

            $this->s3->put($filename, $imageData, [
                'visibility' => 'public',
                'ContentType' => $mimeType,
                'CacheControl' => 'max-age=31536000'
            ]);

            return $this->saveImage($filename);

        } catch (\Exception $e) {
            \Log::error(
                $e->getMessage() . " in " . basename($e->getFile()) . " line " . $e->getLine());
            throw $e;
        }
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
