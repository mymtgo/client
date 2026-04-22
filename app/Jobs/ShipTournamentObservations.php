<?php

namespace App\Jobs;

use App\Actions\RegisterDevice;
use App\Facades\AppSettings;
use App\Models\TournamentObservationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShipTournamentObservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH_LIMIT = 200;

    public const MAX_ATTEMPTS = 20;

    public function handle(): void
    {
        $rows = $this->claim();

        if ($rows->isEmpty()) {
            return;
        }

        $response = $this->send($rows);

        if ($response === null) {
            $this->markFailure($rows, 'request threw exception');

            return;
        }

        if ($response >= 200 && $response < 300) {
            TournamentObservationQueue::whereIn('id', $rows->pluck('id'))
                ->update([
                    'status' => 'sent',
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        if ($response === 401) {
            RegisterDevice::run();
        }

        $this->markFailure($rows, "HTTP {$response}");
    }

    /**
     * @return Collection<int, TournamentObservationQueue>
     */
    private function claim(): Collection
    {
        return DB::transaction(function () {
            $rows = TournamentObservationQueue::query()
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id')
                ->limit(self::BATCH_LIMIT)
                ->lockForUpdate()
                ->get();

            if ($rows->isNotEmpty()) {
                TournamentObservationQueue::whereIn('id', $rows->pluck('id'))
                    ->update(['status' => 'sending', 'updated_at' => now()]);
            }

            return $rows;
        });
    }

    /**
     * @param  Collection<int, TournamentObservationQueue>  $rows
     */
    private function send(Collection $rows): ?int
    {
        $body = $rows->map(fn (TournamentObservationQueue $row) => [
            'tournament_token' => $row->tournament_token,
            'match_token' => $row->match_token,
            'event_type' => $row->event_type,
            'payload' => $row->payload,
            'client_observed_at' => $row->client_observed_at?->toIso8601String(),
        ])->values()->toArray();

        $gz = gzencode(json_encode($body));

        try {
            $response = Http::withHeaders([
                'X-Device-Id' => AppSettings::deviceId(),
                'X-Api-Key' => RegisterDevice::retrieveKey(),
                'Content-Encoding' => 'gzip',
                'Content-Type' => 'application/json',
            ])
                ->withBody($gz, 'application/json')
                ->post(config('mymtgo_api.url').'/api/tournament-observations');

            return $response->status();
        } catch (Throwable $e) {
            Log::warning('ShipTournamentObservations: send failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  Collection<int, TournamentObservationQueue>  $rows
     */
    private function markFailure(Collection $rows, string $error): void
    {
        foreach ($rows as $row) {
            $attempts = $row->attempts + 1;
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $backoffSeconds = min(300, 5 * (2 ** $attempts));

            TournamentObservationQueue::where('id', $row->id)->update([
                'status' => $status,
                'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds($backoffSeconds),
                'last_error' => substr($error, 0, 500),
                'updated_at' => now(),
            ]);
        }
    }
}
