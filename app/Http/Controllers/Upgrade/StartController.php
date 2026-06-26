<?php

namespace App\Http\Controllers\Upgrade;

use App\Http\Controllers\Controller;
use App\Jobs\RunSchemaUpgradeJob;
use App\Models\SchemaUpgrade;
use Illuminate\Http\JsonResponse;

class StartController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $existing = SchemaUpgrade::whereNotIn('status', ['complete', 'failed'])->latest()->first();

        if ($existing) {
            return response()->json(['upgrade_id' => $existing->id]);
        }

        $upgrade = SchemaUpgrade::create(['status' => 'pending']);

        RunSchemaUpgradeJob::dispatch($upgrade->id);

        return response()->json(['upgrade_id' => $upgrade->id]);
    }
}
