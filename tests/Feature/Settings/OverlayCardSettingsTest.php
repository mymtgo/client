<?php

use App\Actions\Leagues\OpenOverlayWindow;
use App\Events\LeagueOverlayChanged;
use App\Facades\AppSettings;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('overlay'));

it('persists artwork and size and pushes an overlay reload', function () {
    Event::fake([LeagueOverlayChanged::class]);

    $this->post(route('settings.overlay'), ['overlay_artwork' => 'none', 'overlay_size' => 'compact'])->assertRedirect();

    expect(AppSettings::overlayArtwork())->toBe('none')
        ->and(AppSettings::overlaySize())->toBe('compact');
    Event::assertDispatched(LeagueOverlayChanged::class);
});

it('rejects unknown artwork and size values', function () {
    $this->post(route('settings.overlay'), ['overlay_artwork' => 'banner', 'overlay_size' => 'huge'])
        ->assertSessionHasErrors(['overlay_artwork', 'overlay_size']);
});

it('resizes an open league overlay when the size changes', function () {
    Window::shouldReceive('all')->andReturn([new WindowInstance('main'), new WindowInstance('overlay')]);
    Window::shouldReceive('resize')->once()->with(...OpenOverlayWindow::windowSize('compact'), ...['overlay']);

    $this->post(route('settings.overlay'), ['overlay_size' => 'compact'])->assertRedirect();
});

it('does not resize when the overlay window is closed', function () {
    Window::shouldReceive('all')->andReturn([new WindowInstance('main')]);
    Window::shouldReceive('resize')->never();

    $this->post(route('settings.overlay'), ['overlay_size' => 'compact'])->assertRedirect();
});

it('pushes an overlay reload after uploading and deleting a background', function () {
    Event::fake([LeagueOverlayChanged::class]);

    $this->post(route('settings.overlay.background.upload'), ['image' => UploadedFile::fake()->image('bg.png', 900, 300)]);
    $this->delete(route('settings.overlay.background.delete'));

    Event::assertDispatchedTimes(LeagueOverlayChanged::class, 2);
});

it('passes artwork, size and deck art to the settings page', function () {
    $card = Card::factory()->create(['art_crop' => 'https://cards.example/karn.jpg', 'local_art_crop' => null]);
    $deck = Deck::factory()->create(['cover_id' => $card->id]);
    MtgoMatch::factory()->create(['deck_version_id' => DeckVersion::factory()->create(['deck_id' => $deck->id])->id]);

    $this->get(route('settings.overlays'))
        ->assertInertia(fn ($page) => $page
            ->where('overlayArtwork', 'deck')
            ->where('overlaySize', 'full')
            ->where('overlayDeckArtUrl', 'https://cards.example/karn.jpg'));
});
