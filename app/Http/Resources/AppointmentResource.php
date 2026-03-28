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
        [$duration, $price, $derived] = $this->resource->resolveFinancials();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'start' => $this->getISODateTime($this->date, $this->start_time),
            'end' => $this->getISODateTime($this->date, $this->end_time),
            'date' => $this->date instanceof \DateTimeInterface
                ? $this->date->format('Y-m-d')
                : ($this->date ? date('Y-m-d', strtotime($this->date)) : null),
            'allDay' => $this->all_day,
            'client_id' => $this->client_id,
            'status' => $this->status,
            'price' => $price,
            'duration_minutes' => $duration,
            'is_derived' => $derived,
            'notes' => $this->when(
                $request->user() && $request->user()->id === $this->artist_id,
                $this->notes
            ),
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

        $dateStr = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : date('Y-m-d', strtotime($date));

        return $dateStr . 'T' . $time;
    }
}
