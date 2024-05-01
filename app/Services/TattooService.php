<?php

namespace App\Services;


use App\Exceptions\TattooNotFoundException;
use App\Models\Tattoo;

/**
 *
 */
class TattooService
{
    /**
     * @param int $id
     * @return void|Tattoo
     */
    public function get()
    {
        return Tattoo::paginate(25);
    }

    /**
     * @param int $id
     * @return void|Tattoo
     */
    public function getById(int $id)
    {
        if ($id) {
            return Tattoo::where('id', $id)->first();
        }

        return null;
    }

    public function setPrimaryImage($id, $image)
    {
        $tattoo = $this->getById($id);

        if ($tattoo) {
            $tattoo->primary_image_id = $image->id;
            $tattoo->save();
        } else {
            throw new TattooNotFoundException();
        }

        return $tattoo;
    }
}
