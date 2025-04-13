<?php

namespace App\Http\Resources;

use App\Http\Resources\Elastic\ArtistResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use JamesMills\LaravelTimezone\Timezone;
use App\Http\Resources\BriefArtistResource;

class AppointmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
//            'start' => Timezone::convertToLocal($this->start),
//            'end' => Timezone::convertToLocal($this->end),
            'start' => $this->start_time,
            'end' => $this->end_time,
            'allDay' => $this->all_day,
            'extendedProps' => [
                'status' => $this->status,
                'description' => $this->description,
                'clientName' => $this->client->name,
                'artistName' => $this->artist->name,
                'studioName' => $this->studio->name,
            ],
            'client' => new ClientResource($this->whenLoaded('client')),
            'artist' => new BriefArtistResource($this->whenLoaded('artist')),
        ];
    }
}
