<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised by Electron when the tray context menu's "Open" item is clicked.
 *
 * The item is a plain label with this class as its event rather than a
 * Menu::link: link items only navigate the currently focused window, and
 * with the main window hidden to the tray there is none, so the click was
 * silently dropped.
 *
 * @param  array<string, mixed>  $item
 * @param  array<string, mixed>  $combo
 */
class TrayOpenRequested
{
    use Dispatchable;

    public function __construct(public array $item = [], public array $combo = []) {}
}
