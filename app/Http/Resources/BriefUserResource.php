<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BriefUserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'username' => $this->username,
            'location' => $this->location,
            'type' => 'client',
            'primary_image' => $this->image ? new BriefImageResource($this->image) : null,
        ];
    }
}
