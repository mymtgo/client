<?php

/*
 * The farewell release leaves the 0.x database exactly as 0.46.0 left it, so
 * MyMTGO 1.0's import reads the same file. No database trait here: the test
 * owns a real database file and compares its bytes.
 */

use App\Facades\Mtgo;
use App\Providers\AppServiceProvider;
use App\Providers\NativeAppServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Events\AutoUpdater\CheckingForUpdate;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;

beforeEach(function () {
    config(['farewell.enabled' => true]);
});

it('leaves the database file byte for byte unchanged after a request and a scheduler run', function () {
    $path = storage_path('framework/testing/farewell-'.getmypid().'.sqlite');
    @mkdir(dirname($path), 0777, true);
    @unlink($path);
    touch($path);

    config(['database.connections.farewell' => array_merge(config('database.connections.sqlite'), ['database' => $path])]);
    config(['database.default' => 'farewell']);
    DB::purge('farewell');

    Artisan::call('migrate', ['--database' => 'farewell', '--force' => true]);
    DB::disconnect('farewell');

    // The cache store as a build from .env.example would have it: in the
    // 0.x database. 0.46.0 leaves the downloaded farewell in it.
    config([
        'cache.default' => 'database',
        'cache.stores.database.connection' => 'farewell',
        'cache.stores.database.lock_connection' => 'farewell',
    ]);
    DB::connection('farewell')->table('cache')->insert([
        'key' => config('cache.prefix').'updater.downloaded',
        'value' => serialize(['version' => '0.47.0']),
        'expiration' => 2147483647,
    ]);
    DB::disconnect('farewell');

    // The app's own wiring, as at every launch.
    (new AppServiceProvider(app()))->register();

    $before = hash_file('sha256', $path);

    // Boot, then the updater events electron-updater sends at boot and on
    // each periodic check, through NativePHP's own events endpoint.
    (new NativeAppServiceProvider)->boot();

    $events = [
        [CheckingForUpdate::class, []],
        [UpdateNotAvailable::class, ['0.47.0', [], '2026-10-10T00:00:00.000Z']],
        [UpdateAvailable::class, ['0.47.1', [], '2026-10-10T00:00:00.000Z']],
        [UpdateDownloaded::class, ['/tmp/MyMTGO-0.47.1.exe', '0.47.1', [], '2026-10-10T00:00:00.000Z']],
    ];
    foreach ($events as [$event, $payload]) {
        $this->postJson('/_native/api/events', ['event' => $event, 'payload' => $payload])->assertOk();
    }

    Http::fake();
    $this->get('/')->assertOk();
    $this->get('/decks/1')->assertOk();
    $this->post('/matches')->assertStatus(410);

    // Every scheduled event as the packaged app runs it.
    app()->detectEnvironment(fn () => 'production');
    $schedule = new Schedule;
    Mtgo::schedule($schedule);
    foreach ($schedule->events() as $event) {
        if ($event->filtersPass(app())) {
            $event->run(app());
        }
    }
    DB::disconnect('farewell');

    expect(hash_file('sha256', $path))->toBe($before)
        ->and(file_exists($path.'-wal') ? filesize($path.'-wal') : 0)->toBe(0);

    // The update re-check talks to the Electron bridge only, never an API.
    Http::assertNotSent(fn ($request) => ! str_starts_with($request->url(), (string) config('nativephp-internal.api_url')));

    @unlink($path);
});
