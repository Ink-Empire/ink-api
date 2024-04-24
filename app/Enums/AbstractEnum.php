<?php

namespace App\Enums;

abstract class AbstractEnum
{
    public static function getByName($name)
    {
        $class = new \ReflectionClass(static::class);

        if ($class->hasConstant(strtoupper($name))) {
            return $class->getConstant(strtoupper($name));
        }

        throw new \Exception("Constant named '{$name}' was not found.");
    }

    public static function asArray()
    {
        $class = new \ReflectionClass(static::class);

        return array_reduce(array_keys($class->getConstants()), function ($acc, $key) use ($class) {
            $acc[$key] = $class->getConstant($key);

            return $acc;
        }, []);
    }
}
