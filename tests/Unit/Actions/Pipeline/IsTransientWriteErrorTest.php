<?php

use App\Actions\Pipeline\IsTransientWriteError;
use Illuminate\Database\QueryException;
use Tests\TestCase;

uses(TestCase::class);

function pdoExceptionWithCode(int $nativeCode, string $message = 'error'): PDOException
{
    $e = new PDOException($message);
    $e->errorInfo = ['HY000', $nativeCode, $message];

    return $e;
}

function queryExceptionWithCode(int $nativeCode, string $message = 'error'): QueryException
{
    return new QueryException(
        connectionName: 'nativephp',
        sql: 'update "matches" set "state" = ? where "id" = ?',
        bindings: ['InProgress', 1],
        previous: pdoExceptionWithCode($nativeCode, $message),
    );
}

it('classifies SQLITE_BUSY (5) as transient', function () {
    expect(IsTransientWriteError::run(pdoExceptionWithCode(5, 'database is locked')))->toBeTrue();
});

it('classifies SQLITE_LOCKED (6) as transient', function () {
    expect(IsTransientWriteError::run(pdoExceptionWithCode(6, 'database table is locked')))->toBeTrue();
});

it('classifies SQLITE_READONLY (8) as transient', function () {
    expect(IsTransientWriteError::run(pdoExceptionWithCode(8, 'attempt to write a readonly database')))->toBeTrue();
});

it('classifies SQLITE_IOERR (10) as transient', function () {
    expect(IsTransientWriteError::run(pdoExceptionWithCode(10, 'disk I/O error')))->toBeTrue();
});

it('classifies extended SQLITE_BUSY_SNAPSHOT (517) as transient', function () {
    expect(IsTransientWriteError::run(pdoExceptionWithCode(517, 'database is locked')))->toBeTrue();
});

it('classifies extended SQLITE_READONLY_DBMOVED (776) as transient', function () {
    expect(IsTransientWriteError::run(pdoExceptionWithCode(776, 'readonly database')))->toBeTrue();
});

it('classifies SQLITE_CONSTRAINT (19) as NOT transient', function () {
    expect(IsTransientWriteError::run(pdoExceptionWithCode(19, 'UNIQUE constraint failed')))->toBeFalse();
});

it('unwraps QueryException that wraps a transient PDOException', function () {
    expect(IsTransientWriteError::run(queryExceptionWithCode(5)))->toBeTrue();
});

it('unwraps QueryException that wraps a non-transient PDOException', function () {
    expect(IsTransientWriteError::run(queryExceptionWithCode(19)))->toBeFalse();
});

it('falls back to message match when errorInfo is missing', function () {
    $e = new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked (Connection: nativephp)');

    expect(IsTransientWriteError::run($e))->toBeTrue();
});

it('matches "readonly database" message fallback', function () {
    $e = new RuntimeException('attempt to write a readonly database');

    expect(IsTransientWriteError::run($e))->toBeTrue();
});

it('matches "disk I/O" message fallback', function () {
    $e = new RuntimeException('disk I/O error');

    expect(IsTransientWriteError::run($e))->toBeTrue();
});

it('does NOT classify generic RuntimeException as transient', function () {
    expect(IsTransientWriteError::run(new RuntimeException('something else broke')))->toBeFalse();
});

it('does NOT classify non-Throwable payloads as transient', function () {
    // Defensive: the classifier should be total for Throwable inputs only
    expect(IsTransientWriteError::run(new LogicException('bad state')))->toBeFalse();
});
