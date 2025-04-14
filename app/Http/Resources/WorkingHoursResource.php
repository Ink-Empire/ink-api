<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkingHoursResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'artist_id' => $this->artist_id,
            'day' => $this->day,
            'day_name' => $this->day_name,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'is_day_off' => $this->is_day_off,
        ];
    }
}
