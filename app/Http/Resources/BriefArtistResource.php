<?php

namespace App\Http\Resources;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\WorkingHoursResource;
use Illuminate\Http\Resources\Json\JsonResource;

class BriefArtistResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone,
            'slug' => $this->slug,
            'studio' => $this->studio->first()?->name ?? "",
            'username' => $this->username,
        ];
    }
}
