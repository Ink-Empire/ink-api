<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray($request)
    {
        $currentUserId = $request->user()?->id;
        $otherParticipant = $this->users->firstWhere('id', '!=', $currentUserId);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'participant' => $otherParticipant ? [
                'id' => $otherParticipant->id,
                'name' => $otherParticipant->name,
                'username' => $otherParticipant->username,
                'initials' => $this->getInitials($otherParticipant->name ?? $otherParticipant->username),
                'image' => $otherParticipant->image ? [
                    'id' => $otherParticipant->image->id,
                    'uri' => $otherParticipant->image->uri,
                ] : null,
                'is_online' => $otherParticipant->isOnline(),
                'last_seen_at' => $otherParticipant->last_seen_at,
            ] : null,
            'last_message' => $this->whenLoaded('latestMessage', function () {
                return [
                    'id' => $this->latestMessage->id,
                    'content' => $this->latestMessage->content,
                    'type' => $this->latestMessage->type,
                    'sender_id' => $this->latestMessage->sender_id,
                    'created_at' => $this->latestMessage->created_at,
                ];
            }),
            'unread_count' => $this->unread_count ?? 0,
            'appointment' => $this->whenLoaded('appointment', function () {
                return $this->appointment ? [
                    'id' => $this->appointment->id,
                    'status' => $this->appointment->status,
                    'date' => $this->appointment->date,
                    'start_time' => $this->appointment->start_time,
                    'end_time' => $this->appointment->end_time,
                    'title' => $this->appointment->title,
                    'description' => $this->appointment->description,
                    'placement' => $this->appointment->placement,
                ] : null;
            }),
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
