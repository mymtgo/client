<?php

namespace App\Jobs;

use App\Actions\Sidecar\StartSidecarSupervisor;
use App\Facades\AppSettings;
use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarPaths;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Downloads the pinned helper release, verifies it against the hash in
 * config/sidecar.php, moves it into place and starts it. The hash in
 * config is the only trust anchor: the release's own .sha256 is never read.
 *
 * Runs on the dedicated `sidecar` worker. That worker is the queue's only
 * consumer, so the default connection's short retry_after cannot hand the
 * job to a second worker mid-download.
 */
class DownloadSidecarJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** A killed app must never hold the lock for good. */
    public int $uniqueFor = 1200;

    public function __construct(public string $runId)
    {
        $this->onQueue('sidecar');
    }

    public function uniqueId(): string
    {
        return (string) config('sidecar.version');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** @return array{timeout: int, connect_timeout: int} */
    public static function requestOptions(): array
    {
        return ['timeout' => 540, 'connect_timeout' => 10];
    }

    public function handle(): void
    {
        // Preconditions can change between dispatch and pickup. Offline mode
        // in particular is a privacy choice: no GitHub request after it.
        if (! SidecarPaths::supported()
            || ! AppSettings::sidecarEnabled()
            || AppSettings::isOffline()) {
            return;
        }

        $version = (string) config('sidecar.version');

        if (SidecarPaths::exe() !== null) {
            // A superseded run may have installed it; record it so a later
            // disappearance reads as quarantine rather than a stale download.
            SidecarDownloadStore::writeForRun($this->runId, SidecarDownloadState::ready($version, $this->runId));

            return;
        }

        if (! SidecarDownloadStore::writeForRun($this->runId, SidecarDownloadState::downloading($version, $this->runId))) {
            return;
        }

        $expected = strtolower((string) config('sidecar.sha256'));

        if ($expected === '') {
            $this->failChecksum($version, 'No sha256 pinned in config/sidecar.php');

            return;
        }

        $dir = SidecarPaths::binDirectory();

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $partial = SidecarPaths::pinnedExe().'.partial-'.$this->runId;

        try {
            $this->download($version, $partial);
        } catch (Throwable $e) {
            @unlink($partial);

            throw $e;
        }

        // A Retry or a later boot started a newer run while this one was
        // downloading. That run owns the install; this file is discarded.
        if (SidecarDownloadStore::read()->runId !== $this->runId) {
            @unlink($partial);

            return;
        }

        $actual = strtolower((string) hash_file('sha256', $partial));

        if (! hash_equals($expected, $actual)) {
            @unlink($partial);
            $this->failChecksum($version, "Expected {$expected}, got {$actual}");

            return;
        }

        if (! $this->moveIntoPlace($partial, SidecarPaths::pinnedExe())) {
            @unlink($partial);

            throw new RuntimeException('Could not move the verified helper into place');
        }

        SidecarDownloadStore::writeForRun($this->runId, SidecarDownloadState::ready($version, $this->runId));

        Log::channel('pipeline')->info('Sidecar downloaded', ['version' => $version]);

        StartSidecarSupervisor::run(parentPid: AppSettings::sidecarParentPid());
    }

    /** Runs once retries are exhausted. A checksum failure has already recorded itself. */
    public function failed(?Throwable $e): void
    {
        $state = SidecarDownloadStore::read();

        if ($state->runId !== $this->runId || $state->status !== SidecarDownloadState::DOWNLOADING) {
            return;
        }

        SidecarDownloadStore::write(SidecarDownloadState::failed($state->version, $this->runId, SidecarDownloadState::ERROR_NETWORK));

        Log::channel('pipeline')->warning('Sidecar download failed', ['error' => $e?->getMessage()]);
    }

    private function download(string $version, string $partial): void
    {
        $lastWrite = 0.0;
        $options = self::requestOptions();

        $response = Http::withOptions([
            'progress' => function ($total, $done) use (&$lastWrite, $version) {
                $now = microtime(true);

                if ($now - $lastWrite < 1.0) {
                    return;
                }

                $lastWrite = $now;

                SidecarDownloadStore::writeForRun(
                    $this->runId,
                    SidecarDownloadState::downloading($version, $this->runId, (int) $done, $total > 0 ? (int) $total : null),
                );
            },
        ])
            ->timeout($options['timeout'])
            ->connectTimeout($options['connect_timeout'])
            ->sink($partial)
            ->get(sprintf((string) config('sidecar.url'), $version));

        if (! $response->successful()) {
            throw new RuntimeException("Helper download returned HTTP {$response->status()}");
        }
    }

    private function failChecksum(string $version, string $detail): void
    {
        SidecarDownloadStore::writeForRun($this->runId, SidecarDownloadState::failed($version, $this->runId, SidecarDownloadState::ERROR_CHECKSUM));

        Log::channel('pipeline')->error('Sidecar checksum mismatch', ['version' => $version, 'detail' => $detail]);

        $this->fail(new RuntimeException('Helper checksum mismatch'));
    }

    /**
     * Defender opens a new exe to scan it, and Windows refuses a rename
     * while it does. A few short waits usually outlast the scan.
     */
    private function moveIntoPlace(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }

            if ($attempt < 5) {
                usleep(500_000);
            }
        }

        return false;
    }
}
