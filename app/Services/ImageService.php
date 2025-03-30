<?php

namespace App\Services;


use App\Models\Image;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Storage;

class ImageService
{

    protected $s3;

    protected $s3Path;

    public function __construct()
    {
        $this->s3 = Storage::disk('s3');
    }


    /**
     * @param $image
     * @param $filename
     * @return mixed
     */
    public function processImage($image, $filename): mixed
    {
        try {
            if ($image != null) {
                // Validate that the image is actually base64 encoded
                if (!preg_match('/^[a-zA-Z0-9\/+]*={0,2}$/', $image)) {
                    throw new \Exception("Invalid base64 format");
                }
                
                // Decode the base64 image
                $imageData = base64_decode($image);
                if (!$imageData) {
                    throw new \Exception("Could not decode base64 image data");
                }
                
                // Get mime type to add proper content-type
                $f = finfo_open();
                $mimeType = finfo_buffer($f, $imageData, FILEINFO_MIME_TYPE);
                finfo_close($f);
                
                // Upload to S3 with content-type header
                $this->s3->put($filename, $imageData, [
                    'visibility' => 'public',
                    'ContentType' => $mimeType,
                    'CacheControl' => 'max-age=31536000' // 1 year cache
                ]);
                
                // Save the image record in the database
                $image = $this->saveImage($filename);
            }

            return $image;

        } catch (\Exception $e) {
            $message = $e->getMessage() . " " . (basename($e->getFile())) . " " . $e->getLine();
            \Log::error($message);
            throw $e; // Re-throw so calling code can handle it
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

}
