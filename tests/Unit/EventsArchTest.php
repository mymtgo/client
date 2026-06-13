<?php

use App\Events\GameResultRecorded;
use App\Events\MatchCompleted;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

arch('pipeline UI-reload events dispatch after commit')
    ->expect([MatchCompleted::class, GameResultRecorded::class])
    ->toImplement(ShouldDispatchAfterCommit::class);
