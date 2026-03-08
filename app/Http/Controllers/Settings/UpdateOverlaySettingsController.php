<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class UpdateOverlaySettingsController extends Controller
{
    private const ALLOWED_FONTS = [
        'Segoe UI',
        'Arial',
        'Consolas',
        'Cascadia Code',
        'Tahoma',
        'Verdana',
        'Trebuchet MS',
        'Calibri',
    ];

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'overlay_enabled' => 'required|boolean',
            'overlay_always_show' => 'required|boolean',
            'overlay_font' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_FONTS)],
            'overlay_text_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'overlay_bg_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        Settings::set('overlay_enabled', $validated['overlay_enabled'] ? 1 : 0);
        Settings::set('overlay_always_show', $validated['overlay_always_show'] ? 1 : 0);
        Settings::set('overlay_font', $validated['overlay_font']);
        Settings::set('overlay_text_color', $validated['overlay_text_color']);
        Settings::set('overlay_bg_color', $validated['overlay_bg_color']);

        return back();
    }
}
