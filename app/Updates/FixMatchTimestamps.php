<?php

namespace App\Updates;

use App\Jobs\FixMatchTimestampsJob;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\System;

class FixMatchTimestamps extends AppUpdate
{
    public function run(): void
    {
        $this->seedTimezone();

        FixMatchTimestampsJob::dispatch();
    }

    /**
     * Seed the AppSetting timezone from NativePHP so the correct
     * timezone is in place before recalculating timestamps.
     */
    private function seedTimezone(): void
    {
        $settings = AppSetting::resolve();

        if ($settings->timezone) {
            return;
        }

        $timezone = Settings::get('timezone') ?: System::timezone();

        if ($timezone) {
            $settings->update(['timezone' => $timezone]);

            date_default_timezone_set($timezone);
            config(['app.timezone' => $timezone]);

            Log::info("FixMatchTimestamps: seeded timezone to {$timezone}");
        }
    }
}
