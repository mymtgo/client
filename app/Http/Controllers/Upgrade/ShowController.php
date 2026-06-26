<?php

namespace App\Http\Controllers\Upgrade;

use App\Http\Controllers\Controller;
use App\Models\SchemaUpgrade;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    public function __invoke(): Response
    {
        $pendingUpgrade = SchemaUpgrade::query()
            ->whereNotIn('status', ['complete'])
            ->latest()
            ->first();

        return Inertia::render('upgrade/Show', [
            'pendingUpgrade' => $pendingUpgrade ? [
                'id' => $pendingUpgrade->id,
                'status' => $pendingUpgrade->status,
                'stage' => $pendingUpgrade->stage,
                'progress' => $pendingUpgrade->progress,
                'total' => $pendingUpgrade->total,
                'error' => $pendingUpgrade->error,
            ] : null,
        ]);
    }
}
