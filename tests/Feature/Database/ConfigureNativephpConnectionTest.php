<?php

declare(strict_types=1);

use App\Actions\Database\ConfigureNativephpConnection;
use Illuminate\Support\Facades\DB;

function fakeNativephpConnection(): void
{
    // Exactly the shape NativeServiceProvider::rewriteDatabase() writes: no
    // busy_timeout, no journal_mode, no synchronous, no transaction_mode.
    config([
        'database.connections.nativephp' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);
}

it('gives the nativephp connection immediate transactions and the sqlite settings', function () {
    fakeNativephpConnection();

    ConfigureNativephpConnection::run();

    expect(config('database.connections.nativephp'))->toMatchArray([
        'transaction_mode' => 'IMMEDIATE',
        'busy_timeout' => 30000,
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
    ]);
});

it('purges an already resolved connection so it picks the settings up', function () {
    fakeNativephpConnection();

    // NativePHP resolves the connection during its own boot, and a Connection
    // keeps the config array it was built with, so patching config alone
    // would leave the live connection on DEFERRED transactions.
    DB::connection('nativephp')->getPdo();

    ConfigureNativephpConnection::run();

    expect(DB::connection('nativephp')->getConfig('transaction_mode'))->toBe('IMMEDIATE');
});

it('does nothing when NativePHP has not created its connection', function () {
    config(['database.connections.nativephp' => null]);

    ConfigureNativephpConnection::run();

    expect(config('database.connections.nativephp'))->toBeNull();
});
