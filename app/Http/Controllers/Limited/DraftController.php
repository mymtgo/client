<?php

namespace App\Http\Controllers\Limited;

use App\Actions\Limited\Analytics\ComputeCrossDraftCardStats;
use App\Actions\Limited\Read\BuildDraftReview;
use App\Actions\Limited\Read\GetLimitedEventSharedProps;
use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DraftController extends Controller
{
    /**
     * Pick-by-pick draft review for a limited league. The whole draft ships in
     * one payload so pick navigation never touches the server. `review` is a
     * closure so a partial reload for a deferred prop does not rebuild it.
     */
    public function __invoke(Request $request, League $league): Response
    {
        abort_unless($league->kind->isLimited(), 404);

        $draft = $league->draft;
        $ordinals = $draft
            ? $draft->picks()->pluck('ordinal')->map(fn ($ordinal) => (int) $ordinal)
            : collect();

        $selected = $request->integer('pick');
        if (! $ordinals->contains($selected)) {
            $selected = $ordinals->first();
        }

        return Inertia::render('limited/Draft', [
            'event' => fn () => GetLimitedEventSharedProps::run($league)['event'],
            'currentPage' => 'draft',
            'review' => fn () => $draft ? BuildDraftReview::run($draft) : null,
            'selectedOrdinal' => $selected,
            'crossDraft' => Inertia::defer(fn () => ComputeCrossDraftCardStats::run($league)),
        ]);
    }
}
