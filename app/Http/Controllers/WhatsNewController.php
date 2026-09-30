<?php

namespace App\Http\Controllers;

use App\Actions\WhatsNew\WhatsNewContent;
use Inertia\Inertia;
use Inertia\Response;

class WhatsNewController extends Controller
{
    public function __invoke(): Response
    {
        $html = WhatsNewContent::html();

        abort_if($html === null, 404);

        return Inertia::render('whats-new/Show', [
            'html' => $html,
        ]);
    }
}
