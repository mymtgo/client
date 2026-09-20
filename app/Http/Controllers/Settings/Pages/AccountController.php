<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Actions\Sync\BuildDeckSyncList;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Services\Sync\SyncTokens;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __invoke(): Response
    {
        $slots = AppSettings::syncSlots();

        return Inertia::render('settings/Account', [
            'currentPage' => 'account',
            'linked' => app(SyncTokens::class)->linked(),
            'decks' => BuildDeckSyncList::run(),
            'slots' => [
                'limit' => $slots['limit'] ?? null,
                'used' => (int) ($slots['used'] ?? 0),
            ],
        ]);
    }
}
