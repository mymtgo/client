<?php

namespace App\Actions\Updates;

use App\Updates\AppUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RunAppUpdates
{
    public static function run(): void
    {
        if (! Schema::hasTable('app_updates')) {
            return;
        }

        $completed = DB::table('app_updates')->pluck('update')->flip();

        foreach (self::discoverUpdates() as $update) {
            $key = $update::class;

            if ($completed->has($key)) {
                continue;
            }

            try {
                $update->run();

                DB::table('app_updates')->insert([
                    'update' => $key,
                    'ran_at' => now(),
                ]);

                Log::info("App update completed: {$key}");
            } catch (\Throwable $e) {
                Log::error("App update failed: {$key}", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return array<AppUpdate>
     */
    private static function discoverUpdates(): array
    {
        $path = app_path('Updates');
        $updates = [];

        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\Updates\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class) || ! is_subclass_of($class, AppUpdate::class)) {
                continue;
            }

            $updates[] = new $class;
        }

        return $updates;
    }
}
