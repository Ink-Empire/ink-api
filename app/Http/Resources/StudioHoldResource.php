<?php

namespace App\Http\Resources;

use App\Enums\StudioHoldStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The hold state of a studio, for the admin panel.
 *
 * held_at, held_by and reason survive a release on purpose: they are the
 * record of the last hold placed, and the admin list needs them to show what
 * happened after the fact.
 */
class StudioHoldResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'hold_status' => ($this->hold_status ?? StudioHoldStatus::Active)->value,
            'is_on_hold' => $this->isOnHold(),
            'hold_reason' => $this->hold_reason,
            'held_at' => $this->held_at?->toIso8601String(),
            'held_by' => $this->whenLoaded('heldBy', fn () => [
                'id' => $this->heldBy->id,
                'name' => $this->heldBy->name,
                'email' => $this->heldBy->email,
            ]),
            'hold_lifted_at' => $this->hold_lifted_at?->toIso8601String(),
            'hold_lifted_by' => $this->whenLoaded('holdLiftedBy', fn () => [
                'id' => $this->holdLiftedBy->id,
                'name' => $this->holdLiftedBy->name,
                'email' => $this->holdLiftedBy->email,
            ]),
        ];
    }
}
