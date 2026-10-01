<?php

use App\Events\LeagueOverlayChanged;
use App\Facades\AppSettings;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('saves the label and pushes an overlay reload', function () {
    Event::fake([LeagueOverlayChanged::class]);
    $deck = Deck::factory()->create();

    $this->putJson(route('settings.overlay.deck-label'), ['deck_id' => $deck->id, 'label' => ' Big Mana '])
        ->assertNoContent();

    expect(AppSettings::overlayDeckLabels())->toBe([$deck->id => 'Big Mana']);
    Event::assertDispatched(LeagueOverlayChanged::class);
});

it('removes the label when blank', function () {
    $deck = Deck::factory()->create();
    AppSettings::setOverlayDeckLabel($deck->id, 'Old');

    $this->putJson(route('settings.overlay.deck-label'), ['deck_id' => $deck->id, 'label' => null])->assertNoContent();

    expect(AppSettings::overlayDeckLabels())->toBe([]);
});

it('rejects an unknown deck', function () {
    $this->putJson(route('settings.overlay.deck-label'), ['deck_id' => 999999, 'label' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('deck_id');
});
