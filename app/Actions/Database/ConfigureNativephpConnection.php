<?php

declare(strict_types=1);

namespace App\Actions\Database;

use Illuminate\Support\Facades\DB;
use Native\Desktop\NativeServiceProvider;

/**
 * Restores this app's SQLite settings on the connection NativePHP builds.
 *
 * {@see NativeServiceProvider::rewriteDatabase()} replaces
 * `database.connections.nativephp` with five hardcoded keys and makes it the
 * default, so the tuning in `config/database.php` never reaches the running
 * app. Without it Laravel's SQLite connector leaves busy_timeout at 0 and
 * every writer fails the instant another holds the lock.
 *
 * `transaction_mode` is the one that matters most. A DEFERRED transaction
 * that reads and then upgrades to a write, which is exactly what the database
 * queue's `pop()` does on every poll, gets SQLITE_BUSY returned immediately
 * and busy_timeout is never consulted. IMMEDIATE takes the write lock up
 * front, so contending writers wait out the timeout instead of throwing.
 * Laravel emits it via SQLiteConnection::executeBeginTransactionStatement(),
 * which needs PHP 8.4 or newer; on anything older the key is ignored and the
 * remaining settings still apply.
 */
class ConfigureNativephpConnection
{
    public static function run(): void
    {
        if (config('database.connections.nativephp') === null) {
            return;
        }

        config([
            'database.connections.nativephp.transaction_mode' => 'IMMEDIATE',
            'database.connections.nativephp.busy_timeout' => 30000,
            'database.connections.nativephp.journal_mode' => 'WAL',
            'database.connections.nativephp.synchronous' => 'NORMAL',
        ]);

        // A Connection keeps the config array it was built with, and
        // NativePHP resolves this one during its own boot to run a PRAGMA, so
        // patching config alone would leave the live connection on DEFERRED
        // transactions and a 5000ms timeout. Purging drops it; the next
        // resolution reads the settings above and the connector applies the
        // pragmas itself.
        DB::purge('nativephp');
    }
}
