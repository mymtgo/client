<?php

use App\Actions\SideboardGuides\GetVersionZoneQuantities;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sums copies per oracle id by zone, collapsing split cards', function () {
    Card::create(['mtgo_id' => '201', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment']);
    Card::create(['mtgo_id' => '202', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);
    Card::create(['mtgo_id' => '302', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $version = DeckVersion::create([
        'deck_id' => $deck->id,
        // Two Bolt printings in the main, one Bolt in the board, two RiP in the board.
        'signature' => base64_encode('202:2:0|302:1:0|202:1:1|201:2:1'),
        'modified_at' => now(),
    ]);

    $zones = GetVersionZoneQuantities::run($version);

    expect($zones['in'])->toBe(['o-bolt' => 1, 'o-rip' => 2]);
    expect($zones['out'])->toBe(['o-bolt' => 3]);
});
