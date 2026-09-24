<?php

namespace App\Http\Controllers\Debug\Sidecar;

use App\Actions\Sidecar\BuildSidecarAgreementSummary;
use App\Actions\Sidecar\ReadSidecarStatus;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\GameEvent;
use App\Models\GameFieldDiff;
use App\Sidecar\SidecarAuthorityFlags;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarTables;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        // Status and settings come from settings.json and the status file,
        // so they are still worth showing on an install whose version-gated
        // migration has not created the sidecar tables yet. The table-backed
        // panels come back empty rather than throwing.
        $ready = SidecarTables::ready();

        return Inertia::render('debug/Sidecar', [
            'status' => ReadSidecarStatus::run()?->toArray(),
            'download' => SidecarDownloadStore::read()->toArray(),
            'settings' => [
                'enabled' => AppSettings::sidecarEnabled(),
                'available' => AppSettings::sidecarAvailable(),
                'tripped' => AppSettings::sidecarTripped(),
                'authority' => SidecarAuthorityFlags::current(),
            ],
            'summary' => BuildSidecarAgreementSummary::run(),
            'recentEvents' => $ready ? GameEvent::query()
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'seq', 'session', 'ts', 'type', 'game_mtgo_id', 'match_mtgo_id', 'verified', 'data']) : [],
            'diffs' => $ready ? GameFieldDiff::query()
                ->with(['match:id,mtgo_id', 'game:id,mtgo_id'])
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get() : [],
        ]);
    }
}
