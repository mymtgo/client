<?php

namespace App\Updates;

use App\Jobs\FixMatchTimestampsJob;
use Illuminate\Support\Facades\DB;

class FixMatchTimestampsV3 extends AppUpdate
{
    public function run(): void
    {
        // Reset V1 and V2 records so the job re-processes all matches
        DB::table('app_updates')
            ->whereIn('update', [
                'App\\Updates\\FixMatchTimestamps',
                'App\\Updates\\FixMatchTimestampsV2',
            ])
            ->delete();

        FixMatchTimestampsJob::dispatch();
    }
}
