<?php

/*
 * The farewell release leaves the 0.x database exactly as 0.46.0 left it, so
 * MyMTGO 1.0's import reads the same file. No database trait here: the test
 * owns a real database file and compares its bytes.
 */

use App\Facades\Mtgo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

    $before = hash_file('sha256', $path);

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
