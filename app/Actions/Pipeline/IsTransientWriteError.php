<?php

namespace App\Actions\Pipeline;

use PDOException;
use Throwable;

class IsTransientWriteError
{
    /**
     * SQLite primary result codes that indicate a transient write failure.
     * The app should back off and retry on the next pipeline tick rather
     * than counting these toward the per-match retry budget.
     *
     * @var array<int, int>
     */
    private const TRANSIENT_CODES = [
        5,  // SQLITE_BUSY
        6,  // SQLITE_LOCKED
        8,  // SQLITE_READONLY
        10, // SQLITE_IOERR
    ];

    /**
     * Substrings in exception messages that indicate a transient write error.
     * Used as a belt-and-suspenders fallback when errorInfo isn't populated
     * (e.g. exceptions raised by a wrapper that drops the PDO metadata).
     *
     * @var array<int, string>
     */
    private const TRANSIENT_MESSAGES = [
        'database is locked',
        'readonly database',
        'disk I/O',
    ];

    public static function run(Throwable $e): bool
    {
        if (self::matchesTransientPdoCode($e)) {
            return true;
        }

        $previous = $e->getPrevious();
        if ($previous instanceof Throwable && self::matchesTransientPdoCode($previous)) {
            return true;
        }

        return self::matchesTransientMessage($e->getMessage());
    }

    private static function matchesTransientPdoCode(Throwable $e): bool
    {
        if (! $e instanceof PDOException) {
            return false;
        }

        $nativeCode = $e->errorInfo[1] ?? null;

        if (! is_int($nativeCode)) {
            return false;
        }

        // SQLite extended codes occupy the upper bits; mask to the primary code.
        return in_array($nativeCode & 0xFF, self::TRANSIENT_CODES, true);
    }

    private static function matchesTransientMessage(string $message): bool
    {
        foreach (self::TRANSIENT_MESSAGES as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
