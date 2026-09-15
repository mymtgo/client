<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\ArchetypeDeck;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('settings/Privacy', [
            'currentPage' => 'privacy',
            // EstimateArchetypeLocally scores against ArchetypeDeck decklists, not
            // Archetype rows: a fresh install has ~877 Archetype rows (names only)
            // and zero decklists, so probing Archetype alone would leave this
            // warning permanently suppressed while every match went unclassified.
            'hasArchetypeCatalog' => ArchetypeDeck::query()->exists(),
            // Drives the offline toggle's disabled state. A page prop rather than
            // a shared one: only this page needs it.
            'offlineModeLockedUntil' => AppSettings::offlineModeLockedUntil(),
            'pendingMatches' => MtgoMatch::submittable()
                ->latest('started_at')
                ->get(['id', 'format', 'outcome', 'started_at']),
        ]);
    }
}
