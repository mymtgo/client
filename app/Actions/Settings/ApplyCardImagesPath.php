<?php

namespace App\Actions\Settings;

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Storage;

class ApplyCardImagesPath
{
    /**
     * Point the `cards` disk at the folder chosen in settings.
     *
     * Only the disk root moves. Its `url` stays `/media/cards`, and the paths
     * stored on cards are relative to the root, so every image URL keeps
     * working after a move. A disk already resolved for the current root is
     * left untouched, so this is cheap to call before every queued job.
     *
     * A custom folder that has gone (drive unplugged, letter changed) falls
     * back to the default root. Laravel builds local disks eagerly, so a root
     * it cannot create would throw on every image lookup and take pages down
     * with it; broken images are the lesser failure.
     */
    public static function run(): void
    {
        $root = self::available() ? self::root() : self::defaultRoot();

        if (config('filesystems.disks.cards.root') === $root) {
            return;
        }

        config(['filesystems.disks.cards.root' => $root]);
        Storage::forgetDisk('cards');
    }

    public static function root(): string
    {
        return AppSettings::cardImagesPath() ?? self::defaultRoot();
    }

    /**
     * Whether the chosen folder can be used. The default always can: the disk
     * creates it on demand inside the app's own storage.
     */
    public static function available(): bool
    {
        $custom = AppSettings::cardImagesPath();

        return $custom === null || is_dir($custom);
    }

    public static function defaultRoot(): string
    {
        return config('filesystems.disks.cards.default_root');
    }
}
