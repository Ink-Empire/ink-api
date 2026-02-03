<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StudioDashboardResource extends JsonResource
{
    protected array $artists;
    protected array $announcements;
    protected array $stats;
    protected $workingHours;

    public function __construct(
        $studio,
        array $artists,
        $announcements,
        array $stats,
        $workingHours
    ) {
        parent::__construct($studio);
        $this->artists = $artists;
        $this->announcements = is_array($announcements) ? $announcements : $announcements->toArray();
        $this->stats = $stats;
        $this->workingHours = $workingHours;
    }

    public function toArray($request): array
    {
        return [
            'studio' => new StudioResource($this->resource),
            'artists' => $this->artists,
            'announcements' => $this->announcements,
            'stats' => new StudioStatsResource($this->stats),
            'working_hours' => StudioWorkingHoursResource::collection($this->workingHours),
        ];
    }
}
