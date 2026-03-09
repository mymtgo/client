<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Dialog;

class BrowseFolderController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $defaultPath = $request->query('default');

        $dialog = Dialog::new()
            ->title('Select folder')
            ->folders();

        if ($defaultPath && is_dir($defaultPath)) {
            $dialog->defaultPath($defaultPath);
        }

        $selected = $dialog->open();

        if (! $selected) {
            return response()->json(['path' => null]);
        }

        $path = is_array($selected) ? $selected[0] : $selected;

        return response()->json(['path' => $path]);
    }
}
