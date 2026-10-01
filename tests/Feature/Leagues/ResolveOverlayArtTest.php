<?php

use App\Actions\Decks\FindLastPlayedDeck;
use App\Actions\Leagues\ResolveOverlayArt;
use App\Facades\AppSettings;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('overlay'));

function deckWithCover(string $artCrop = 'https://cards.example/art.jpg'): Deck
{
    $card = Card::factory()->create(['art_crop' => $artCrop, 'local_art_crop' => null]);

    return Deck::factory()->create(['cover_id' => $card->id]);
}

it('defaults the size to full and stores compact', function () {
    expect(AppSettings::overlaySize())->toBe('full');

    AppSettings::setOverlaySize('compact');

    expect(AppSettings::overlaySize())->toBe('compact');
});

it('defaults artwork to deck when nothing is set', function () {
    expect(AppSettings::overlayArtwork())->toBeNull()
        ->and(ResolveOverlayArt::mode())->toBe('deck');
});

it('defaults artwork to custom for a user with a legacy uploaded background', function () {
    Storage::disk('overlay')->put('background-1.png', 'png');
    AppSettings::setOverlayBackgroundPath('background-1.png');

    expect(ResolveOverlayArt::mode())->toBe('custom')
        ->and(ResolveOverlayArt::run(deckWithCover())['url'])->toBe(Storage::disk('overlay')->url('background-1.png'));
});

it('uses the deck cover in deck mode', function () {
    AppSettings::setOverlayArtwork('deck');

    expect(ResolveOverlayArt::run(deckWithCover('https://cards.example/karn.jpg')))->toBe(['url' => 'https://cards.example/karn.jpg']);
});

it('returns no art in none mode even with a deck and a custom image', function () {
    Storage::disk('overlay')->put('background-1.png', 'png');
    AppSettings::setOverlayBackgroundPath('background-1.png');
    AppSettings::setOverlayArtwork('none');

    expect(ResolveOverlayArt::run(deckWithCover()))->toBeNull();
});

it('falls back to the deck cover when the custom file is missing', function () {
    AppSettings::setOverlayBackgroundPath('gone.png');
    AppSettings::setOverlayArtwork('custom');

    expect(ResolveOverlayArt::run(deckWithCover('https://cards.example/karn.jpg')))->toBe(['url' => 'https://cards.example/karn.jpg']);
});

it('returns null with no deck in deck mode', function () {
    expect(ResolveOverlayArt::run(null))->toBeNull();
});

it('finds the deck of the most recently started match', function () {
    $old = deckWithCover();
    $recent = deckWithCover();

    MtgoMatch::factory()->create(['deck_version_id' => DeckVersion::factory()->create(['deck_id' => $old->id])->id, 'started_at' => now()->subDay()]);
    MtgoMatch::factory()->create(['deck_version_id' => DeckVersion::factory()->create(['deck_id' => $recent->id])->id, 'started_at' => now()->subHour()]);
    MtgoMatch::factory()->create(['deck_version_id' => null, 'started_at' => now()]);

    expect(FindLastPlayedDeck::run()?->id)->toBe($recent->id);
});
