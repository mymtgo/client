<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $guarded = [];

    /**
     * Get the singleton settings row, creating it if needed.
     */
    public static function resolve(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }
}
