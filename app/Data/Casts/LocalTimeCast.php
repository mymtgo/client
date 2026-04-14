<?php

namespace App\Data\Casts;

use Carbon\Carbon;
use Native\Desktop\Facades\Settings;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;

class LocalTimeCast implements Cast
{
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $carbon = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $carbon->copy()->setTimezone(Settings::get('system_tz', 'UTC'));
    }
}
