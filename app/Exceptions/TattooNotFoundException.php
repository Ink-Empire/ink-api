<?php

namespace App\Exceptions;

class TattooNotFoundException extends \Exception
{
    protected $message = 'Tattoo not found';
}
