<?php

namespace App\Services;

use App\Models\Style;

/**
 *
 */
class StyleService
{
    /**
     *
     */
    public function get()
    {
        return Style::paginate(100);
    }

    /**
     * @param int $id
     */
    public function getById(int $id) : ?Style
    {
        if ($id) {
            return Style::where('id', $id)->first();
        }

        return null;
    }
}
