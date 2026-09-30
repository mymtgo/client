<?php

namespace App\Jobs;

use App\Actions\Settings\ApplyCardImagesPath;
use App\Facades\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Moves the local card image cache to another folder.
 *
 * Runs on `card_downloads`, the queue every image write goes through. That
 * queue has one worker, so no download can land in the old folder while the
 * move is copying. The setting only switches once every file has been copied,
 * so a failure part way leaves images working from where they were.
 */
class MoveCardImages implements ShouldQueue
{
    use Queueable;

    /**
     * Images always live in a folder of their own inside the one the player
     * picks. Clearing local images deletes every file on the disk, which must
     * never reach whatever else sits in a folder like `D:\`.
     */
    public const SUBFOLDER = 'mymtgo-card-images';

    public const MOVING_KEY = 'card_images.moving';

    public const ERROR_KEY = 'card_images.move_error';

    public int $tries = 1;

    /**
     * Copying thousands of images to another drive outlasts the downloads
     * worker's 60 second default.
     */
    public int $timeout = 3600;

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $this->onQueue('card_downloads');
    }

    public function handle(): void
    {
        $created = [];

        try {
            File::ensureDirectoryExists($this->to);

            $sources = $this->copyAll($created);

            AppSettings::setCardImagesPath($this->to === ApplyCardImagesPath::defaultRoot() ? null : $this->to);
            ApplyCardImagesPath::run();

            File::delete($sources);
            $this->removeOldFolder();

            Cache::forget(self::ERROR_KEY);
        } catch (\Throwable $e) {
            File::delete($created);

            Log::warning('MoveCardImages: move failed, images stay in the old folder', [
                'from' => $this->from,
                'to' => $this->to,
                'error' => $e->getMessage(),
            ]);

            Cache::forever(self::ERROR_KEY, 'Moving card images failed: '.$e->getMessage());
        } finally {
            Cache::forget(self::MOVING_KEY);
        }
    }

    /**
     * Copy every image into the new folder, skipping ones an earlier run
     * already copied. Returns the source files that now exist at the target.
     *
     * @param  array<int, string>  $created  target files this run wrote, for cleanup on failure
     * @return array<int, string>
     */
    private function copyAll(array &$created): array
    {
        if (! is_dir($this->from)) {
            return [];
        }

        $sources = [];

        foreach (File::allFiles($this->from) as $file) {
            $target = $this->to.DIRECTORY_SEPARATOR.$file->getRelativePathname();

            if (! (is_file($target) && filesize($target) === $file->getSize())) {
                File::ensureDirectoryExists(dirname($target));

                if (! @copy($file->getPathname(), $target)) {
                    throw new \RuntimeException("could not copy {$file->getRelativePathname()}");
                }

                $created[] = $target;
            }

            $sources[] = $file->getPathname();
        }

        return $sources;
    }

    /**
     * A custom folder is ours to remove once empty. The default folder stays:
     * it lives in the app's own storage.
     */
    private function removeOldFolder(): void
    {
        if ($this->from === ApplyCardImagesPath::defaultRoot() || ! is_dir($this->from)) {
            return;
        }

        if (File::allFiles($this->from) === []) {
            File::deleteDirectory($this->from);
        }
    }
}
