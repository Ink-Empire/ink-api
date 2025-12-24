<?php

namespace App\Util;

class StringToModel
{
    public static function convert($model)
    {
        $model = 'App\Models\\' . ucfirst($model);
        return new $model;
    }
}
