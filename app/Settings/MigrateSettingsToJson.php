<?php

namespace App\Settings;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Settings;

class MigrateSettingsToJson
{
    /**
     * Map of NativePHP Settings key to its default value.
     * Null defaults are intentional — e.g. device_id must stay null so
     * MtgoManager::runInitialSetup() detects an unregistered device.
     *
     * @var array<string, mixed>
     */
    private const KEYS = [
        'log_path' => '',
        'log_data_path' => '',
        'share_stats' => true,
        'watcher_active' => true,
        'hide_phantom_leagues' => false,
        'debug_mode' => false,
        'league_window' => false,
        'opponent_window' => false,
        'deck_window' => false,
        'local_images' => false,
        'decks_grouped_by_archetype' => false,
        'system_tz' => 'UTC',
        'device_id' => null,
        'api_key' => null,
        'api_key_expires_at' => null,
    ];

    /**
     * Read every known key from NativePHP Settings and write them to settings.json.
     * If every read fails (e.g. Electron is unavailable), the file is not created
     * so the migration can be retried on the next boot.
     */
    public function run(): void
    {
        $data = [];
        $anySuccess = false;

        foreach (self::KEYS as $key => $default) {
            try {
                $value = Settings::get($key);
                $data[$key] = $value ?? $default;
                $anySuccess = true;
            } catch (\Throwable $e) {
                Log::warning("MigrateSettingsToJson: failed to read {$key}", [
                    'error' => $e->getMessage(),
                ]);
                $data[$key] = $default;
            }
        }

        if (! $anySuccess) {
            Log::error('MigrateSettingsToJson: all reads failed, deferring migration');

            return;
        }

        Storage::disk()->put(
            'settings.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
