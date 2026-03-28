<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientProfileResource extends JsonResource
{
    private array $profileData;

    public function __construct($resource, array $profileData)
    {
        parent::__construct($resource);
        $this->profileData = $profileData;
    }

    public function toArray($request)
    {
        $clientNotes = $this->profileData['notes']->map(fn ($note) => [
            'id' => $note->id,
            'body' => $note->body,
            'source' => 'note',
            'created_at' => $note->created_at->toIso8601String(),
        ]);

        $appointmentNotes = ($this->profileData['appointment_notes'] ?? collect())->map(fn ($appt) => [
            'id' => 'appt_' . $appt->id,
            'body' => $appt->notes,
            'source' => 'appointment',
            'appointment_title' => $appt->title,
            'created_at' => $appt->date->toIso8601String(),
        ]);

        $allNotes = $clientNotes->concat($appointmentNotes)
            ->sortByDesc('created_at')
            ->values();

        return [
            'client' => new BriefClientResource($this->resource),
            'stats' => $this->profileData['stats'],
            'tags' => UserTagGroupResource::collection($this->profileData['tags']),
            'notes' => $allNotes,
            'history' => ClientAppointmentHistoryResource::collection($this->profileData['history']),
        ];
    }
}
