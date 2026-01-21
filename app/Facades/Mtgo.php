<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class Mtgo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mtgo';
    }
}
