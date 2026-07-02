<?php

namespace App\Actions\Outbox;

use App\Facades\AppSettings;
use App\Models\Outbox;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Push one outbox row to the cloud sink (POST /api/matches) through the
 * Bearer macro (silent 401 refresh-and-retry). No stored session → hold
 * silently (no attempt burned — the user may simply not be signed in yet).
 * The synced flag is version-guarded so an ack for v1 never stomps a v2
 * that raced in mid-push.
 */
final class PushMatch
{
    public const MAX_ATTEMPTS = 10;

    public function run(Outbox $row): void
    {
        if (AppSettings::oauthTokens() === null) {
            return;
        }

        $sentVersion = $row->file_version;

        try {
            $response = Http::mymtgoAuthed()
                ->timeout(15)
                ->connectTimeout(5)
                ->post('/api/matches', $row->payload);
        } catch (Throwable $e) {
            $this->recordFailure($row, $e->getMessage());

            return;
        }

        if (! $response->successful()) {
            $this->recordFailure($row, "HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500));

            return;
        }

        // Version-guarded ack: only flip to synced if no newer compile
        // re-pended the row while this push was in flight.
        Outbox::query()
            ->whereKey($row->id)
            ->where('file_version', $sentVersion)
            ->update([
                'status' => 'synced',
                'synced_version' => $sentVersion,
                'last_attempt_at' => now(),
                'last_error' => null,
            ]);

        Outbox::query()
            ->whereKey($row->id)
            ->where('file_version', '>', $sentVersion)
            ->update([
                'synced_version' => $sentVersion,
                'last_attempt_at' => now(),
            ]);
    }

    private function recordFailure(Outbox $row, string $error): void
    {
        $attempts = $row->attempts + 1;

        $row->update([
            'attempts' => $attempts,
            'last_attempt_at' => now(),
            'last_error' => $error,
            'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
        ]);
    }
}
