<?php

use App\Facades\AppSettings;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\PopulateMissingCardData;
use App\Jobs\RepairGuessedMultiFaceCards;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Updates\RepairGuessedMultiFaceCards as RepairGuessedMultiFaceCardsUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Drop the global Http::fake() stub from Pest.php so test-specific stubs win.
    $factory = Http::getFacadeRoot();
    (new ReflectionProperty($factory, 'stubCallbacks'))->setValue($factory, collect());

    AppSettings::setDeviceId('device-multi-face-repair-test');
    AppSettings::setApiKey('key-one');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());

    Queue::fake();

    $complete = MtgoMatch::factory()->create([
        'deck_version_id' => DeckVersion::factory()->create()->id,
        'state' => 'complete',
    ]);
    Game::factory()->for($complete, 'match')->create(['won' => true, 'started_at' => now()]);
});

function answerCardsById(array $cardsById): void
{
    Http::fake([
        '*/api/cards' => fn ($request) => Http::response(
            collect($request['ids'] ?? [])
                ->map(fn ($id) => $cardsById[(int) $id] ?? null)
                ->filter()
                ->values()
                ->all(),
            200,
        ),
    ]);
}

it('queues the repair from the app update instead of running it at launch', function () {
    Http::fake();

    (new RepairGuessedMultiFaceCardsUpdate)->run();

    Queue::assertPushed(RepairGuessedMultiFaceCards::class);
    Http::assertNothingSent();
});

it('corrects a card stored as a neighbouring multi-face card and rebuilds stats', function () {
    Card::factory()->create([
        'mtgo_id' => '61460',
        'name' => 'Ulvenwald Captive // Ulvenwald Abomination',
        'oracle_id' => 'oracle-captive',
        'scryfall_id' => 'scryfall-captive',
        'image' => 'https://example.test/captive.png',
    ]);

    answerCardsById([61460 => [
        'value' => 61460,
        'scryfall_id' => 'scryfall-evolution',
        'oracle_id' => 'oracle-evolution',
        'name' => 'Eldritch Evolution',
    ]]);

    (new RepairGuessedMultiFaceCards)->handle();

    $card = Card::where('mtgo_id', '61460')->sole();

    expect($card->name)->toBe('Eldritch Evolution')
        ->and($card->oracle_id)->toBe('oracle-evolution')
        ->and($card->scryfall_id)->toBeNull()
        ->and($card->image)->toBeNull();

    Queue::assertPushed(PopulateMissingCardData::class);
    Queue::assertPushed(ComputeCardGameStats::class, 1);
});

it('leaves correct multi-face cards and back faces the api does not index alone', function () {
    Card::factory()->create([
        'mtgo_id' => '126541',
        'name' => 'Bridgeworks Battle // Tanglespan Bridgeworks',
        'oracle_id' => 'oracle-bridgeworks',
        'scryfall_id' => 'scryfall-bridgeworks',
    ]);
    Card::factory()->create([
        'mtgo_id' => '126519',
        'name' => 'Boggart Trawler // Boggart Bog',
        'oracle_id' => 'oracle-boggart',
        'scryfall_id' => 'scryfall-boggart',
    ]);

    answerCardsById([126541 => [
        'value' => 126541,
        'scryfall_id' => 'scryfall-bridgeworks',
        'oracle_id' => 'oracle-bridgeworks',
        'name' => 'Bridgeworks Battle // Tanglespan Bridgeworks',
    ]]);

    (new RepairGuessedMultiFaceCards)->handle();

    expect(Card::whereNull('scryfall_id')->count())->toBe(0);

    Queue::assertNotPushed(PopulateMissingCardData::class);
    Queue::assertNotPushed(ComputeCardGameStats::class);
});

it('throws for a retry when the api does not answer', function () {
    Card::factory()->create([
        'mtgo_id' => '61460',
        'name' => 'Ulvenwald Captive // Ulvenwald Abomination',
        'oracle_id' => 'oracle-captive',
        'scryfall_id' => 'scryfall-captive',
    ]);

    Http::fake(['*/api/cards' => Http::response(['message' => 'Server Error'], 500)]);

    expect(fn () => (new RepairGuessedMultiFaceCards)->handle())->toThrow(RuntimeException::class);

    expect(Card::where('mtgo_id', '61460')->sole()->scryfall_id)->toBe('scryfall-captive');
    Queue::assertNotPushed(ComputeCardGameStats::class);
});
