<?php

use App\Actions\Archetypes\ApplyArchetypeRefresh;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\CheckArchetypeVersion;
use App\Jobs\DownloadArchetypes;
use App\Managers\MtgoManager;
use App\Models\Archetype;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function fakeVersionedArchetypeApi(array $rows, string $version): void
{
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/archetypes/version' => Http::response(['version' => $version], 200),
        '*/api/archetypes' => Http::response($rows, 200, ['X-Archetypes-Version' => $version]),
    ]);
}

function sharedArchetypeUpdate(): array
{
    $shared = (new HandleInertiaRequests)->share(Request::create('/'));

    return value($shared['archetypeUpdate']);
}

it('stores the remote archetype version when checking', function () {
    fakeVersionedArchetypeApi([], '7');

    (new CheckArchetypeVersion)->handle();

    expect(Cache::get('archetype_remote_version'))->toBe('7');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/archetypes/version'));
});

it('does not check the remote archetype version while offline', function () {
    fakeVersionedArchetypeApi([], '7');
    AppSettings::setOffline(true);

    (new CheckArchetypeVersion)->handle();

    expect(Cache::get('archetype_remote_version'))->toBeNull();
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/archetypes/version'));
});

it('records the synced version when downloading archetypes', function () {
    fakeVersionedArchetypeApi([
        ['uuid' => (string) Str::uuid(), 'name' => 'Storm', 'format' => 'Modern', 'colorIdentity' => 'UR'],
    ], '7');

    (new DownloadArchetypes)->handle();

    expect(AppSettings::archetypeVersion())->toBe('7')
        ->and(Cache::get('archetype_remote_version'))->toBe('7');
});

it('records the synced version when applying a refresh', function () {
    Queue::fake();
    $kept = Archetype::factory()->create(['format' => 'modern']);

    fakeVersionedArchetypeApi([
        ['uuid' => $kept->uuid, 'name' => 'Renamed', 'format' => 'Modern', 'colorIdentity' => $kept->color_identity],
    ], '8');

    ApplyArchetypeRefresh::run();

    expect(AppSettings::archetypeVersion())->toBe('8')
        ->and(Cache::get('archetype_remote_version'))->toBe('8');
});

it('shares an available archetype update when the remote version differs from the synced one', function () {
    AppSettings::setArchetypeVersion('7');
    Cache::forever('archetype_remote_version', '8');

    expect(sharedArchetypeUpdate())->toBe(['available' => true, 'version' => '8']);
});

it('shares no archetype update when versions match', function () {
    AppSettings::setArchetypeVersion('8');
    Cache::forever('archetype_remote_version', '8');

    expect(sharedArchetypeUpdate()['available'])->toBeFalse();
});

it('shares no archetype update before the first archetype sync', function () {
    Cache::forever('archetype_remote_version', '8');

    expect(sharedArchetypeUpdate()['available'])->toBeFalse();
});

it('shares no archetype update when the remote version is unknown', function () {
    AppSettings::setArchetypeVersion('7');

    expect(sharedArchetypeUpdate()['available'])->toBeFalse();
});

it('marks archetypes up to date without a page when the refresh plan is empty', function () {
    $same = Archetype::factory()->create(['format' => 'modern']);
    AppSettings::setArchetypeVersion('7');

    fakeVersionedArchetypeApi([
        ['uuid' => $same->uuid, 'name' => $same->name, 'format' => $same->format, 'colorIdentity' => $same->color_identity],
    ], '8');

    $this->get(route('archetypes.refresh'))
        ->assertRedirect(route('archetypes.index'))
        ->assertSessionHas('success');

    expect(AppSettings::archetypeVersion())->toBe('8');
});

it('schedules an hourly archetype version check that skips while offline', function () {
    $schedule = app(Schedule::class);
    Mtgo::schedule($schedule);

    $event = collect($schedule->events())->first(fn ($e) => $e->description === 'check_archetype_version');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');

    AppSettings::setOffline(true);
    expect($event->filtersPass(app()))->toBeFalse();

    AppSettings::setOffline(false);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('checks the archetype version at boot when archetypes are already synced', function () {
    Queue::fake();
    Archetype::factory()->create(['is_fallback' => false]);

    (new MtgoManager)->runInitialSetup();

    Queue::assertPushed(CheckArchetypeVersion::class);
});

it('does not check the archetype version at boot while offline', function () {
    Queue::fake();
    Archetype::factory()->create(['is_fallback' => false]);
    AppSettings::setOffline(true);

    (new MtgoManager)->runInitialSetup();

    Queue::assertNotPushed(CheckArchetypeVersion::class);
});
