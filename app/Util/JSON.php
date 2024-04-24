<?php

namespace App\Util;

class JSON
{
    static function arrayToObject(Array $array)
    {
        return json_decode(json_encode($array, JSON_FORCE_OBJECT));
    }

    static function stringToArray(String $string)
    {
        return json_decode($string, true);
    }

    static function stringToObject(String $string)
    {
        return json_decode($string, JSON_FORCE_OBJECT);
    }

    static function objectToString($object)
    {
        return json_encode($object);
    }

    static function objectToArray($object)
    {
        return json_decode(json_encode($object), true);
    }
}
