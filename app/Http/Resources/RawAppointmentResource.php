<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RawAppointmentResource extends JsonResource
{
    /**
     * Enable default data wrapping for Laravel best practices
     */

    public function toArray($request)
    {
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
            'client' => $this->when($this->relationLoaded('client'), function () {
                return [
                    'id' => $this->client->id,
                    'username' => $this->client->username,
                    'email' => $this->client->email,
                    'name' => $this->client->name,
                ];
            }),
            'artist' => $this->when($this->relationLoaded('artist'), function () {
                return [
                    'id' => $this->artist->id,
                    'username' => $this->artist->username,
                    'email' => $this->artist->email,
                    'name' => $this->artist->name,
                ];
            }),
        ];
    }
}
