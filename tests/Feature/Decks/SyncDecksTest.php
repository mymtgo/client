<?php

namespace Tests\Feature\Decks;

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Decks\GetDeckFiles;
use App\Actions\Decks\SyncDecks;
use App\Facades\Mtgo;
use App\Models\Card;
use App\Models\Deck;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncDecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.user_home' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/user_home'),
        ]]);

        $path = storage_path('framework/testing/disks/user_home/AppData/Local/Apps/2.0');

        (new Filesystem)->cleanDirectory(storage_path('framework/testing/disks/user_home'));
        mkdir($path, 0777, true);

        Mtgo::shouldReceive('getLogPath')->andReturn($path)->byDefault();
        Mtgo::shouldReceive('getLogDataPath')->andReturn($path.'/Data')->byDefault();
        Mtgo::shouldReceive('getUsername')->andReturn(null)->byDefault();

        Http::fake();
    }

    public function test_it_soft_deletes_decks_that_are_no_longer_present_in_files()
    {
        // Ensure FindMtgoLogPath doesn't hit MtgoManager's getLogPath directly if it's cached or something
        // But since we mocked 'mtgo' facade, it should use the mock.

        // Create a deck in the database
        $deck = Deck::factory()->create(['mtgo_id' => 'test-deck-1', 'name' => 'Old Deck']);

        // Mock GetDeckFiles to return empty array (as if file was deleted)
        // We can still mock GetDeckFiles directly for this simple test
        $this->mock(GetDeckFiles::class, function ($mock) {
            $mock->shouldReceive('run')->andReturn([]);
        });

        SyncDecks::run();

        $this->assertSoftDeleted($deck);
    }

    public function test_it_keeps_decks_that_are_present_in_the_active_log_directory()
    {
        $deckId = '75d11598-f222-488b-a249-14a09e075727';
        $deck = Deck::factory()->create(['mtgo_id' => $deckId, 'name' => 'Active Deck']);
        Card::factory()->create(['mtgo_id' => 123]);

        $path = storage_path('framework/testing/disks/user_home/AppData/Local/Apps/2.0');
        $randomHash = 'abcdef123456';
        $activePath = $path.'/'.$randomHash;
        $activeDataPath = $path.'/Data/'.$randomHash;

        if (! file_exists($activePath)) {
            mkdir($activePath, 0777, true);
        }
        if (! file_exists($activeDataPath)) {
            mkdir($activeDataPath, 0777, true);
        }

        // Create a dummy log file to make it the "active" path
        $logFile = $activePath.'/mtgo.log';
        file_put_contents($logFile, 'dummy log content');

        // Mark this as the active data directory via user_settings
        file_put_contents($activeDataPath.'/user_settings', '');

        // Create the deck XML in the active Data path (common MTGO structure)
        $xmlContent = <<<XML
<Grouping Name="Active Deck" NetDeckId="{$deckId}" GroupingType="Deck" Timestamp="2026-01-21T10:00:00" FormatCode="Standard">
    <Item CatId="123" Quantity="4" IsSideboard="false" />
</Grouping>
XML;
        $deckFile = $activeDataPath.'/grouping '.$deckId.'.xml';
        file_put_contents($deckFile, $xmlContent);

        SyncDecks::run();

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_soft_deletes_decks_that_are_only_present_in_stale_directories()
    {
        $deckId = '75d11598-f222-488b-a249-14a09e075727';
        $deck = Deck::factory()->create(['mtgo_id' => $deckId, 'name' => 'Stale Deck']);
        Card::factory()->create(['mtgo_id' => 123]);

        $path = storage_path('framework/testing/disks/user_home/AppData/Local/Apps/2.0');
        $activePath = $path.'/active';
        $stalePath = $path.'/stale';

        if (! file_exists($activePath)) {
            mkdir($activePath, 0777, true);
        }
        if (! file_exists($stalePath)) {
            mkdir($stalePath, 0777, true);
        }

        // Active log is in activePath
        file_put_contents($activePath.'/mtgo.log', 'active');
        touch($activePath.'/mtgo.log', time());

        // Stale deck file is in stalePath
        $xmlContent = <<<XML
<Grouping Name="Stale Deck" NetDeckId="{$deckId}" GroupingType="Deck" Timestamp="2026-01-21T10:00:00" FormatCode="Standard">
    <Item CatId="123" Quantity="4" IsSideboard="false" />
</Grouping>
XML;
        file_put_contents($stalePath.'/grouping '.$deckId.'.xml', $xmlContent);

        SyncDecks::run();

        // Should be soft deleted because it's not in the active directory
        $this->assertSoftDeleted($deck);
    }

    public function test_it_produces_canonical_signature_regardless_of_xml_sideboard_capitalization()
    {
        $deckId = 'caab1001-0000-0000-0000-000000002001';
        Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
        Card::factory()->create(['mtgo_id' => 2001, 'oracle_id' => 'oracle-2001']);

        $path = storage_path('framework/testing/disks/user_home/AppData/Local/Apps/2.0');
        $randomHash = 'capstest123456';
        $activePath = $path.'/'.$randomHash;
        $activeDataPath = $path.'/Data/'.$randomHash;

        if (! file_exists($activePath)) {
            mkdir($activePath, 0777, true);
        }
        if (! file_exists($activeDataPath)) {
            mkdir($activeDataPath, 0777, true);
        }

        file_put_contents($activePath.'/mtgo.log', 'dummy log content');
        file_put_contents($activeDataPath.'/user_settings', '');

        $xmlContent = <<<XML
<Grouping Name="Caps Test" NetDeckId="{$deckId}" GroupingType="Deck" Timestamp="2026-04-29T10:00:00" FormatCode="Standard">
  <Item CatId="1001" Quantity="4" IsSideboard="False" />
  <Item CatId="2001" Quantity="3" IsSideboard="True" />
</Grouping>
XML;
        $deckFile = $activeDataPath.'/grouping '.$deckId.'.xml';
        file_put_contents($deckFile, $xmlContent);

        SyncDecks::run();

        $deck = Deck::where('mtgo_id', $deckId)->first();
        $version = $deck->versions()->first();

        $expected = GenerateDeckSignature::run(collect([
            ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
            ['mtgo_id' => 2001, 'quantity' => 3, 'sideboard' => 'true'],
        ]));

        $this->assertSame($expected, $version->signature);
    }
}
