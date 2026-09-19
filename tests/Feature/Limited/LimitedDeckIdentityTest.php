<?php

use App\Actions\Limited\EnsureLimitedDeckVersion;
use App\Models\Deck;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// LimitedDeckSnapshot has no factory and the model is `$guarded = []`, so
// build one directly rather than adding a factory this work does not need.
function limitedSnapshot(League $league): LimitedDeckSnapshot
{
    return LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'source' => 'registered',
        'signature' => 'sig',
        'captured_at' => now(),
        'cards' => [['catalog_id' => 12345, 'quantity' => 1, 'sideboard' => false]],
    ]);
}

it('keys a draftless limited deck on the MTGO event and course, not the local row id', function () {
    $league = League::factory()->create(['event_id' => 4321, 'mtgo_course_id' => 99]);
    $snapshot = limitedSnapshot($league);

    EnsureLimitedDeckVersion::run($league, $snapshot);

    expect(Deck::query()->where('mtgo_id', 'limited:event-4321-99')->exists())->toBeTrue();
});

it('keeps a league with no course id on the local-only key', function () {
    $league = League::factory()->create(['event_id' => 4321, 'mtgo_course_id' => null]);

    $version = EnsureLimitedDeckVersion::run($league, limitedSnapshot($league));
    $deck = $version->deck;

    expect($deck->mtgo_id)->toBe("limited:league-{$league->id}")
        ->and(Deck::query()->syncableIdentity()->whereKey($deck->id)->exists())->toBeFalse();
});

it('excludes limited decks from the withoutLimited scope', function () {
    $limited = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);
    $constructed = Deck::factory()->create(['mtgo_id' => '110186502', 'format' => 'Modern']);

    $ids = Deck::query()->withoutLimited()->pluck('id');

    expect($ids)->toContain($constructed->id)
        ->and($ids)->not->toContain($limited->id)
        ->and($limited->isLimited())->toBeTrue()
        ->and($constructed->isLimited())->toBeFalse();
});

it('rekeys an existing local-only limited deck once its league has an identity', function () {
    $league = League::factory()->create(['event_id' => 4321, 'mtgo_course_id' => 99]);
    $deck = Deck::factory()->create(['mtgo_id' => "limited:league-{$league->id}", 'format' => 'Limited']);
    $league->update(['deck_version_id' => $deck->versions()->create(['signature' => 'sig', 'modified_at' => now()])->id]);

    // RefreshDatabase's initial migrate:fresh already ran this migration before
    // the league/deck above existed, so seed+remove its row to genuinely
    // re-exercise up() the way RemovePhantomLeaguesMigrationTest does.
    DB::table('migrations')->where('migration', '2026_09_17_130000_rekey_limited_decks')->delete();

    $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_17_130000_rekey_limited_decks.php'])->assertSuccessful();

    expect($deck->fresh()->mtgo_id)->toBe('limited:event-4321-99');
});
