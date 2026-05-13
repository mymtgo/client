<?php

namespace App\Actions\Pipeline;

class RunPipeline
{
    public static function run(): void
    {
        if (! app('mtgo')->pathsAreValid()) {
            return;
        }

        app('mtgo')->ingestLogs();

        ApplyLogEvents::run();
    }
}
