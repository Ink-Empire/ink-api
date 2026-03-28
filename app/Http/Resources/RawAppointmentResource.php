<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RawAppointmentResource extends JsonResource
{
    public function toArray($request)
    {
        [$duration, $price, $derived] = $this->resource->resolveFinancials();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'client_id' => $this->client_id,
            'artist_id' => $this->artist_id,
            'studio_id' => $this->studio_id,
            'tattoo_id' => $this->tattoo_id,
            'date' => $this->date,
            'status' => $this->status,
            'type' => $this->type,
            'all_day' => $this->all_day,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'price' => $price,
            'duration_minutes' => $duration,
            'is_derived' => $derived,
            'notes' => $this->when(
                $request->user() && $request->user()->id === $this->artist_id,
                $this->notes
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'messages_count' => $this->messages_count,
            'messages' => $this->whenLoaded('messages', function () {
                return $this->messages->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'content' => $message->content,
                        'sender_id' => $message->sender_id,
                        'recipient_id' => $message->recipient_id,
                        'created_at' => $message->created_at,
                        'read_at' => $message->read_at,
                    ];
                });
            }),
            'client' => new ClientResource($this->whenLoaded('client')),
            'artist' => new BriefArtistResource($this->whenLoaded('artist')),
        ];
    }
}
