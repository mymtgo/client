<?php

use App\Facades\AppSettings;
use App\Jobs\PopulateMissingCardData;
use App\Managers\MtgoManager;
use App\Models\Card;
use App\Services\Sync\SyncApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::set('debug_mode', true);
});

/** A fake MTGO data path holding a CardDataSource folder with the four files. */
function fakeCardDataSource(): string
{
    $root = sys_get_temp_dir().'/mtgo-upload-test-'.uniqid();
    File::ensureDirectoryExists("{$root}/Data/CardDataSource");

    foreach (['client_TOK', 'CARDNAME_STRING', 'COLOR', 'PRINTEDCARDSET_STRING'] as $name) {
        File::put("{$root}/Data/CardDataSource/{$name}.xml", "<{$name}/>");
    }

    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('getLogDataPath')->andReturn($root);
    app()->instance('mtgo', $mock);

    return $root;
}

it('uploads the local token catalog and re-resolves token rows', function () {
    Bus::fake();
    fakeCardDataSource();
    $token = Card::factory()->create(['mtgo_id' => '125873', 'type' => 'Token Creature', 'scryfall_id' => 'green-cat']);

    $api = Mockery::mock(SyncApi::class);
    $api->shouldReceive('uploadTokenCatalog')->once()
        ->with(Mockery::on(fn (array $files) => array_keys($files) === ['client_TOK', 'CARDNAME_STRING', 'COLOR', 'PRINTEDCARDSET_STRING']
            && $files['COLOR'] === '<COLOR/>'))
        ->andReturn(['added' => 4900, 'already_mapped' => 0, 'tiers' => [], 'unmatched' => []]);
    app()->instance(SyncApi::class, $api);

    $this->post('/debug/cards/token-catalog')->assertRedirect();

    expect($token->fresh()->scryfall_id)->toBeNull();
    Bus::assertDispatched(PopulateMissingCardData::class);
});

it('reports a missing MTGO install without calling the API', function () {
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('getLogDataPath')->andReturn(sys_get_temp_dir().'/no-mtgo-here-'.uniqid());
    app()->instance('mtgo', $mock);

    $api = Mockery::mock(SyncApi::class);
    $api->shouldNotReceive('uploadTokenCatalog');
    app()->instance(SyncApi::class, $api);

    $this->post('/debug/cards/token-catalog')->assertRedirect()->assertSessionHasErrors('catalog');
});
