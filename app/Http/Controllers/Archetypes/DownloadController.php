<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\RegisterDevice;
use App\Jobs\DownloadArchetypes;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DownloadController
{
    public function __invoke(): RedirectResponse
    {
        try {
            DownloadArchetypes::dispatchSync();
        } catch (\Throwable $e) {
            $context = [
                'exception' => $e,
                'has_api_key' => (bool) RegisterDevice::retrieveKey(),
                'ping' => $this->pingApi(),
            ];

            if ($e instanceof RequestException && $e->response) {
                $context['status'] = $e->response->status();
                $context['body'] = mb_substr((string) $e->response->body(), 0, 500);
            }

            Log::error('DownloadArchetypes failed', $context);
            report($e);

            return back()->with('error', 'Could not connect to the archetype server. Please check your internet connection and try again.');
        }

        return back();
    }

    /**
     * @return array{ok: bool, status?: int, error?: string}
     */
    private function pingApi(): array
    {
        try {
            $response = Http::timeout(5)->get(rtrim(config('mymtgo_api.url'), '/').'/up');

            return ['ok' => $response->successful(), 'status' => $response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
        }
    }
}
