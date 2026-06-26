<?php

use App\Facades\AppSettings;
use App\Jobs\RunSchemaUpgradeJob;
use App\Managers\MtgoManager;
use App\Models\Account;
use App\Models\MtgoMatch;
use App\Models\SchemaUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// EnsureSchemaUpgraded middleware — gate behaviour
// ---------------------------------------------------------------------------

it('redirects to upgrade.show in legacy state (version 0 + null account_id match)', function () {
    AppSettings::setDataSchemaVersion(0);
    MtgoMatch::factory()->create(['account_id' => null]);

    $this->get('/')->assertRedirect(route('upgrade.show'));
});

it('does not redirect the allow-listed upgrade route in legacy state', function () {
    AppSettings::setDataSchemaVersion(0);
    MtgoMatch::factory()->create(['account_id' => null]);

    $this->get(route('upgrade.show'))->assertSuccessful();
});

it('does not redirect upgrade/* sub-paths in legacy state', function () {
    AppSettings::setDataSchemaVersion(0);
    MtgoMatch::factory()->create(['account_id' => null]);

    $upgrade = SchemaUpgrade::create(['status' => 'pending']);

    // upgrade.status is under upgrade/*
    $this->get(route('upgrade.status', $upgrade->id))->assertSuccessful();
});

it('passes through any route when the version is already at target', function () {
    AppSettings::setDataSchemaVersion(SchemaUpgrade::TARGET_DATA_VERSION);
    MtgoMatch::factory()->create(['account_id' => null]);

    $this->get('/')->assertSuccessful();
});

it('passes through and auto-bumps the version on a fresh install (version 0, no matches)', function () {
    AppSettings::setDataSchemaVersion(0);
    // No matches in DB

    $this->get('/')->assertSuccessful();

    expect(AppSettings::dataSchemaVersion())
        ->toBe(SchemaUpgrade::TARGET_DATA_VERSION);
});

it('does not redirect when version 0 but all matches have account_id set', function () {
    AppSettings::setDataSchemaVersion(0);
    $account = Account::factory()->create();
    MtgoMatch::factory()->create(['account_id' => $account->id]);

    $this->get('/')->assertSuccessful();

    expect(AppSettings::dataSchemaVersion())
        ->toBe(SchemaUpgrade::TARGET_DATA_VERSION);
});

// ---------------------------------------------------------------------------
// MtgoManager::canRun() — daemon pause
// ---------------------------------------------------------------------------

/**
 * Create a temporary directory containing an mtgo.log file and register it
 * via AppSettings so that pathsAreValid() returns true in the test env.
 * Returns the directory path so the caller can clean up.
 */
function makeValidLogDir(): string
{
    $dir = sys_get_temp_dir().'/mtgo_test_logs_'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/mtgo.log', '');

    return $dir;
}

it('canRun returns false when needsUpgrade is true, even with valid paths and active watcher', function () {
    // Legacy state — needsUpgrade() will return true
    AppSettings::setDataSchemaVersion(0);
    AppSettings::setWatcherActive(true);
    MtgoMatch::factory()->create(['account_id' => null]);

    // Satisfy pathsAreValid() so needsUpgrade() is the ONLY blocking condition
    $logDir = makeValidLogDir();
    AppSettings::setLogPath($logDir);

    $manager = app(MtgoManager::class);

    expect($manager->canRun())->toBeFalse();

    // Cleanup
    @unlink($logDir.'/mtgo.log');
    @rmdir($logDir);
});

it('canRun returns true once the version is bumped to TARGET with valid paths and active watcher', function () {
    AppSettings::setWatcherActive(true);
    MtgoMatch::factory()->create(['account_id' => null]);

    // Satisfy pathsAreValid() so the upgrade gate is the only variable
    $logDir = makeValidLogDir();
    AppSettings::setLogPath($logDir);

    $manager = app(MtgoManager::class);

    // In legacy state canRun must be false (guards are working)
    AppSettings::setDataSchemaVersion(0);
    expect($manager->canRun())->toBeFalse();

    // After bumping to TARGET, canRun must be true
    AppSettings::setDataSchemaVersion(SchemaUpgrade::TARGET_DATA_VERSION);
    expect($manager->canRun())->toBeTrue();

    // Cleanup
    @unlink($logDir.'/mtgo.log');
    @rmdir($logDir);
});

// ---------------------------------------------------------------------------
// RunSchemaUpgradeJob — version bump on completion
// ---------------------------------------------------------------------------

it('RunSchemaUpgradeJob bumps data_schema_version to TARGET after a successful run', function () {
    AppSettings::setDataSchemaVersion(0);

    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    DB::table('players')->insert(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('players')->insert(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    MtgoMatch::factory()->create(['account_id' => null, 'opponent_id' => null]);

    $tracker = SchemaUpgrade::create(['status' => 'pending']);

    // Dispatch synchronously (test queue driver is sync)
    RunSchemaUpgradeJob::dispatchSync($tracker->id);

    expect(AppSettings::dataSchemaVersion())
        ->toBe(SchemaUpgrade::TARGET_DATA_VERSION);
});
