<?php

namespace App\Util;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class StringToModel
{
    /**
     * Resolve a short model name such as "Artist" to a model instance.
     *
     * An unknown name used to raise \Error, which slips past every
     * catch (\Exception) in the callers and surfaces as a 500. Throwing an
     * InvalidArgumentException instead lets them report it properly.
     *
     * @throws InvalidArgumentException
     */
    public static function convert($model): Model
    {
        $class = 'App\Models\\' . ucfirst((string) $model);

        if (!class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Unknown model "%s".', $model));
        }

        return new $class;
    }
}
