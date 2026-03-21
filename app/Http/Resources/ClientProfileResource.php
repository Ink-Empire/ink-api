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
        return [
            'client' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
                'created_at' => $this->created_at->toIso8601String(),
            ],
            'stats' => $this->profileData['stats'],
            'tags' => $this->profileData['tags']->map(fn ($group) => [
                'category' => new UserTagCategoryResource($group['category']),
                'tags' => UserTagResource::collection($group['tags']),
            ]),
            'notes' => ClientNoteResource::collection($this->profileData['notes']),
            'history' => ClientAppointmentHistoryResource::collection($this->profileData['history']),
        ];
    }
}
