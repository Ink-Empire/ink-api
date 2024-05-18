<?php

namespace App\Services;

use App\Exceptions\StudioNotFoundException;
use App\Models\Image;
use App\Models\Studio;

/**
 *
 */
class StudioService
{

    /**
     * @param $id
     */
    public function getById($id): ?Studio
    {
        if ($id) {
            return Studio::where('id', $id)->first();
        }

        return null;
    }

    /**
     *
     */
    public function get()
    {
        return Studio::paginate(25);
    }


    /**
     * @throws StudioNotFoundException
     */
    public function setStudioImage(string $studio_id, Image $image): Studio
    {
        $studio = $this->getById($studio_id);

        if ($studio) {
            $studio->image_id = $image->id;
            $studio->save();
        } else {
            throw new StudioNotFoundException();
        }

        return $studio;
    }

    public function setBusinessDays(array $data, $studio)
    {
        if (isset($data['start']) && isset($data['end'])) {
            foreach ($data['days'] as $day) {
                $studio->business_hours()->updateOrCreate(
                    [
                        'day_id' => $day,
                        'studio_id' => $studio->id
                    ],
                    [
                        'day_id' => $day,
                        'open_time' => $data['start'],
                        'close_time' => $data['end']
                    ]);
            }
        }
    }


    public function updateStyles(?Studio $studio, $stylesArray): void
    {
        $studio->styles()->sync($stylesArray);
    }

    public function updateTattoos(?Studio $studio, mixed $tattooArray): void
    {
        //
    }

    public function updateArtists(?Studio $studio, mixed $fieldVal): void
    {
        //
    }

}
