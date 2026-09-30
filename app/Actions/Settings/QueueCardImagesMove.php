<?php

namespace App\Actions\Settings;

use App\Jobs\MoveCardImages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class QueueCardImagesMove
{
    /**
     * Validate the target folder and queue the move into it.
     *
     * @throws ValidationException
     */
    public static function run(string $to): void
    {
        $from = ApplyCardImagesPath::root();

        if (Cache::get(MoveCardImages::MOVING_KEY)) {
            self::fail('Card images are already being moved.');
        }

        if (! self::isAbsolute($to)) {
            self::fail('Choose a full folder path.');
        }

        if (self::overlaps($from, $to)) {
            self::fail('Choose a folder outside the current image folder.');
        }

        if (! is_dir($to) && ! @mkdir($to, 0755, true)) {
            self::fail('That folder could not be created.');
        }

        if (! is_writable($to)) {
            self::fail('That folder cannot be written to.');
        }

        Cache::forget(MoveCardImages::ERROR_KEY);
        Cache::forever(MoveCardImages::MOVING_KEY, true);

        MoveCardImages::dispatch($from, $to);
    }

    /**
     * The same folder, or one inside the other, would have the move copy
     * files onto themselves or delete what it just copied.
     */
    private static function overlaps(string $from, string $to): bool
    {
        $from = self::normalise($from);
        $to = self::normalise($to);

        return $from === $to
            || str_starts_with($to, $from.'/')
            || str_starts_with($from, $to.'/');
    }

    /**
     * A POSIX path, a Windows drive path, or a UNC share.
     */
    private static function isAbsolute(string $path): bool
    {
        return preg_match('#^(/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $path) === 1;
    }

    private static function normalise(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    /**
     * @throws ValidationException
     */
    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['cardImagesPath' => $message]);
    }
}
