<?php

namespace App\Util;

class StringToModel
{
    public static function convert($model)
    {
        $model = 'App\Models\\' . ($model);
        return new $model;
    }
}
