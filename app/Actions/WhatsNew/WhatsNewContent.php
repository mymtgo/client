<?php

namespace App\Actions\WhatsNew;

use Illuminate\Support\Str;

/**
 * The single what's-new markdown file, replaced by hand each release.
 */
class WhatsNewContent
{
    private static ?string $pathOverride = null;

    public static function path(): string
    {
        return self::$pathOverride ?? resource_path('content/whats-new.md');
    }

    /**
     * Test seam: point at a temporary file, or null to restore the default.
     */
    public static function usePath(?string $path): void
    {
        self::$pathOverride = $path;
    }

    public static function exists(): bool
    {
        return self::markdown() !== null;
    }

    public static function html(): ?string
    {
        $markdown = self::markdown();

        return $markdown === null ? null : Str::markdown($markdown);
    }

    private static function markdown(): ?string
    {
        $path = self::path();

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false || trim($contents) === '' ? null : $contents;
    }
}
