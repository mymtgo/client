<?php

namespace App\Updates;

use App\Facades\AppSettings;
use Illuminate\Support\Facades\File;

class RemoveSidecarHelper extends AppUpdate
{
    /** Where earlier versions downloaded the helper exe and its download.json, relative to storage_path(). */
    public const BIN_DIRECTORY = 'app/sidecar-bin';

    /** Where the helper wrote its events, status, logs and cache, relative to storage_path(). */
    public const DATA_DIRECTORY = 'app/sidecar';

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
     * Both directories are app-owned: no version ever let the player move
     * the helper's data folder, so they are deleted whole.
     *
     * Electron tree-kills child processes on quit and on update install, so
     * the helper is not running by the time this boots. If a file is still
     * locked anyway, this throws: RunAppUpdates logs it and retries on the
     * next boot. The settings keys go last, so a failed run leaves them.
     *
     * MTGOSDK's own extraction folder under %LOCALAPPDATA%\MTGOSDK is left
     * alone: other MTGOSDK tools can share it.
     */
    public function run(): void
    {
        foreach ([self::BIN_DIRECTORY, self::DATA_DIRECTORY] as $relative) {
            $directory = storage_path($relative);

            if (is_dir($directory)) {
                $this->deleteDirectory($directory);
            }

            if (file_exists($directory)) {
                throw new \RuntimeException("RemoveSidecarHelper: {$directory} could not be deleted, retrying next boot.");
            }
        }

        foreach (self::SETTINGS_KEYS as $key) {
            AppSettings::forget($key);
        }
    }

    protected function deleteDirectory(string $directory): void
    {
        File::deleteDirectory($directory);
    }
}
