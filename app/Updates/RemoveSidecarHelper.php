<?php

namespace App\Updates;

use App\Facades\AppSettings;
use Illuminate\Support\Facades\File;

class RemoveSidecarHelper extends AppUpdate
{
    /** Where earlier versions downloaded the helper exe and its download.json, relative to storage_path(). */
    public const BIN_DIRECTORY = 'app/sidecar-bin';

    /** Where the helper wrote its files unless the player chose another folder, relative to storage_path(). */
    public const DEFAULT_DIRECTORY = 'app/sidecar';

    /** What the helper wrote in its directory: event files, status (and its .tmp), current and rolled logs, cache (and its .tmp). */
    public const HELPER_FILE_PATTERNS = ['events-*.ndjson', 'status.json*', 'sidecar*.log', 'known-good-cache.json*'];

    public const SETTINGS_KEYS = [
        'sidecar_directory',
        'sidecar_authority',
        'sidecar_enabled',
        'sidecar_available',
        'sidecar_notice_seen',
        'sidecar_parent_pid',
        'sidecar_tripped',
        'sidecar_crashes',
    ];

    /**
     * Remove the MTGO helper from the player's machine. The helper left the
     * app in 0.45.1; this clears what earlier versions downloaded and wrote.
     *
     * Electron tree-kills child processes on quit and on update install, so
     * the helper is not running by the time this boots. If its exe is still
     * locked anyway, this throws: RunAppUpdates logs it and retries on the
     * next boot. The settings keys go last, so a retry still knows a custom
     * helper directory.
     *
     * Only files the helper wrote are deleted from its directory, because
     * the player could point it at any folder. MTGOSDK's own extraction
     * folder under %LOCALAPPDATA%\MTGOSDK is left alone: other MTGOSDK
     * tools can share it.
     */
    public function run(): void
    {
        $bin = storage_path(self::BIN_DIRECTORY);

        if (is_dir($bin)) {
            $this->deleteBinDirectory($bin);
        }

        if (file_exists($bin)) {
            throw new \RuntimeException("RemoveSidecarHelper: {$bin} could not be deleted, retrying next boot.");
        }

        $default = rtrim(storage_path(self::DEFAULT_DIRECTORY), '/\\');
        $directory = rtrim((string) AppSettings::get('sidecar_directory', $default), '/\\');

        if (is_dir($directory)) {
            $this->deleteHelperFiles($directory);

            if ($directory === $default && (glob($directory.DIRECTORY_SEPARATOR.'*') ?: []) === []) {
                @rmdir($directory);
            }
        }

        foreach (self::SETTINGS_KEYS as $key) {
            AppSettings::forget($key);
        }
    }

    protected function deleteBinDirectory(string $bin): void
    {
        File::deleteDirectory($bin);
    }

    private function deleteHelperFiles(string $directory): void
    {
        foreach (self::HELPER_FILE_PATTERNS as $pattern) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.$pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
