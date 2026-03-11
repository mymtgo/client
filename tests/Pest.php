<?php

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

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();

        // NativePHP facades make HTTP calls to localhost:4000 (the Electron
        // backend) which doesn't exist in CI or during testing.
        \Illuminate\Support\Facades\Http::fake();
        \Native\Desktop\Facades\Window::fake()
            ->alwaysReturnWindows([
                new \Native\Desktop\Windows\Window('main'),
            ]);

        // Settings doesn't support ::fake() so we swap with an in-memory store.
        \Native\Desktop\Facades\Settings::swap(new class
        {
            protected array $store = [];

            public function get(string $key, $default = null): mixed
            {
                return $this->store[$key] ?? ($default instanceof \Closure ? $default() : $default);
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
