<?php

namespace App\Actions\Support;

use App\Actions\Logs\FindMtgoLogPath;
use App\Exceptions\SupportBundleEmptyException;
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

        $zip->close();

        return $zipPath;
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
