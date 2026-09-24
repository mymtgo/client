<?php

use App\Actions\Tray\CreateTrayMenuBar;
use App\Events\TrayOpenRequested;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('gives the tray "Open" item an event so it works while the main window is hidden', function () {
    if (PHP_OS_FAMILY === 'Linux') {
        $this->markTestSkipped('Tray menu bar is not created on Linux.');
    }

    Http::fake();

    CreateTrayMenuBar::run();

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'menu-bar/create')) {
            return false;
        }

        $openItem = collect($request['contextMenu'])
            ->first(fn (array $item) => ($item['label'] ?? null) === 'Open mymtgo');

        return $openItem !== null
            && $openItem['type'] === 'normal'
            && ($openItem['event'] ?? null) === TrayOpenRequested::class
            && ! array_key_exists('url', $openItem);
    });
});
