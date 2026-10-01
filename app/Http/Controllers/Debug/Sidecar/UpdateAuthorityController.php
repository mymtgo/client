<?php

namespace App\Http\Controllers\Debug\Sidecar;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateAuthorityController extends Controller
{
    /**
     * Lets a player hand one field back to the logs (or to the helper) when
     * the helper gets it wrong on their machine. Only differences from the
     * default are stored, so a later change to a default still reaches
     * anyone who has not overridden that field.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in(SidecarAuthorityFlags::FIELDS)],
            'enabled' => ['required', 'boolean'],
        ]);

        $field = $validated['field'];
        $enabled = (bool) $validated['enabled'];
        $overrides = AppSettings::sidecarAuthority();

        if ($enabled === SidecarAuthorityFlags::DEFAULTS[$field]) {
            unset($overrides[$field]);
        } else {
            $overrides[$field] = $enabled;
        }

        AppSettings::setSidecarAuthority($overrides);

        return back();
    }
}
