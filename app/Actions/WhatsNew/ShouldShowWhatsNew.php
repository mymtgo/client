<?php

namespace App\Actions\WhatsNew;

use App\Facades\AppSettings;
use App\Models\MtgoMatch;

/**
 * True when the running build is a newer major or minor than the last
 * what's-new shown. Patch releases never qualify.
 */
class ShouldShowWhatsNew
{
    public static function run(): bool
    {
        if (! WhatsNewContent::exists()) {
            return false;
        }

        $running = (string) config('nativephp.version');
        $seen = AppSettings::whatsNewSeenVersion();

        if ($seen === null) {
            // Fresh install: nothing is "new" yet. Existing users getting
            // this build for the first time have matches and should see it.
            if (! MtgoMatch::query()->exists()) {
                AppSettings::setWhatsNewSeenVersion($running);

                return false;
            }

            $seen = '0.0.0';
        }

        return version_compare(self::majorMinor($running), self::majorMinor($seen), '>');
    }

    private static function majorMinor(string $version): string
    {
        $parts = explode('.', ltrim($version, 'v'));

        return ((int) ($parts[0] ?? 0)).'.'.((int) ($parts[1] ?? 0));
    }
}
