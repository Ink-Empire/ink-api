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
    public function processImage($image, $filename)
    {
        try {
            if ($image != null) {
                $this->s3->put($filename, file_get_contents($image), 'public');
                $image = $this->saveImage($filename);
            }

            return $image;

        } catch (\Exception $e) {
            $message = $e->getMessage() . " " . (basename($e->getFile())) . " " . $e->getLine();
            \Log::error($message);
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
