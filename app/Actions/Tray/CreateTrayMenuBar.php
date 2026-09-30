<?php

namespace App\Actions\Tray;

use App\Events\TrayOpenRequested;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;

class CreateTrayMenuBar
{
    public static function run(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return;
        }

        $icon = self::iconPath();

        if ($icon === null) {
            return;
        }

        MenuBar::create()
            ->icon($icon)
            ->tooltip(self::tooltip(null))
            ->onlyShowContextMenu(true)
            ->showDockIcon()
            ->withContextMenu(
                Menu::make(
                    Menu::label('Open mymtgo')->event(TrayOpenRequested::class),
                    Menu::separator(),
                    Menu::quit(),
                )
            );
    }

    public static function tooltip(?string $readyVersion): string
    {
        return $readyVersion === null ? 'mymtgo' : "mymtgo: update ready (v{$readyVersion})";
    }

    public static function iconPath(bool $updateReady = false): ?string
    {
        $suffix = $updateReady ? '-update' : '';

        $candidate = match (PHP_OS_FAMILY) {
            'Windows' => resource_path("icons/tray{$suffix}.ico"),
            'Darwin' => resource_path("icons/trayTemplate{$suffix}@2x.png"),
            default => null,
        };

        return ($candidate !== null && is_file($candidate)) ? $candidate : null;
    }
}
