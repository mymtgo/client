<?php

use App\Actions\Sidecar\BuildSidecarMatchView;
use App\Actions\Sidecar\IngestSidecarEvents;
use App\Actions\Sidecar\ProjectSidecarTimeline;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarGameView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-project-'.uniqid();
    mkdir($this->dir);
    copy(base_path('tests/fixtures/sidecar/events-game-full.ndjson'), $this->dir.'/events-aaaaaaaa-0000-0000-0000-000000000002.ndjson');
    copy(base_path('tests/fixtures/sidecar/status.json'), $this->dir.'/status.json');
    AppSettings::setSidecarDirectory($this->dir);
    AppSettings::setSystemTimezone('Europe/London');
    IngestSidecarEvents::resetTick();
    IngestSidecarEvents::run();

    $first = GameEvent::whereNotNull('game_mtgo_id')->orderBy('seq')->first();
    $this->matchMtgoId = $first->match_mtgo_id;
    $this->gameMtgoId = $first->game_mtgo_id;

    $this->match = MtgoMatch::factory()->create(['mtgo_id' => $this->matchMtgoId]);
    $this->game = Game::factory()->create(['match_id' => $this->match->id, 'mtgo_id' => $this->gameMtgoId]);
    $this->view = BuildSidecarMatchView::run($this->matchMtgoId)->games[$this->gameMtgoId];
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('writes one frame per state-changing tick and takes ownership', function () {
    // A tick of its own carrying nothing but the clock. The fold moves TimeLeft
    // silently, so this must not earn a frame.
    $last = GameEvent::where('game_mtgo_id', $this->gameMtgoId)->orderByDesc('seq')->first();
    $clockOnlyTs = $last->ts->addSecond();
    GameEvent::create([
        'log_instance_id' => $last->log_instance_id,
        'session' => $last->session,
        'session_started_at' => $last->session_started_at,
        // seq is unique per log instance, and match-level events follow the
        // game's last one, so take the instance's high water mark.
        'seq' => GameEvent::where('log_instance_id', $last->log_instance_id)->max('seq') + 1,
        'source' => $last->source,
        'type' => 'clock_tick',
        'ts' => $clockOnlyTs,
        'game_mtgo_id' => $this->gameMtgoId,
        'match_mtgo_id' => $this->matchMtgoId,
        'verified' => true,
        'data' => ['p' => 0, 'remaining_ms' => 1000, 'trigger' => 'priority'],
    ]);

    ProjectSidecarTimeline::run($this->game, $this->view);

    $ticks = GameEvent::where('game_mtgo_id', $this->gameMtgoId)->get()
        ->groupBy(fn (GameEvent $e) => $e->ts->format('Y-m-d\TH:i:s.v'));
    $stateTicks = $ticks->filter(fn ($group) => $group->contains(fn (GameEvent $e) => $e->type !== 'clock_tick'));
    $clockOnlyTicks = $ticks->count() - $stateTicks->count();

    expect(GameTimeline::where('game_id', $this->game->id)->count())->toBe($stateTicks->count())
        // The synthetic tick is one of these, and no frame carries its time.
        ->and($clockOnlyTicks)->toBeGreaterThanOrEqual(1)
        ->and(GameTimeline::where('game_id', $this->game->id)
            ->where('timestamp', $clockOnlyTs->setTimezone('Europe/London')->format('H:i:s.v'))->exists())->toBeFalse()
        ->and($this->game->fresh()->timeline_source)->toBe('sidecar');
});

it('stamps frames with millisecond time of day in the system timezone', function () {
    ProjectSidecarTimeline::run($this->game, $this->view);

    $firstEvent = GameEvent::where('game_mtgo_id', $this->gameMtgoId)->orderBy('seq')->first();
    $firstRow = GameTimeline::where('game_id', $this->game->id)->orderBy('id')->first();

    expect($firstRow->timestamp)->toBe($firstEvent->ts->setTimezone('Europe/London')->format('H:i:s.v'))
        ->and($firstRow->timestamp)->toMatch('/^\d{2}:\d{2}:\d{2}\.\d{3}$/')
        ->and($firstRow->created_at)->not->toBeNull();
});

it('produces frames in the spec shape with the additive keys present by the end', function () {
    ProjectSidecarTimeline::run($this->game, $this->view);

    $last = GameTimeline::where('game_id', $this->game->id)->orderByDesc('id')->first()->content;

    expect($last)->toHaveKeys(['Turn', 'Phase', 'Step', 'ActivePlayer', 'Priority', 'Players', 'Cards'])
        ->and($last['Players'][0])->toHaveKeys(['Id', 'Name', 'Life', 'HandCount', 'LibraryCount', 'TimeLeft'])
        ->and(collect($last['Players'])->pluck('Name')->sort()->values()->all())->toBe(['Opp_Name', 'local.player']);

    $tapped = GameTimeline::where('game_id', $this->game->id)->get()
        ->contains(fn (GameTimeline $t) => collect($t->content['Cards'])->contains(fn ($c) => ($c['Tapped'] ?? false) === true));
    expect($tapped)->toBeTrue();

    $nowhere = GameTimeline::where('game_id', $this->game->id)->get()
        ->contains(fn (GameTimeline $t) => collect($t->content['Cards'])->contains(fn ($c) => $c['Zone'] === 'Nowhere'));
    expect($nowhere)->toBeFalse();
});

it('replaces rather than appends on a second run', function () {
    ProjectSidecarTimeline::run($this->game, $this->view);
    $count = GameTimeline::where('game_id', $this->game->id)->count();
    $hash = md5(GameTimeline::where('game_id', $this->game->id)->orderBy('id')->pluck('content')->toJson());
    $ids = GameTimeline::where('game_id', $this->game->id)->orderBy('id')->pluck('id')->all();
    $matchUpdatedAt = $this->match->fresh()->updated_at;

    $this->travel(1)->minutes();
    ProjectSidecarTimeline::run($this->game, $this->view);

    expect(GameTimeline::where('game_id', $this->game->id)->count())->toBe($count)
        ->and(md5(GameTimeline::where('game_id', $this->game->id)->orderBy('id')->pluck('content')->toJson()))->toBe($hash)
        // Same ids, so the second pass skipped the delete and insert entirely.
        ->and(GameTimeline::where('game_id', $this->game->id)->orderBy('id')->pluck('id')->all())->toBe($ids)
        // Nothing was written, so the match must not look dirty to sync.
        ->and($this->match->fresh()->updated_at->equalTo($matchUpdatedAt))->toBeTrue();
});

it('rewrites rather than skipping when the event tail changed', function () {
    ProjectSidecarTimeline::run($this->game, $this->view);
    $count = GameTimeline::where('game_id', $this->game->id)->count();
    $ids = GameTimeline::where('game_id', $this->game->id)->orderBy('id')->pluck('id')->all();
    $matchUpdatedAt = $this->match->fresh()->updated_at;

    GameEvent::whereIn('id', GameEvent::where('game_mtgo_id', $this->gameMtgoId)
        ->orderByDesc('seq')->limit(10)->pluck('id'))->delete();
    $view = BuildSidecarMatchView::run($this->matchMtgoId)->games[$this->gameMtgoId];

    $this->travel(1)->minutes();
    ProjectSidecarTimeline::run($this->game, $view);

    expect(GameTimeline::where('game_id', $this->game->id)->count())->toBeLessThan($count)
        ->and(GameTimeline::where('game_id', $this->game->id)->orderBy('id')->pluck('id')->all())->not->toBe($ids)
        ->and(array_intersect($ids, GameTimeline::where('game_id', $this->game->id)->pluck('id')->all()))->toBe([])
        // Frames really changed, so sync has to see the match as dirty.
        ->and($this->match->fresh()->updated_at->greaterThan($matchUpdatedAt))->toBeTrue();
});

it('writes nothing and leaves ownership alone when the view has no player names', function () {
    $nameless = new SidecarGameView(
        gameMtgoId: $this->view->gameMtgoId,
        gameNumber: $this->view->gameNumber,
        playerNames: [],
        startedAt: $this->view->startedAt,
        endedAt: $this->view->endedAt,
        winnerName: $this->view->winnerName,
        onPlayName: $this->view->onPlayName,
        hasStart: $this->view->hasStart,
        hasEnd: $this->view->hasEnd,
        allVerified: $this->view->allVerified,
        turnCount: $this->view->turnCount,
        clockByName: $this->view->clockByName,
        keyframes: $this->view->keyframes,
    );

    ProjectSidecarTimeline::run($this->game, $nameless);

    expect(GameTimeline::where('game_id', $this->game->id)->count())->toBe(0)
        ->and($this->game->fresh()->timeline_source)->toBeNull();
});

it('swallows a query exception from the write and leaves ownership alone', function () {
    // Stands in for the DB being locked by concurrent ingestion. The write must
    // not escape and abort ApplySidecarProjection's loop over the other games.
    // The queue is faked only to stop the card backfill job, which runs inline
    // under the sync driver and reads the same table, from throwing first.
    Queue::fake();
    Schema::drop('game_timelines');

    ProjectSidecarTimeline::run($this->game, $this->view);

    expect($this->game->fresh()->timeline_source)->toBeNull();
});

it('overwrites log frames that were there first', function () {
    GameTimeline::create(['game_id' => $this->game->id, 'timestamp' => '13:20:15', 'content' => ['Players' => [], 'Cards' => []]]);
    $this->game->update(['timeline_source' => 'log']);

    ProjectSidecarTimeline::run($this->game, $this->view);

    expect(GameTimeline::where('game_id', $this->game->id)->where('timestamp', '13:20:15')->exists())->toBeFalse()
        ->and($this->game->fresh()->timeline_source)->toBe('sidecar');
});

it('folds a stream that begins with a reattach keyframe', function () {
    // Drop everything before the first turn keyframe to simulate attaching mid-game.
    $firstTurnKeyframe = GameEvent::where('game_mtgo_id', $this->gameMtgoId)->where('type', 'keyframe')
        ->get()->first(fn (GameEvent $e) => ($e->data['trigger'] ?? null) === 'turn');
    GameEvent::where('game_mtgo_id', $this->gameMtgoId)->where('seq', '<', $firstTurnKeyframe->seq)->delete();
    $firstTurnKeyframe->update(['data' => array_replace($firstTurnKeyframe->data, ['trigger' => 'reattach'])]);

    $view = BuildSidecarMatchView::run($this->matchMtgoId)->games[$this->gameMtgoId];
    ProjectSidecarTimeline::run($this->game, $view);

    $first = GameTimeline::where('game_id', $this->game->id)->orderBy('id')->first();
    expect($first)->not->toBeNull()
        ->and($first->content['Turn'])->toBe($firstTurnKeyframe->data['turn'])
        ->and(collect($first->content['Players'])->pluck('Name')->sort()->values()->all())->toBe(['Opp_Name', 'local.player'])
        ->and($this->game->fresh()->timeline_source)->toBe('sidecar');
});

it('writes nothing and leaves ownership alone when no game_started or keyframe exists', function () {
    GameEvent::where('game_mtgo_id', $this->gameMtgoId)->whereIn('type', ['game_started', 'keyframe'])->delete();
    $view = BuildSidecarMatchView::run($this->matchMtgoId)->games[$this->gameMtgoId];

    ProjectSidecarTimeline::run($this->game, $view);

    expect(GameTimeline::where('game_id', $this->game->id)->count())->toBe(0)
        ->and($this->game->fresh()->timeline_source)->toBeNull();
});
