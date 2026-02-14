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
            'start' => $this->getISODateTime($this->date, $this->start_time),
            'end' => $this->getISODateTime($this->date, $this->end_time),
            'allDay' => $this->all_day,
            'client_id' => $this->client_id,
            'extendedProps' => [
                'status' => $this->status,
                'description' => $this->description,
                'clientName' => $this->client?->name,
                'artistName' => $this->artist?->name,
                'studioName' => $this->studio?->name ?? "",
            ],
            'client' => new ClientResource($this->whenLoaded('client')),
            'artist' => new BriefArtistResource($this->whenLoaded('artist')),
        ];
    }

    private function getISODateTime($date, $time)
    {
        if (!$date || !$time) {
            return null;
        }

        // Handle Carbon/DateTime objects - extract just the date portion
        $dateStr = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : date('Y-m-d', strtotime($date));

        return $dateStr . 'T' . $time;
    }
}
