<?php

namespace App\Settings;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AppSettings
{
    private const FILENAME = 'settings.json';

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->read();

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * Persist a single key/value pair, holding an exclusive file lock across
     * the full read-modify-write cycle to prevent concurrent writers from
     * clobbering each other.
     *
     * Writes back through the same handle (truncate-in-place) so the inode
     * never changes while the lock is held. This keeps flock() meaningful:
     * every concurrent opener blocks on the same inode and sees the latest
     * content once they acquire the lock.
     */
    public function set(string $key, mixed $value): void
    {
        $path = $this->path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path} for write");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Could not acquire exclusive lock on {$path}");
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = json_decode($raw === false ? '' : $raw, true);
            if (! is_array($data)) {
                if (is_string($raw) && $raw !== '') {
                    // File is non-empty but invalid — quarantine, then re-open fresh.
                    // The current handle's lock is on the now-quarantined inode; a
                    // concurrent writer opening "settings.json" after the quarantine
                    // rename gets a different inode, so we must release + re-open to
                    // ensure our truncate-in-place writes land on the live inode.
                    fclose($handle);
                    $this->quarantine($path, $raw);

                    $handle = fopen($path, 'c+');
                    if ($handle === false) {
                        throw new \RuntimeException("Could not re-open {$path} after quarantine");
                    }
                    if (! flock($handle, LOCK_EX)) {
                        throw new \RuntimeException("Could not acquire exclusive lock on {$path} after quarantine");
                    }
                }
                $data = [];
            }

            $data[$key] = $value;

            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new \RuntimeException('AppSettings: failed to encode settings as JSON');
            }

            rewind($handle);
            if (ftruncate($handle, 0) === false) {
                throw new \RuntimeException("AppSettings: failed to truncate {$path}");
            }

            if (fwrite($handle, $encoded) === false) {
                throw new \RuntimeException("AppSettings: failed to write {$path}");
            }

            fflush($handle);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * Remove a single key from settings.json under an exclusive file lock.
     * No-op if the file does not exist or the key is absent.
     */
    public function forget(string $key): void
    {
        $path = $this->path();

        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path} for write");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Could not acquire exclusive lock on {$path}");
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = json_decode($raw === false ? '' : $raw, true);
            if (! is_array($data)) {
                return;
            }

            if (! array_key_exists($key, $data)) {
                return;
            }

            unset($data[$key]);

            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new \RuntimeException('AppSettings: failed to encode settings as JSON');
            }

            rewind($handle);
            if (ftruncate($handle, 0) === false) {
                throw new \RuntimeException("AppSettings: failed to truncate {$path}");
            }

            if (fwrite($handle, $encoded) === false) {
                throw new \RuntimeException("AppSettings: failed to write {$path}");
            }

            fflush($handle);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    public function logPath(): string
    {
        return (string) $this->get('log_path', '');
    }

    public function setLogPath(string $path): void
    {
        $this->set('log_path', $path);
    }

    public function logDataPath(): string
    {
        return (string) $this->get('log_data_path', '');
    }

    public function setLogDataPath(string $path): void
    {
        $this->set('log_data_path', $path);
    }

    public function shouldTransmitMatches(): bool
    {
        return (bool) $this->get('share_stats', true);
    }

    public function setShouldTransmitMatches(bool $value): void
    {
        $this->set('share_stats', $value);
    }

    public function isWatcherActive(): bool
    {
        return (bool) $this->get('watcher_active', true);
    }

    public function setWatcherActive(bool $value): void
    {
        $this->set('watcher_active', $value);
    }

    public function isDebugMode(): bool
    {
        return (bool) $this->get('debug_mode', false);
    }

    public function setDebugMode(bool $value): void
    {
        $this->set('debug_mode', $value);
    }

    public function showLeagueWindow(): bool
    {
        return (bool) $this->get('league_window', false);
    }

    public function setShowLeagueWindow(bool $value): void
    {
        $this->set('league_window', $value);
    }

    public function showOpponentWindow(): bool
    {
        return (bool) $this->get('opponent_window', false);
    }

    public function setShowOpponentWindow(bool $value): void
    {
        $this->set('opponent_window', $value);
    }

    public function showDeckWindow(): bool
    {
        return (bool) $this->get('deck_window', false);
    }

    public function setShowDeckWindow(bool $value): void
    {
        $this->set('deck_window', $value);
    }

    public function downloadImagesLocally(): bool
    {
        return (bool) $this->get('local_images', false);
    }

    public function setDownloadImagesLocally(bool $value): void
    {
        $this->set('local_images', $value);
    }

    public function decksGroupedByArchetype(): bool
    {
        return (bool) $this->get('decks_grouped_by_archetype', false);
    }

    public function setDecksGroupedByArchetype(bool $value): void
    {
        $this->set('decks_grouped_by_archetype', $value);
    }

    public function systemTimezone(): string
    {
        return (string) $this->get('system_tz', 'UTC');
    }

    public function setSystemTimezone(string $tz): void
    {
        $this->set('system_tz', $tz);
    }

    public function deviceId(): ?string
    {
        $value = $this->get('device_id');

        return is_string($value) ? $value : null;
    }

    public function setDeviceId(string $id): void
    {
        $this->set('device_id', $id);
    }

    public function apiKey(): ?string
    {
        $encrypted = $this->get('api_key');

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setApiKey(?string $key): void
    {
        if ($key === null) {
            $this->set('api_key', null);

            return;
        }

        $this->set('api_key', Crypt::encrypt($key));
    }

    public function apiKeyExpiresAt(): ?string
    {
        $value = $this->get('api_key_expires_at');

        return is_string($value) ? $value : null;
    }

    public function setApiKeyExpiresAt(?string $expiresAt): void
    {
        $this->set('api_key_expires_at', $expiresAt);
    }

    private function path(): string
    {
        return Storage::disk()->path(self::FILENAME);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return [];
            }

            $raw = stream_get_contents($handle);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $this->quarantine($path, $raw);

        return [];
    }

    /**
     * Move a corrupt settings file aside so the app can self-heal on the next write.
     */
    private function quarantine(string $path, string $raw): void
    {
        $target = $path.'.corrupt.'.time();

        // Best-effort rename; if it fails (lock/permission), at least remove the
        // bad file so the app can self-heal on next write.
        if (! @rename($path, $target)) {
            @unlink($path);
        }

        Log::error('settings.json corrupt — quarantined and starting fresh', [
            'target' => basename($target),
            'raw_length' => strlen($raw),
        ]);
    }
}
