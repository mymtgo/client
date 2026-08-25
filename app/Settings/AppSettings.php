<?php

namespace App\Settings;

use Illuminate\Support\Carbon;
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

                    // A corrupt file means whatever offline_mode was set to is
                    // unrecoverable. Rebuild fail-closed so a lost privacy opt-in
                    // never silently reverts to online — the explicit $key/$value
                    // being written below still wins if this write IS offline_mode.
                    // Scoped to this branch only: an empty, newly-created file (a
                    // genuine fresh install's very first write, nothing to
                    // quarantine above) must NOT trip this — that would bake
                    // offline_mode: true into every new install's first write.
                    $data = ['offline_mode' => true];
                } else {
                    $data = [];
                }
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

    /**
     * Whether the app should make no community API calls.
     *
     * Fails CLOSED: if settings.json cannot be read right now (locked,
     * unreadable, corrupt), the user is treated as offline rather than
     * silently falling back to the "online" default. This method reads the
     * file itself — via readResult() — rather than going through get(),
     * because get()/read() collapse every failure into the same empty array
     * as "no settings yet", which is exactly the ambiguity that must not
     * leak into a privacy switch. No other setting's read-failure behaviour
     * changes; this is the only caller of readResult().
     *
     * The try/catch matters as much as the ok flag: a hard fopen()/flock()
     * failure surfaces here as a PHP warning that this app's error handler
     * (bootstrap/app.php) promotes to an ErrorException — e.g. the same
     * "AV/indexer still holds the file" condition already seen with Blade
     * cache renames — rather than returning false cleanly. Letting that
     * propagate would fail OPEN by crashing whatever caller assumed
     * isOffline() can't throw; catching it and returning true keeps the
     * fail-closed guarantee real under the exact conditions that motivate it.
     */
    public function isOffline(): bool
    {
        try {
            $result = $this->readResult();
        } catch (\Throwable) {
            return true;
        }

        if (! $result['ok']) {
            return true;
        }

        return array_key_exists('offline_mode', $result['data'])
            ? (bool) $result['data']['offline_mode']
            : false;
    }

    public function setOffline(bool $value): void
    {
        $this->set('offline_mode', $value);
    }

    /**
     * When offline mode may be switched back on, if a cooldown is running.
     *
     * Set when a user leaves offline mode, so that grabbing a fresh archetype
     * catalogue and immediately going private again costs a day online rather
     * than two clicks. Deliberately NOT obfuscated: it lives in plain
     * settings.json and a determined user can delete it. This is friction and
     * an honesty signal, not enforcement — real enforcement would have to live
     * on the API, which knows who has actually submitted anything.
     */
    public function offlineModeLockedUntil(): ?string
    {
        $value = $this->get('offline_mode_locked_until');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setOfflineModeLockedUntil(?string $isoTimestamp): void
    {
        $this->set('offline_mode_locked_until', $isoTimestamp);
    }

    /**
     * Unlike isOffline(), this deliberately fails OPEN: an unreadable or
     * unparseable timestamp means "not locked". Failing closed here would trap
     * a user OUT of privacy on a bad read, which is the opposite of what the
     * offline-mode guarantee is for.
     */
    public function isOfflineModeLocked(): bool
    {
        $until = $this->offlineModeLockedUntil();

        if ($until === null) {
            return false;
        }

        try {
            return Carbon::parse($until)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether offline_mode has genuinely never been recorded, as opposed to
     * a read failure that would look identical to "unset" through a plain
     * get(). `MtgoManager::runInitialSetup()` runs on every boot with no
     * first-run gate, and seeds offline_mode: false the first time it finds
     * the key unset — a boot-time read failure must not be mistaken for a
     * genuine fresh install there, or a transient hiccup permanently
     * overwrites whatever the user actually chose. Any doubt (a failed read,
     * or an exception) returns false — "don't know" must never be treated
     * as "never set" for this flag, so this boot simply skips reseeding it
     * rather than guessing.
     */
    public function offlineModeNeverSet(): bool
    {
        try {
            $result = $this->readResult();
        } catch (\Throwable) {
            return false;
        }

        return $result['ok'] && ! array_key_exists('offline_mode', $result['data']);
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

    public function donationPromptSeen(): bool
    {
        return (bool) $this->get('donation_prompt_seen', false);
    }

    public function setDonationPromptSeen(bool $value): void
    {
        $this->set('donation_prompt_seen', $value);
    }

    /**
     * Whether the game overlay window is enabled.
     *
     * Falls back to the two windows this one replaced so an upgrade does not
     * silently drop an overlay the player already had open. Settings live in a
     * JSON file, so there is no migration to carry this — the fallback is the
     * migration.
     */
    /**
     * The running NativePHP web server's base URL, captured at app boot.
     * Background processes (queue workers, the watch daemon) can't derive
     * this themselves — their route() falls back to APP_URL, which points
     * nowhere — so any window opened outside an HTTP request must build
     * its URL from this value.
     */
    public function appServerUrl(): ?string
    {
        return $this->get('app_server_url');
    }

    public function setAppServerUrl(string $url): void
    {
        $this->set('app_server_url', rtrim($url, '/'));
    }

    public function showGameOverlay(): bool
    {
        $explicit = $this->get('game_overlay');

        if ($explicit !== null) {
            return (bool) $explicit;
        }

        return (bool) $this->get('deck_window', false)
            || (bool) $this->get('opponent_window', false);
    }

    public function setShowGameOverlay(bool $value): void
    {
        $this->set('game_overlay', $value);
    }

    public function overlayShowOpponent(): bool
    {
        return (bool) $this->get('overlay_show_opponent', true);
    }

    public function setOverlayShowOpponent(bool $value): void
    {
        $this->set('overlay_show_opponent', $value);
    }

    public function overlayShowDrawOdds(): bool
    {
        return (bool) $this->get('overlay_show_draw_odds', true);
    }

    public function setOverlayShowDrawOdds(bool $value): void
    {
        $this->set('overlay_show_draw_odds', $value);
    }

    public function overlayShowReveals(): bool
    {
        return (bool) $this->get('overlay_show_reveals', true);
    }

    public function setOverlayShowReveals(bool $value): void
    {
        $this->set('overlay_show_reveals', $value);
    }

    public function overlayShowSideboard(): bool
    {
        return (bool) $this->get('overlay_show_sideboard', true);
    }

    public function setOverlayShowSideboard(bool $value): void
    {
        $this->set('overlay_show_sideboard', $value);
    }

    public function downloadImagesLocally(): bool
    {
        return (bool) $this->get('local_images', false);
    }

    public function setDownloadImagesLocally(bool $value): void
    {
        $this->set('local_images', $value);
    }

    public function overlayBackgroundPath(): ?string
    {
        $value = $this->get('overlay_background_path');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setOverlayBackgroundPath(?string $path): void
    {
        if ($path === null || $path === '') {
            $this->forget('overlay_background_path');

            return;
        }

        $this->set('overlay_background_path', $path);
    }

    public function decksGroupedByArchetype(): bool
    {
        return (bool) $this->get('decks_grouped_by_archetype', false);
    }

    public function setDecksGroupedByArchetype(bool $value): void
    {
        $this->set('decks_grouped_by_archetype', $value);
    }

    public function hideArchivedDecks(): bool
    {
        return (bool) $this->get('hide_archived_decks', true);
    }

    public function setHideArchivedDecks(bool $value): void
    {
        $this->set('hide_archived_decks', $value);
    }

    public function cardStatsTrust(): int
    {
        $value = $this->get('card_stats_trust', 50);
        $int = is_int($value) ? $value : (int) $value;

        if ($int < 0) {
            return 0;
        }
        if ($int > 100000) {
            return 100000;
        }

        return $int;
    }

    public function setCardStatsTrust(int $value): void
    {
        $clamped = max(0, min(100000, $value));
        $this->set('card_stats_trust', $clamped);
    }

    public function autostartEnabled(): bool
    {
        return (bool) $this->get('autostart_enabled', false);
    }

    public function setAutostartEnabled(bool $value): void
    {
        $this->set('autostart_enabled', $value);
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

    public function archetypesLastRefreshedAt(): ?string
    {
        $value = $this->get('archetypes_last_refreshed_at');

        return is_string($value) ? $value : null;
    }

    public function setArchetypesLastRefreshedAt(string $value): void
    {
        $this->set('archetypes_last_refreshed_at', $value);
    }

    public function archetypesRefreshInProgress(): bool
    {
        return (bool) $this->get('archetypes_refresh_in_progress', false);
    }

    public function setArchetypesRefreshInProgress(bool $value): void
    {
        $this->set('archetypes_refresh_in_progress', $value);
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
        return $this->readResult()['data'];
    }

    /**
     * Read settings.json, distinguishing "could not read right now" from
     * "read fine, key/file just isn't there yet".
     *
     * A missing file is `ok: true` with empty data — that is the normal,
     * expected state for a fresh install and every setting's documented
     * default already accounts for it. `fopen`/`flock` failing on an
     * existing file, an empty/false raw read, and invalid JSON are all
     * `ok: false` — the file exists but its current content cannot be
     * trusted, which is a materially different situation for a caller that
     * cannot afford to assume the least-private default (see isOffline()).
     *
     * This is the only place that draws that distinction; read() collapses
     * it back to an empty array for every other caller, so no other
     * setting's read-failure behaviour changes.
     *
     * @return array{ok: bool, data: array<string, mixed>}
     */
    private function readResult(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return ['ok' => true, 'data' => []];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['ok' => false, 'data' => []];
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return ['ok' => false, 'data' => []];
            }

            $raw = stream_get_contents($handle);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        if ($raw === false || $raw === '') {
            return ['ok' => false, 'data' => []];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return ['ok' => true, 'data' => $decoded];
        }

        $this->quarantine($path, $raw);

        return ['ok' => false, 'data' => []];
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
