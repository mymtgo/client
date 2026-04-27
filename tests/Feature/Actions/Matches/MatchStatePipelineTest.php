<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Enums\MatchState;
use App\Managers\MtgoManager;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\LogCursor;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn(sys_get_temp_dir());
    app()->instance('mtgo', $mock);
});

it('does not create matches for foreign match tokens without join events', function () {
    LogCursor::create([
        'file_path' => '/test/log',
        'byte_offset' => 0,
        'local_username' => 'TestPlayer',
    ]);

    LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 100,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'SomeRandomState',
        'raw_text' => 'foreign match event',
        'event_type' => 'match_state_changed',
        'logged_at' => now(),
        'ingested_at' => now(),
        'match_id' => 55555,
        'match_token' => 'foreign-token',
    ]);

    Artisan::call('mtgo:process-matches');

    expect(MtgoMatch::count())->toBe(0);
});

it('filters complete vs incomplete matches correctly via scopes', function () {
    MtgoMatch::create([
        'mtgo_id' => 11111,
        'token' => 'complete-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Complete,
        'outcome' => 'win',
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => 22222,
        'token' => 'started-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Started,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => 33333,
        'token' => 'in-progress-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::InProgress,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => 44444,
        'token' => 'ended-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Ended,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    expect(MtgoMatch::complete()->count())->toBe(1);
    expect(MtgoMatch::incomplete()->count())->toBe(3);
    expect(MtgoMatch::count())->toBe(4);
});

it('relinks orphan matches on each pipeline tick', function () {
    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $card = Card::factory()->create(['mtgo_id' => 100, 'oracle_id' => 'oracle-pipeline']);

    $signature = GenerateDeckSignature::run(collect([[
        'mtgo_id' => $card->mtgo_id,
        'quantity' => 4,
        'sideboard' => 'false',
    ]]));

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    DeckVersion::factory()->create(['deck_id' => $deck->id, 'signature' => $signature]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'deck_version_id' => null,
        'ended_at' => now()->subMinutes(5),
    ]);

    $game = $match->games()->create([
        'mtgo_id' => 88888,
        'started_at' => now()->subMinutes(10),
    ]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => '12:00:00 [INF] (Deck|Used) '.json_encode([[
            'CatalogId' => $card->mtgo_id,
            'Quantity' => 4,
            'InSideboard' => false,
        ]]),
        'logged_at' => now()->subMinutes(10),
    ]);

    Artisan::call('mtgo:process-matches');

    expect($match->fresh()->deck_version_id)->not->toBeNull();
});

it('includes state filter in submittable scope', function () {
    MtgoMatch::create([
        'mtgo_id' => 99999,
        'token' => 'incomplete-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    expect(MtgoMatch::submittable()->count())->toBe(0);
});
