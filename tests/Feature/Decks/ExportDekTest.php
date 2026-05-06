<?php

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedExportableDeck(): Deck
{
    $deck = Deck::factory()->create(['name' => 'Mono Red']);
    Card::factory()->create(['mtgo_id' => 100, 'name' => 'Lightning Bolt']);
    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
        'signature' => base64_encode('100:4:0'),
    ]);

    return $deck->fresh();
}

function fakeDialogSaveResult(?string $path): void
{
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(['result' => $path])]);
}

it('writes the .dek file when the user confirms the dialog', function () {
    $deck = seedExportableDeck();

    $tmp = tempnam(sys_get_temp_dir(), 'dek').'.dek';
    fakeDialogSaveResult($tmp);

    $response = $this->post(route('decks.export-dek', ['deck' => $deck->id]));

    $response->assertOk();
    $response->assertJson(['success' => true, 'path' => $tmp]);
    expect(File::get($tmp))->toContain('Lightning Bolt');

    @unlink($tmp);
});

it('returns cancelled response when the dialog is dismissed', function () {
    $deck = seedExportableDeck();

    fakeDialogSaveResult(null);

    $this->post(route('decks.export-dek', ['deck' => $deck->id]))
        ->assertOk()
        ->assertJson(['success' => false, 'cancelled' => true]);
});

it('exports a soft-deleted deck', function () {
    $deck = seedExportableDeck();
    $deck->delete();

    $tmp = tempnam(sys_get_temp_dir(), 'dek').'.dek';
    fakeDialogSaveResult($tmp);

    $this->post(route('decks.export-dek', ['deck' => $deck->id]))
        ->assertOk()
        ->assertJson(['success' => true]);

    @unlink($tmp);
});
