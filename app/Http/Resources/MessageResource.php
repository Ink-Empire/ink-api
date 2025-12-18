<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender' => $this->whenLoaded('sender', function () {
                return [
                    'id' => $this->sender->id,
                    'name' => $this->sender->name,
                    'username' => $this->sender->username,
                    'initials' => $this->getInitials($this->sender->name ?? $this->sender->username),
                    'image' => $this->sender->image ? [
                        'id' => $this->sender->image->id,
                        'uri' => $this->sender->image->uri,
                    ] : null,
                ];
            }),
            'content' => $this->content,
            'type' => $this->type ?? 'text',
            'metadata' => $this->metadata,
            'attachments' => $this->whenLoaded('attachments', function () {
                return $this->attachments->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'image' => $attachment->image ? [
                            'id' => $attachment->image->id,
                            'uri' => $attachment->image->uri,
                        ] : null,
                    ];
                });
            }),
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function getInitials(?string $name): string
    {
        if (!$name) {
            return '??';
        }

        $parts = explode(' ', $name);
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        }

        return strtoupper(substr($name, 0, 2));
    }
}
