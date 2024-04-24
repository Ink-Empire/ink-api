<?php


namespace App\Enums;


use ReflectionClass;

class QueueNames extends AbstractEnum
{
    const ELASTIC_REINDEX = 'elastic-reindex';
    const ELASTIC_REBUILD = 'elastic-rebuild';

    public static function returnArray()
    {
        $class = new ReflectionClass(__CLASS__);
        $classArray = $class->getConstants();
        $constants = array_flip($classArray);

        //if your value + _TEXT exists, return it, if not, null
        return array_keys($constants);
    }
}
