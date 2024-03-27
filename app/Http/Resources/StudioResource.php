<?php

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Resources\Json\JsonResource;

class StudioResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'about' => $this->about,
            'location' => $this->location,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->getImage(),
            'is_verified' => $this->is_verified
        ];
    }

    private function getImage()
    {
        if(!$this->image_id){
            $image = new Image();
            $image->setUriAttribute(); //i wish this would automatically trigger but it wont unless you set it
            return $image;
        } else {
            return $this->image;
        }
    }
}
