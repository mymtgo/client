<?php

namespace App\Jobs;

use App\Actions\RegisterDevice;
use App\Exceptions\OfflineModeException;
use App\Facades\AppSettings;
use App\Models\CardStatShipQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShipCardStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CLAIM_LIMIT = 200;

    public const HTTP_CHUNK = 100;

    public const MAX_ATTEMPTS = 20;

    public int $tries = 1;

    public int $maxExceptions = 1;

    /**
     * Set by send() when offline mode was toggled on between the handle()
     * guard and the request. Distinguishes "skip quietly" from "real
     * failure" for a null return, since ?int has no room for a third state.
     */
    private bool $offlineDuringSend = false;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('ship-card-stats'))->dontRelease()];
    }

    public function handle(): void
    {
        if (AppSettings::isOffline()) {
            return;
        }

        $rows = $this->claim();

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows->chunk(self::HTTP_CHUNK) as $chunk) {
            $status = $this->send($chunk);

            if ($status === null) {
                if ($this->offlineDuringSend) {
                    // Release every row this run claimed but never got to
                    // send — the current chunk and any still queued behind
                    // it — back to pending. No backoff, no attempt charged:
                    // this wasn't a failed request, it just never went out.
                    $this->releaseStrandedClaims($rows);

                    return;
                }

                $this->markFailure($chunk, 'request threw exception');

                continue;
            }

            if ($status >= 200 && $status < 300) {
                $this->markSent($chunk);

                continue;
            }

            if ($status === 401) {
                RegisterDevice::run();
            }

            $this->markFailure($chunk, "HTTP {$status}");
        }
    }

    /**
     * @return Collection<int, CardStatShipQueue>
     */
    private function claim(): Collection
    {
        return DB::transaction(function () {
            $rows = CardStatShipQueue::query()
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id')
                ->limit(self::CLAIM_LIMIT)
                ->lockForUpdate()
                ->get();

            if ($rows->isNotEmpty()) {
                CardStatShipQueue::whereIn('id', $rows->pluck('id'))
                    ->update(['status' => 'sending', 'updated_at' => now()]);
            }

            return $rows;
        });
    }

    /**
     * @param  Collection<int, CardStatShipQueue>  $chunk
     */
    private function send(Collection $chunk): ?int
    {
        $games = $chunk->map(function (CardStatShipQueue $row) {
            return is_array($row->payload) ? $row->payload : json_decode($row->payload, true);
        })->values()->all();

        $body = ['games' => $games];
        $gz = gzencode(json_encode($body));

        try {
            $response = Http::mymtgoApi()
                ->withHeaders([
                    'Content-Encoding' => 'gzip',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(30)
                ->connectTimeout(10)
                ->withBody($gz, 'application/json')
                ->post('/api/card-stats/report');

            return $response->status();
        } catch (OfflineModeException) {
            $this->offlineDuringSend = true;

            return null;
        } catch (Throwable $e) {
            Log::warning('ShipCardStats: send failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  Collection<int, CardStatShipQueue>  $rows
     */
    private function releaseStrandedClaims(Collection $rows): void
    {
        CardStatShipQueue::whereIn('id', $rows->pluck('id'))
            ->where('status', 'sending')
            ->update(['status' => 'pending', 'updated_at' => now()]);
    }

    /**
     * @param  Collection<int, CardStatShipQueue>  $chunk
     */
    private function markSent(Collection $chunk): void
    {
        CardStatShipQueue::whereIn('id', $chunk->pluck('id'))
            ->update([
                'status' => 'sent',
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  Collection<int, CardStatShipQueue>  $chunk
     */
    private function markFailure(Collection $chunk, string $error): void
    {
        foreach ($chunk as $row) {
            $attempts = $row->attempts + 1;
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $backoffSeconds = min(300, 5 * (2 ** $attempts));

            CardStatShipQueue::where('id', $row->id)->update([
                'status' => $status,
                'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds($backoffSeconds),
                'last_error' => substr($error, 0, 500),
                'updated_at' => now(),
            ]);
        }
    }
}
