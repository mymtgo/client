<?php

namespace App\Sidecar;

/**
 * Detects whether the .NET Desktop Runtime the helper exe needs is installed.
 *
 * The helper is published framework-dependent (about 10 MB) rather than
 * self-contained (about 150 MB), so the runtime is the user's to install.
 * MTGOSDK pulls in WPF, which is why the Desktop shared framework is the one
 * that matters, not Microsoft.NETCore.App on its own. The exe is built with
 * RollForward=Major, so any major at or above the required one satisfies it.
 *
 * Detection is a directory scan of the shared-framework folder, the same
 * layout `dotnet --list-runtimes` reads, without spawning a process.
 */
class DotnetRuntime
{
    public const REQUIRED_MAJOR = 10;

    public const DESKTOP_FRAMEWORK = 'Microsoft.WindowsDesktop.App';

    /**
     * Exit code the .NET apphost returns when no suitable runtime is found
     * (0x80008096, "You must install or update .NET to run this application").
     * Electron may report it as the signed or the unsigned 32-bit value.
     */
    public const MISSING_RUNTIME_EXIT_CODE = 0x80008096;

    public const DOWNLOAD_URL = 'https://dotnet.microsoft.com/download/dotnet/'.self::REQUIRED_MAJOR.'.0';

    /**
     * @param  list<string>|null  $roots  dotnet install roots to scan; null uses the Windows defaults
     */
    public static function desktopInstalled(?array $roots = null): bool
    {
        foreach ($roots ?? self::defaultRoots() as $root) {
            $dir = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR.self::DESKTOP_FRAMEWORK;

            if (! is_dir($dir)) {
                continue;
            }

            foreach (scandir($dir) ?: [] as $entry) {
                if (! is_dir($dir.DIRECTORY_SEPARATOR.$entry)) {
                    continue;
                }

                // Release versions only: "10.0.12". Previews carry a suffix
                // and the exe's roll-forward policy does not pick them up.
                if (preg_match('/^(\d+)\.\d+\.\d+$/', $entry, $m) === 1 && (int) $m[1] >= self::REQUIRED_MAJOR) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function isMissingRuntimeExitCode(int $code): bool
    {
        return ($code & 0xFFFFFFFF) === self::MISSING_RUNTIME_EXIT_CODE;
    }

    /** @return list<string> */
    private static function defaultRoots(): array
    {
        $roots = [];

        foreach (['DOTNET_ROOT', 'ProgramFiles', 'ProgramW6432'] as $var) {
            $value = getenv($var);

            if (is_string($value) && $value !== '') {
                $roots[] = $var === 'DOTNET_ROOT' ? $value : $value.DIRECTORY_SEPARATOR.'dotnet';
            }
        }

        if ($roots === []) {
            $roots[] = 'C:\\Program Files\\dotnet';
        }

        return array_values(array_unique($roots));
    }
}
