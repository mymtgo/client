<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateTrustSettingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        AppSettings::setCardStatsTrust($validated['value']);

        return response()->json(['value' => AppSettings::cardStatsTrust()]);
    }
}
