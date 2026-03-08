<?php

namespace App\Jobs;

use App\Actions\Matches\BuildMatches;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLogEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 2;

    public function __construct() {}

    public function handle(): void
    {
        BuildMatches::run();
    }
}
