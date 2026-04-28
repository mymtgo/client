<?php

use App\Facades\AppSettings;
use App\Jobs\DownloadArchetypes;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('walks through the full setup flow', function () {
    // Reset to fresh-install state.
    AppSettings::setSetupCompleted(false);
    AppSettings::setSetupSkippedArchetypes(false);
    AppSettings::setSetupSkippedDecks(false);

    // Hitting / redirects to /setup when not complete.
    $this->get('/')->assertRedirect('/setup');

    // /setup renders.
    $this->get('/setup')->assertOk();

    // Save log path (use a temp dir).
    $logDir = sys_get_temp_dir().'/setup-e2e-log-'.uniqid();
    mkdir($logDir);
    $this->patch('/setup/log-path', ['path' => $logDir])->assertRedirect('/setup');

    $dataDir = sys_get_temp_dir().'/setup-e2e-data-'.uniqid();
    mkdir($dataDir);
    $this->patch('/setup/data-path', ['path' => $dataDir])->assertRedirect('/setup');

    // Trigger archetype download (faked).
    Bus::fake([DownloadArchetypes::class]);
    $this->post('/setup/archetypes/download')->assertRedirect('/setup');
    Bus::assertDispatchedSync(DownloadArchetypes::class);

    // Sync decks (empty data path → no-op).
    $this->post('/setup/decks/sync')->assertRedirect('/setup');

    // Seed an archetype so middleware allows post-setup navigation.
    Archetype::factory()->create();

    // Complete with next=app.
    $this->post('/setup/complete', ['next' => 'app'])->assertRedirect('/');

    // Setup flag is now true, archetypes exist → / no longer redirects.
    expect(AppSettings::setupCompleted())->toBeTrue();
});

it('walks through the setup flow with skips and goes to /import', function () {
    AppSettings::setSetupCompleted(false);
    AppSettings::setSetupSkippedArchetypes(false);
    AppSettings::setSetupSkippedDecks(false);

    // Save paths.
    $logDir = sys_get_temp_dir().'/setup-e2e-log2-'.uniqid();
    mkdir($logDir);
    $this->patch('/setup/log-path', ['path' => $logDir])->assertRedirect('/setup');
    $dataDir = sys_get_temp_dir().'/setup-e2e-data2-'.uniqid();
    mkdir($dataDir);
    $this->patch('/setup/data-path', ['path' => $dataDir])->assertRedirect('/setup');

    // Skip archetypes.
    $this->post('/setup/archetypes/skip')->assertRedirect('/setup');
    expect(AppSettings::setupSkippedArchetypes())->toBeTrue();

    // Skip decks.
    $this->post('/setup/decks/skip')->assertRedirect('/setup');
    expect(AppSettings::setupSkippedDecks())->toBeTrue();

    // Complete to import.
    $this->post('/setup/complete', ['next' => 'import'])->assertRedirect('/import');

    // Skipped archetypes → middleware allows / despite no archetypes seeded.
    $this->get('/')->assertOk();
});
