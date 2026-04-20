<?php

namespace App\Actions\Tournaments;

class StripBbCode
{
    public static function run(string $text): string
    {
        return preg_replace('/\[\/?\w+\]/', '', $text);
    }
}
