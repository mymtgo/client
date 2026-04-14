<?php

namespace App\Actions\Games;

use Native\Desktop\Facades\Window;

class OpenGameReplayWindow
{
    public static function run(int $gameId): void
    {
        $windowId = "game-replay-{$gameId}";

        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === $windowId);

        if ($alreadyOpen) {
            return;
        }

        // Close any other game replay windows
        collect(Window::all())
            ->filter(fn ($w) => str_starts_with($w->getId(), 'game-replay-') && $w->getId() !== $windowId)
            ->each(fn ($w) => Window::close($w->getId()));

        Window::open($windowId)
            ->route('games.show', ['id' => $gameId])
            ->width(1200)
            ->height(800)
            ->minWidth(800)
            ->minHeight(600)
            ->rememberState()
            ->resizable()
            ->movable()
            ->hideMenu()
            ->showDevTools(false)
            ->title('Game Replay');
    }
}
