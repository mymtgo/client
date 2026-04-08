<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $guarded = [];

    protected static ?self $cached = null;

    /**
     * Get the singleton settings row, creating it if needed.
     */
    public static function resolve(): self
    {
        return static::$cached ??= self::firstOrCreate(['id' => 1]);
    }

    /**
     * Get the user's display timezone (for formatting dates in the UI).
     */
    public static function displayTimezone(): string
    {
        return static::resolve()->timezone ?? 'UTC';
    }
}
