<?php

namespace App\Jobs;

use App\Actions\RegisterDevice;
use App\Facades\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubmitMatchLogSample implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $matchToken,
        public string $matchType,
        public string $format,
        public string $rawText,
        public ?string $username,
    ) {}

    public function handle(): void
    {
        if (! AppSettings::shouldTransmitMatches()) {
            return;
        }

        $response = Http::withHeaders([
            'X-Device-Id' => AppSettings::deviceId(),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->post(config('mymtgo_api.url').'/api/match-log-samples', [
            'match_token' => $this->matchToken,
            'match_type' => $this->matchType,
            'format' => $this->format,
            'username' => $this->username,
            'raw_text' => $this->rawText,
        ]);

        if ($response->status() === 401) {
            RegisterDevice::run();
            $this->release(30);

            return;
        }

        if (! $response->successful()) {
            Log::warning('SubmitMatchLogSample: non-2xx', [
                'match_token' => $this->matchToken,
                'status' => $response->status(),
            ]);
        }
    }
}
