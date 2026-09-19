<?php

namespace App\Http\Requests\Dashboard;

use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetRegistry;
use App\Models\Account;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLayoutRequest extends FormRequest
{
    public const MAX_INSTANCES = 30;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'layout' => ['present', 'array', 'max:'.self::MAX_INSTANCES],
            'layout.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'layout.*.type' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! app(WidgetRegistry::class)->has($value)) {
                    $fail('Unknown widget type.');
                }
            }],
            'layout.*.config' => ['present', 'array'],
        ];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $registry = app(WidgetRegistry::class);
                $singles = [];

                foreach ((array) $this->input('layout', []) as $index => $row) {
                    $type = is_array($row) ? $registry->find((string) ($row['type'] ?? '')) : null;

                    if ($type === null) {
                        continue;
                    }

                    if (! $type->allowsMultiple()) {
                        if (isset($singles[$type->key()])) {
                            $validator->errors()->add("layout.$index.type", 'This widget can only appear once.');

                            continue;
                        }
                        $singles[$type->key()] = true;
                    }

                    try {
                        $config = $type->validateConfig((array) ($row['config'] ?? []));
                    } catch (InvalidWidgetConfig $e) {
                        $validator->errors()->add("layout.$index.config", $e->getMessage());

                        continue;
                    }

                    $this->checkExistence($validator, $index, $type->key(), $config);
                }
            },
        ];
    }

    /**
     * Normalised layout ready to store.
     *
     * @return array<int, array{id: string, type: string, config: array<string, mixed>}>
     */
    public function layout(): array
    {
        $registry = app(WidgetRegistry::class);

        return array_values(array_map(fn (array $row) => [
            'id' => $row['id'],
            'type' => $row['type'],
            'config' => $registry->find($row['type'])->validateConfig($row['config']),
        ], $this->validated('layout')));
    }

    /** @param array<string, mixed> $config */
    private function checkExistence(Validator $validator, int|string $index, string $type, array $config): void
    {
        $accountId = Account::currentId();

        if ($type === 'deck_stats') {
            $visible = Deck::query()->whereKey($config['deck_id'])
                ->when($accountId, fn ($q, $id) => $q->where('account_id', $id))
                ->exists();
            if (! $visible) {
                $validator->errors()->add("layout.$index.config.deck_id", 'Choose one of your decks.');
            }
        }

        if ($type === 'limited_picks' && $config['set_code'] !== null) {
            if (! League::query()->limited()->where('set_code', $config['set_code'])->exists()) {
                $validator->errors()->add("layout.$index.config.set_code", 'No limited leagues for that set.');
            }
        }

        if ($type === 'archetype_stats') {
            $hasMyDeck = Deck::query()
                ->where('archetype_id', $config['archetype_id'])
                ->when($accountId, fn ($q, $id) => $q->where('account_id', $id))
                ->exists();
            if (! $hasMyDeck) {
                $validator->errors()->add("layout.$index.config.archetype_id", 'Choose an archetype one of your decks uses.');
            }
        }

        if ($type === 'league_results' && $config['format'] !== null) {
            $known = MtgoMatch::complete()->notLimitedFormat()->distinct()->pluck('format')->all();
            if (! in_array($config['format'], $known, true)) {
                $validator->errors()->add("layout.$index.config.format", "Unknown format {$config['format']}.");
            }
        }

        if ($type === 'format_stats' && $config['formats'] !== []) {
            $known = MtgoMatch::complete()->notLimitedFormat()->distinct()->pluck('format')->all();
            foreach ($config['formats'] as $code) {
                if (! in_array($code, $known, true)) {
                    $validator->errors()->add("layout.$index.config.formats", "Unknown format $code.");
                }
            }
        }
    }
}
