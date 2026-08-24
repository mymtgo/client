<?php

use App\Facades\AppSettings;
use App\Models\Account;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\Window;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();

        // Reset request-scoped Account cache between tests.
        Account::flushCurrent();

        // NativePHP facades make HTTP calls to localhost:4000 (the Electron
        // backend) which doesn't exist in CI or during testing.
        Http::fake();
        Window::fake()
            ->alwaysReturnWindows([
                new Native\Desktop\Windows\Window('main'),
            ]);

        // Legacy NativePHP Settings swap — kept during migration while call sites
        // are replaced. Remove once all production code uses AppSettings.
        Settings::swap(new class
        {
            protected array $store = [];

            public function get(string $key, $default = null): mixed
            {
                return $this->store[$key] ?? ($default instanceof Closure ? $default() : $default);
            }

            public function set(string $key, $value): void
            {
                $this->store[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->store[$key]);
            }

            public function clear(): void
            {
                $this->store = [];
            }
        });

        // AppSettings: an in-memory subclass that overrides the storage
        // primitives. Typed accessors defined on the parent class fall through
        // to these, so no per-method stubbing is required.
        AppSettings::swap(new class extends App\Settings\AppSettings
        {
            protected array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->store[$key]);
            }

            // isOffline() on the real AppSettings reads settings.json directly
            // (via a private method, so this override can't fall through to
            // it) to fail closed on a read failure that get()'s default
            // can't express. The in-memory store here never fails to read,
            // so there's nothing to fail closed on — just read the store like
            // every other accessor.
            public function isOffline(): bool
            {
                return (bool) ($this->store['offline_mode'] ?? false);
            }

            // Same private-method-resolution problem as isOffline() above:
            // offlineModeNeverSet() also reads settings.json directly on the
            // real class. The in-memory store can't fail a read, so "never
            // set" here just means the key is absent from the store.
            public function offlineModeNeverSet(): bool
            {
                return ! array_key_exists('offline_mode', $this->store);
            }
        });
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
