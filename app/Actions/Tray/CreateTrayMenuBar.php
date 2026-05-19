<?php

namespace App\Actions\Tray;

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
            ->tooltip('mymtgo')
            ->onlyShowContextMenu(true)
            ->showDockIcon()
            ->withContextMenu(
                Menu::make(
                    Menu::link(url('/'), 'Open mymtgo'),
                    Menu::separator(),
                    Menu::quit(),
                )
            );
    }

    private static function iconPath(): ?string
    {
        $candidate = match (PHP_OS_FAMILY) {
            'Windows' => resource_path('icons/tray.ico'),
            'Darwin' => resource_path('icons/trayTemplate@2x.png'),
            default => null,
        };

        return ($candidate !== null && is_file($candidate)) ? $candidate : null;
    }
}
