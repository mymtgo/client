<?php

namespace App\Actions\Settings;

use Illuminate\Support\Facades\Storage;

class MeasureLocalImagesSize
{
    /**
     * Total size of locally cached card images, formatted for display.
     */
    public static function run(): string
    {
        $disk = Storage::disk('cards');
        $bytes = array_sum(array_map(
            fn (string $file) => $disk->exists($file) ? $disk->size($file) : 0,
            $disk->allFiles()
        ));

        return match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, 1).' GB',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 0).' KB',
            default => $bytes.' B',
        };
    }
}
