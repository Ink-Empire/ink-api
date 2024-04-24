<?php

namespace App\Util;

class stringToModel
{
    public static function convert($model)
    {
        $model = 'App\Models\\' . $model;
        return new $model;
    }
}
