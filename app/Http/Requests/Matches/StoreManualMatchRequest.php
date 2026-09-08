<?php

namespace App\Http\Requests\Matches;

use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Deck;
use App\Models\League;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreManualMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $accountId = Account::currentId();

        return [
            'deck_id' => [
                'required',
                'integer',
                Rule::exists('decks', 'id')->where(fn ($q) => $q->where('account_id', $accountId)),
            ],
            'opponent_name' => ['required', 'string', 'max:255'],
            'archetype_id' => ['nullable', 'integer', Rule::exists('archetypes', 'id')],
            'league_id' => ['nullable', 'integer', Rule::exists('leagues', 'id')->whereNull('deleted_at')],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'games' => ['required', 'array', 'min:1', 'max:3'],
            'games.*.won' => ['required', 'boolean'],
            'games.*.on_play' => ['required', 'boolean'],
            'games.*.turns' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateGameRecord($validator);
                $this->validateDeckHasVersion($validator);
                $this->validateLeague($validator);
            },
        ];
    }

    private function validateGameRecord(Validator $validator): void
    {
        $games = collect($this->input('games', []));
        $wins = $games->filter(fn (array $game) => filter_var($game['won'], FILTER_VALIDATE_BOOLEAN))->count();
        $losses = $games->count() - $wins;

        if ($wins > 2 || $losses > 2) {
            $validator->errors()->add('games', 'A best-of-three match cannot have more than two wins or two losses.');

            return;
        }

        // A third game after a 2-0 is not a real match shape either.
        $priorWins = $games->slice(0, -1)->filter(fn (array $game) => filter_var($game['won'], FILTER_VALIDATE_BOOLEAN))->count();
        $priorLosses = $games->count() - 1 - $priorWins;

        if ($priorWins >= 2 || $priorLosses >= 2) {
            $validator->errors()->add('games', 'The match was already decided before the last game.');
        }
    }

    private function validateDeckHasVersion(Validator $validator): void
    {
        $deck = Deck::query()->find($this->integer('deck_id'));

        if ($deck && ! $deck->latestVersion()->exists()) {
            $validator->errors()->add('deck_id', 'This deck has no saved version to attach the match to.');
        }
    }

    private function validateLeague(Validator $validator): void
    {
        if (! $this->filled('league_id')) {
            return;
        }

        $league = League::query()->with('deckVersion')->find($this->integer('league_id'));

        if (! $league) {
            return;
        }

        if ($league->deckVersion && $league->deckVersion->deck_id !== $this->integer('deck_id')) {
            $validator->errors()->add('league_id', 'This league was played with a different deck.');

            return;
        }

        $played = $league->matches()->where('state', MatchState::Complete)->count();

        if ($played >= $league->kind->roundCount()) {
            $validator->errors()->add('league_id', 'This league already has a full run of matches.');
        }
    }
}
