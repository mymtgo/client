<?php

/*
 * The farewell release (0.47.0). MyMTGO 1.0 replaces this app, so every page
 * shows one screen pointing to the new download, every sync and background
 * job stops, and the database is left exactly as 0.46.0 left it so that
 * MyMTGO 1.0 can import it.
 */

use App\Actions\Sync\Auth\HandleSyncOauthCallback;
use App\Actions\WhatsNew\WhatsNewContent;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Middleware\ShowFarewell;
use App\Models\MtgoMatch;
use App\Providers\NativeAppServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Shell;
use Native\Desktop\Facades\Window;

uses(LazilyRefreshDatabase::class);

const FAREWELL_TITLE = 'MyMTGO has a new app';
const FAREWELL_BODY = 'MyMTGO 1.0 replaces this app. Download it and it imports your matches, decks and settings from this app the first time it opens. This app no longer records or syncs your matches.';
const FAREWELL_DOWNLOAD_LABEL = 'Download MyMTGO';
const FAREWELL_SMARTSCREEN = "Windows may warn that it protected your PC, because MyMTGO's installer is not code signed. Choose More info, then Run anyway.";
const FAREWELL_UNINSTALL = 'Once MyMTGO is installed you can uninstall this app. Your data stays on this computer.';
const FAREWELL_DOWNLOAD_URL = 'https://mymtgo.com/tracker';

beforeEach(function () {
    // Pest.php switches the farewell off so the older page tests keep
    // exercising their controllers; these tests are the shipped app.
    config(['farewell.enabled' => true]);
});

/**
 * Every named route in the web group, with its path parameters filled in.
 *
 * @return array<int, array{name: string, method: string, uri: string}>
 */
function farewellWebRoutes(): array
{
    $routes = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || $name === 'farewell.download') {
            continue;
        }

        if (! in_array('web', $route->gatherMiddleware(), true)) {
            continue;
        }

        $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $routes[] = ['name' => $name, 'method' => $method, 'uri' => $uri];
        }
    }

    return $routes;
}

/**
 * An in-memory AppSettings that records every write.
 *
 * @param  array<string, mixed>  $store
 */
function farewellSettingsRecorder(array $store = []): App\Settings\AppSettings
{
    $recorder = new class extends App\Settings\AppSettings
    {
        /** @var array<int, string> */
        public array $writes = [];

        public array $initial = [];

        protected array $store = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        }

        public function set(string $key, mixed $value): void
        {
            $this->writes[] = $key;
            $this->store[$key] = $value;
        }

        public function forget(string $key): void
        {
            $this->writes[] = $key;
            unset($this->store[$key]);
        }

        public function isOffline(): bool
        {
            return false;
        }

        /** @param  array<string, mixed>  $store */
        public function seed(array $store): void
        {
            $this->store = $store;
        }
    };
    $recorder->seed($store);

    return $recorder;
}

it('ships with the farewell switched on', function () {
    $config = require config_path('farewell.php');

    expect($config['enabled'])->toBeTrue();
});

it('renders the farewell screen for every named web route', function () {
    $gets = array_filter(farewellWebRoutes(), fn ($route) => $route['method'] === 'GET');

    expect(count($gets))->toBeGreaterThan(50);

    foreach ($gets as $route) {
        $this->get($route['uri'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Farewell'));
    }
});

it('shows the farewell copy verbatim with the v1 download link', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Farewell')
            ->where('title', FAREWELL_TITLE)
            ->where('body', FAREWELL_BODY)
            ->where('downloadLabel', FAREWELL_DOWNLOAD_LABEL)
            ->where('downloadUrl', FAREWELL_DOWNLOAD_URL)
            ->where('smartScreen', FAREWELL_SMARTSCREEN)
            ->where('uninstall', FAREWELL_UNINSTALL)
        );
});

it('renders the farewell screen without the app layout and with no em dash', function () {
    $vue = file_get_contents(resource_path('js/pages/Farewell.vue'));

    expect($vue)->toContain('layout: (h: unknown, page: unknown) => page')
        ->and($vue)->not->toContain('AppLayout')
        ->and($vue)->not->toContain("\u{2014}");

    foreach ([FAREWELL_TITLE, FAREWELL_BODY, FAREWELL_DOWNLOAD_LABEL, FAREWELL_SMARTSCREEN, FAREWELL_UNINSTALL] as $text) {
        expect($text)->not->toContain("\u{2014}");
    }

    expect(file_get_contents(app_path('Http/Middleware/ShowFarewell.php')))->not->toContain("\u{2014}");
});

it('answers 410 to a post and to every other non-GET web route', function () {
    $this->post('/matches')->assertStatus(410);

    $others = array_filter(farewellWebRoutes(), fn ($route) => $route['method'] !== 'GET');

    expect(count($others))->toBeGreaterThan(50);

    foreach ($others as $route) {
        $this->call($route['method'], $route['uri'])->assertStatus(410);
    }
});

it('opens the v1 download on the first click after the update from 0.46.0, writing nothing', function () {
    Shell::fake();

    // An existing user: matches recorded, what's new last seen at 0.46.0, so
    // the what's-new redirect would take any request that reached it.
    config(['nativephp.version' => '0.47.0']);
    WhatsNewContent::usePath(resource_path('content/whats-new.md'));
    MtgoMatch::factory()->create();

    $recorder = farewellSettingsRecorder(['whats_new_seen_version' => '0.46.0']);
    AppSettings::swap($recorder);

    $this->get(route('farewell.download'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Farewell'))
        ->assertCookieMissing((string) config('session.cookie'));

    Shell::assertOpenedExternal(FAREWELL_DOWNLOAD_URL);

    expect($recorder->writes)->toBe([]);
});

it('renders the farewell screen for an unknown page', function () {
    $this->get('/no-such-page')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Farewell'));
});

it('makes no API call when a page is opened', function () {
    Http::fake();

    $this->get('/')->assertOk();
    $this->get('/settings/api-status')->assertOk();
    $this->post('/settings/sync')->assertStatus(410);
    $this->post('/settings/submit-matches')->assertStatus(410);

    Http::assertNothingSent();
});

it('schedules only the update re-check', function () {
    $schedule = app(Schedule::class);

    Mtgo::schedule($schedule);

    $names = collect($schedule->events())->map(fn ($event) => $event->description)->all();

    expect($names)->toBe(['check_for_updates']);
});

it('runs no queue worker, so no queued job can write to the database', function () {
    // 0.x's `updates` queue carries ReDecodeGameLogsJob, which writes game
    // logs; the update re-check itself never touches a queue.
    expect(config('nativephp.queue_workers'))->toBe([]);
});

it('boots with the updater, the tray and the main window only', function () {
    Queue::fake();
    Mtgo::spy();

    $recorder = new class extends App\Settings\AppSettings
    {
        /** @var array<int, string> */
        public array $writes = [];

        protected array $store = ['show_league_window' => true, 'show_game_overlay' => true];

        public function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        }

        public function set(string $key, mixed $value): void
        {
            $this->writes[] = $key;
            $this->store[$key] = $value;
        }

        public function forget(string $key): void
        {
            $this->writes[] = $key;
            unset($this->store[$key]);
        }

        public function isOffline(): bool
        {
            return false;
        }
    };
    AppSettings::swap($recorder);

    (new NativeAppServiceProvider)->boot();

    Mtgo::shouldNotHaveReceived('runInitialSetup');
    Mtgo::shouldNotHaveReceived('retryUnsubmittedMatches');
    Queue::assertNothingPushed();

    expect($recorder->writes)->toBe([])
        ->and(DB::table('app_updates')->count())->toBe(0);

    Window::assertOpenedCount(1);
    Window::assertOpened('main');
});

it('keeps the migration list exactly as 0.46.0 shipped it', function () {
    $migrator = app('migrator');
    $names = array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
    sort($names);

    // The 134 migrations of tag v0.46.0, by name.
    expect(count($names))->toBe(134)
        ->and(hash('sha256', implode("\n", $names)))->toBe('94d659420ce5a3c858145c1fcdd497fca0dbb4440cf6a0d1c2a07fd6c240d4bc');
});

it('opens the farewell screen from a deep link instead of handling it', function () {
    $handler = Mockery::mock(HandleSyncOauthCallback::class);
    $handler->shouldNotReceive('run');
    app()->instance(HandleSyncOauthCallback::class, $handler);

    Event::dispatch(new OpenedFromURL('mymtgo://sync/callback?code=abc&state=def'));

    Window::assertShown('main');
});

it('keeps the farewell middleware first in the web group', function () {
    $web = app(Kernel::class)->getMiddlewareGroups()['web'];

    expect($web[0])->toBe(ShowFarewell::class);
});
