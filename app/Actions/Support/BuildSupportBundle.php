<?php

namespace App\Actions\Support;

use App\Actions\Logs\FindMtgoLogPath;
use App\Exceptions\SupportBundleEmptyException;
use App\Models\GameFieldDiff;
use App\Sidecar\SidecarPaths;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

class BuildSupportBundle
{
    /**
     * Number of most recent pipeline logs to include in the bundle.
     */
    private const PIPELINE_LOG_LIMIT = 3;

    /**
     * Number of most recent laravel logs to include in the bundle.
     */
    private const LARAVEL_LOG_LIMIT = 3;

    /**
     * Newest sidecar event files to include in the bundle.
     */
    private const SIDECAR_EVENT_FILE_LIMIT = 3;

    public function __construct(
        private readonly ?string $logsDir = null,
    ) {}

    /**
     * Build a zip containing the latest mtgo.log, the laravel.log,
     * and the most recent pipeline logs.
     *
     * @return string Absolute path to the temporary zip file. Caller is responsible for deletion.
     *
     * @throws SupportBundleEmptyException When no log files are available.
     */
    public function __invoke(): string
    {
        $mtgoLog = FindMtgoLogPath::all()->last();
        $laravelLogs = $this->recentLaravelLogs();
        $pipelineLogs = $this->recentPipelineLogs();

        if ($mtgoLog === null && $laravelLogs === [] && $pipelineLogs === []) {
            throw new SupportBundleEmptyException('No log files available to bundle.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mtgo-report-');
        $zipPath = $tmpFile.'.zip';
        unlink($tmpFile);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($mtgoLog !== null) {
            $zip->addFile($mtgoLog, basename($mtgoLog));
        }

        foreach ($laravelLogs as $log) {
            $zip->addFile($log, basename($log));
        }

        foreach ($pipelineLogs as $log) {
            $zip->addFile($log, basename($log));
        }

        $this->addSidecarArtefacts($zip);

        $zip->close();

        return $zipPath;
    }

    /**
     * Add sidecar status, log, recent event files, and a field-diff export
     * to the bundle. The sidecar directory may not exist (log-only builds,
     * or a machine where the sidecar has never run) which is a supported
     * state, not an error.
     */
    private function addSidecarArtefacts(ZipArchive $zip): void
    {
        $dir = SidecarPaths::directory();

        if (is_dir($dir)) {
            foreach (['status.json', 'sidecar.log'] as $name) {
                $path = $dir.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    $zip->addFile($path, 'sidecar/'.$name);
                }
            }

            $events = SidecarPaths::eventFiles();
            foreach (array_slice(array_reverse($events), 0, self::SIDECAR_EVENT_FILE_LIMIT) as $path) {
                $zip->addFile($path, 'sidecar/'.basename($path));
            }
        }

        // Guard against callers whose database has not been migrated (e.g.
        // isolated unit tests exercising this action without RefreshDatabase)
        // rather than throwing out of a support-bundle export.
        $diffs = Schema::hasTable('game_field_diffs')
            ? GameFieldDiff::query()
                ->with(['match:id,mtgo_id', 'game:id,mtgo_id'])
                ->orderBy('id')
                ->get()
                ->map(fn (GameFieldDiff $diff) => [
                    'match_mtgo_id' => (string) $diff->match?->mtgo_id,
                    'game_mtgo_id' => $diff->game ? (string) $diff->game->mtgo_id : null,
                    'field' => $diff->field,
                    'log_value' => $diff->log_value,
                    'sidecar_value' => $diff->sidecar_value,
                    'chosen_source' => $diff->chosen_source,
                    'updated_at' => $diff->updated_at?->toIso8601ZuluString(),
                ])
                ->values()
            : collect();

        $zip->addFromString('sidecar/game_field_diffs.json', json_encode($diffs, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Return up to LARAVEL_LOG_LIMIT most recent laravel log file paths.
     * Matches both `laravel.log` (single channel) and `laravel-YYYY-MM-DD.log` (daily channel).
     *
     * @return array<int, string>
     */
    private function recentLaravelLogs(): array
    {
        $candidates = glob($this->logsDirectory().'/laravel*.log') ?: [];

        if ($candidates === []) {
            return [];
        }

        rsort($candidates);

        return array_slice($candidates, 0, self::LARAVEL_LOG_LIMIT);
    }

    /**
     * Return up to PIPELINE_LOG_LIMIT most recent pipeline log file paths,
     * sorted newest-first by filename (filenames are date-padded).
     *
     * @return array<int, string>
     */
    private function recentPipelineLogs(): array
    {
        $candidates = glob($this->logsDirectory().'/pipeline-*.log') ?: [];

        if ($candidates === []) {
            return [];
        }

        rsort($candidates);

        return array_slice($candidates, 0, self::PIPELINE_LOG_LIMIT);
    }

    private function logsDirectory(): string
    {
        return $this->logsDir ?? storage_path('logs');
    }
}
