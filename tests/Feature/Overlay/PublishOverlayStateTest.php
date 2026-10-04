<?php

use App\Actions\Overlay\PublishOverlayState;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('overlay');
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
    AppSettings::setOverlayPublish(true);
    Mtgo::setUsername('StreamPlayer');
    Account::factory()->create(['username' => 'StreamPlayer', 'login_id' => 4242]);

    $card = Card::factory()->create(['art_crop' => 'https://cards.scryfall.io/art_crop/karn.jpg', 'local_art_crop' => 'karn.jpg']);
    $deck = Deck::factory()->create(['name' => 'Mono Green Tron', 'cover_id' => $card->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);

    $this->api = Mockery::mock(SyncApi::class);
    app()->instance(SyncApi::class, $this->api);
});

it('sends the remote deck art, never the local copy', function () {
    $this->api->shouldReceive('publishOverlay')->once()->withArgs(fn (int $loginId, array $state) => $loginId === 4242
        && $state['art'] === ['url' => 'https://cards.scryfall.io/art_crop/karn.jpg']
        && $state['deck']['name'] === 'Mono Green Tron');

    PublishOverlayState::run();

    expect(AppSettings::overlayLastPublishedAt())->not->toBeNull();
});

it('sends no art in none mode', function () {
    AppSettings::setOverlayArtwork('none');
    $this->api->shouldReceive('publishOverlay')->once()->withArgs(fn (int $id, array $state) => $state['art'] === null);

    PublishOverlayState::run();
});

it('uploads a changed custom background once and reuses it after', function () {
    AppSettings::setOverlayArtwork('custom');
    Storage::disk('overlay')->put('background-1.png', 'png-bytes');
    AppSettings::setOverlayBackgroundPath('background-1.png');

    $this->api->shouldReceive('uploadOverlayBackground')->once()->with('png-bytes', 'background-1.png')->andReturn('https://cdn.example/bg.png');
    $this->api->shouldReceive('publishOverlay')->twice()->withArgs(fn (int $id, array $state) => $state['art'] === ['url' => 'https://cdn.example/bg.png']);

    PublishOverlayState::run();
    PublishOverlayState::run();

    expect(AppSettings::overlayBackgroundRemote())->toBe(['url' => 'https://cdn.example/bg.png', 'hash' => sha1('png-bytes')]);
});

it('falls back to deck art when the custom upload fails', function () {
    AppSettings::setOverlayArtwork('custom');
    Storage::disk('overlay')->put('background-1.png', 'png-bytes');
    AppSettings::setOverlayBackgroundPath('background-1.png');

    $this->api->shouldReceive('uploadOverlayBackground')->andThrow(new RuntimeException('500'));
    $this->api->shouldReceive('publishOverlay')->once()->withArgs(fn (int $id, array $state) => $state['art'] === ['url' => 'https://cards.scryfall.io/art_crop/karn.jpg']);

    PublishOverlayState::run();
});

it('falls back to deck art when custom is chosen but no file exists', function () {
    AppSettings::setOverlayArtwork('custom');
    $this->api->shouldNotReceive('uploadOverlayBackground');
    $this->api->shouldReceive('publishOverlay')->once()->withArgs(fn (int $id, array $state) => ! str_contains((string) json_encode($state), '127.0.0.1')
        && $state['art'] === ['url' => 'https://cards.scryfall.io/art_crop/karn.jpg']);

    PublishOverlayState::run();
});

it('publishes with the league window off', function () {
    AppSettings::setShowLeagueWindow(false);
    $this->api->shouldReceive('publishOverlay')->once();

    PublishOverlayState::run();
});

it('does nothing when publishing is off, unlinked or the username is unknown', function (Closure $arrange) {
    $arrange();
    $this->api->shouldNotReceive('publishOverlay');

    PublishOverlayState::run();
})->with([
    'publish off' => fn () => AppSettings::setOverlayPublish(false),
    'unlinked' => fn () => app(SyncTokens::class)->clear(),
    'no login id' => fn () => Mtgo::setUsername('NobodyKnown'),
]);

it('swallows a failed push and keeps the last published time', function () {
    AppSettings::setOverlayLastPublishedAt('2026-10-01T10:00:00+00:00');
    $this->api->shouldReceive('publishOverlay')->andThrow(new RuntimeException('503'));

    PublishOverlayState::run();

    expect(AppSettings::overlayLastPublishedAt())->toBe('2026-10-01T10:00:00+00:00');
});

it('records why a push failed so settings can say', function (int $status, string $reason) {
    $this->api->shouldReceive('publishOverlay')->andThrow(new RuntimeException('failed', $status));

    PublishOverlayState::run();

    expect(AppSettings::overlayPublishError())->toBe($reason);
})->with([
    'not claimed' => [409, 'not_claimed'],
    'rejected' => [422, 'rejected'],
    'server down' => [503, 'unreachable'],
]);

it('records a missing login id', function () {
    Mtgo::setUsername('NobodyKnown');
    $this->api->shouldNotReceive('publishOverlay');

    PublishOverlayState::run();

    expect(AppSettings::overlayPublishError())->toBe('no_login_id');
});

it('clears the failure reason after a successful push', function () {
    AppSettings::setOverlayPublishError('unreachable');
    $this->api->shouldReceive('publishOverlay')->once();

    PublishOverlayState::run();

    expect(AppSettings::overlayPublishError())->toBeNull();
});
