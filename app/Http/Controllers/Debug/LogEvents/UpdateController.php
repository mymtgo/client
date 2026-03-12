<?php

namespace App\Http\Controllers\Debug\LogEvents;

use App\Http\Controllers\Controller;
use App\Models\LogEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    public function __invoke(Request $request, LogEvent $logEvent): RedirectResponse
    {
        $allowed = [
            'file_path', 'byte_offset_start', 'byte_offset_end',
            'timestamp', 'level', 'category', 'context', 'raw_text',
            'ingested_at', 'processed_at', 'match_token', 'game_id',
            'match_id', 'event_type', 'logged_at',
        ];

        $field = collect($request->only($allowed))->keys()->first();

        if (! $field) {
            return back();
        }

        $rules = [
            'file_path' => 'nullable|string',
            'byte_offset_start' => 'integer|min:0',
            'byte_offset_end' => 'integer|min:0',
            'timestamp' => 'nullable|date',
            'level' => 'nullable|string|max:8',
            'category' => 'nullable|string|max:255',
            'context' => 'nullable|string|max:255',
            'raw_text' => 'nullable|string',
            'ingested_at' => 'nullable|date',
            'processed_at' => 'nullable|date',
            'match_token' => 'nullable|string',
            'game_id' => 'nullable|string',
            'match_id' => 'nullable|string',
            'event_type' => 'nullable|string',
            'logged_at' => 'nullable|date',
        ];

        $request->validate([$field => $rules[$field]]);

        $logEvent->update([$field => $request->input($field)]);

        return back();
    }
}
