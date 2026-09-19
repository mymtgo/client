<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\Widgets\FormatStatsWidget;
use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedFormatMatch(int $versionId, string $format, bool $won, ?string $startedAt = null): void
{
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $versionId,
        'format' => $format,
        'outcome' => $won ? MatchOutcome::Win : MatchOutcome::Loss,
        'started_at' => $startedAt ?? now()->subHour(),
    ]);
    Game::factory()->create(['match_id' => $match->id, 'won' => $won]);
}

beforeEach(function () {
    $account = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $this->version = DeckVersion::factory()->create(['deck_id' => $deck->id])->id;
});

it('normalises the formats list', function () {
    $widget = new FormatStatsWidget;

    expect($widget->validateConfig([]))->toBe(['formats' => []])
        ->and($widget->validateConfig(['formats' => ['CModern', 'CModern', '']]))->toBe(['formats' => ['CModern']])
        ->and(fn () => $widget->validateConfig(['formats' => 'CModern']))->toThrow(InvalidWidgetConfig::class)
        ->and(fn () => $widget->validateConfig(['formats' => [1]]))->toThrow(InvalidWidgetConfig::class);
});

it('groups constructed matches by format inside the timeframe', function () {
    seedFormatMatch($this->version, 'CModern', true);
    seedFormatMatch($this->version, 'CModern', false);
    seedFormatMatch($this->version, 'CStandard', true);
    seedFormatMatch($this->version, 'DHOBHOBHOB', true);
    seedFormatMatch($this->version, 'CStandard', true, now()->subYears(2)->toDateTimeString());

    $rows = (new FormatStatsWidget)->resolve(['formats' => []], DashboardScope::fromTimeframe('monthly'));

    expect(collect($rows)->pluck('code')->all())->toBe(['CModern', 'CStandard'])
        ->and($rows[0]['record']->wins)->toBe(1)
        ->and($rows[0]['record']->losses)->toBe(1)
        ->and($rows[0]['gameWinrate'])->toBe(50)
        ->and($rows[1]['record']->wins)->toBe(1)
        ->and($rows[1]['label'])->toBe('Standard');
});

it('honours the configured format list', function () {
    seedFormatMatch($this->version, 'CModern', true);
    seedFormatMatch($this->version, 'CStandard', true);

    $rows = (new FormatStatsWidget)->resolve(['formats' => ['CStandard']], DashboardScope::fromTimeframe('alltime'));

    expect(collect($rows)->pluck('code')->all())->toBe(['CStandard']);
});
