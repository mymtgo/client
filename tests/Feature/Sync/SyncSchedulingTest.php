<?php

use App\Enums\MatchState;
use App\Jobs\RunSyncJob;
use App\Models\MtgoMatch;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('schedules a sync when a match completes on a linked, online device', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Bus::fake([RunSyncJob::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $match->update(['state' => MatchState::Complete]);

    Bus::assertDispatched(RunSyncJob::class);
});

it('schedules the completion sync about thirty seconds out so closing the app soon after rarely strands the match', function () {
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    Bus::fake([RunSyncJob::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $match->update(['state' => MatchState::Complete]);

    Bus::assertDispatched(RunSyncJob::class, fn (RunSyncJob $job) => $job->delay instanceof DateTimeInterface
        && abs(now()->diffInSeconds($job->delay)) <= 30);
});

it('does not schedule a completion sync while unlinked', function () {
    app(SyncTokens::class)->clear();
    Bus::fake([RunSyncJob::class]);

    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $match->update(['state' => MatchState::Complete]);

    Bus::assertNotDispatched(RunSyncJob::class);
});
