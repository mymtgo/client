<?php

namespace App\Sidecar;

/**
 * Folds sidecar game events into replay frames. One instance per game.
 * The frame shape is the Twitch "Game State" snapshot the log pipeline
 * stores, plus additive keys the log never carries. Optional keys are
 * omitted, never null, so log frames and sidecar frames share one reader.
 * Spec: docs/superpowers/specs/2026-09-23-sidecar-replay-timeline-design.md §3.
 */
final class TimelineFold
{
    private const COMBAT_KEYS = ['Attacking', 'Blocking', 'Damage'];

    /** @var array<int, array<string, mixed>> slot => player frame */
    private array $players = [];

    /** @var array<int, array<string, mixed>> card id => card frame */
    private array $cards = [];

    private ?int $turn = null;

    private ?string $phase = null;

    private ?string $step = null;

    private ?int $activePlayer = null;

    private ?int $priority = null;

    /** @param  array<int, string>  $playerNames  slot => name */
    private function __construct(array $playerNames)
    {
        foreach ($playerNames as $slot => $name) {
            $this->players[(int) $slot] = ['Id' => (int) $slot, 'Name' => (string) $name, 'Life' => 20, 'HandCount' => 0, 'LibraryCount' => 0];
        }
        ksort($this->players);
    }

    /** @param  array<int, string>  $playerNames  slot => name, from SidecarGameView::playerNames */
    public static function start(array $playerNames): self
    {
        return new self($playerNames);
    }

    /**
     * Applies one event. Returns true when the visible frame changed, so the
     * caller knows whether this tick deserves a row. clock_tick mutates
     * TimeLeft silently: the clock alone is not a state change. game_started
     * and game_ended are frame anchors: they change nothing but always emit,
     * so the replay opens and closes on the real game boundary timestamps.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(string $type, array $data): bool
    {
        return match ($type) {
            'game_started', 'game_ended' => true,
            'keyframe' => $this->applyKeyframe($data),
            'turn_started' => $this->applyTurnStarted($data),
            'phase_changed' => $this->applyPhaseChanged($data),
            'priority_changed' => $this->setPriority($data),
            'card_zone_changed' => $this->applyZoneChange($data),
            'card_tapped' => $this->setCard($data, 'Tapped', true),
            'card_untapped' => $this->setCard($data, 'Tapped', false),
            'card_pt_changed' => $this->applyPtChanged($data),
            'card_counters_changed' => $this->setCounters($data),
            'card_attacking' => $this->setCard($data, 'Attacking', (int) ($data['target_p'] ?? 0)),
            'card_blocking' => $this->setCard($data, 'Blocking', (int) ($data['target_c'] ?? 0)),
            'card_damage' => $this->setCard($data, 'Damage', (int) ($data['damage'] ?? 0)),
            'card_revealed' => $this->applyCardRevealed($data),
            'life_changed' => $this->setPlayer($data, 'Life', (int) ($data['life'] ?? 0)),
            'hand_count_changed' => $this->setPlayer($data, 'HandCount', (int) ($data['count'] ?? 0)),
            'library_count_changed' => $this->setPlayer($data, 'LibraryCount', (int) ($data['count'] ?? 0)),
            'mana_pool_changed' => $this->setPlayer($data, 'Pool', self::pool($data['pool'] ?? [])),
            'clock_tick' => $this->applyClockTick($data),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function frame(): array
    {
        $frame = [];
        if ($this->turn !== null) {
            $frame['Turn'] = $this->turn;
        }
        if ($this->phase !== null) {
            $frame['Phase'] = $this->phase;
        }
        if ($this->step !== null) {
            $frame['Step'] = $this->step;
        }
        if ($this->activePlayer !== null) {
            $frame['ActivePlayer'] = $this->activePlayer;
        }
        if ($this->priority !== null) {
            $frame['Priority'] = $this->priority;
        }

        $cards = $this->cards;
        ksort($cards);

        $frame['Players'] = array_values($this->players);
        $frame['Cards'] = array_values($cards);

        return $frame;
    }

    /** @param  array<string, mixed>  $data */
    private function applyKeyframe(array $data): bool
    {
        $this->turn = isset($data['turn']) ? (int) $data['turn'] : null;
        $this->phase = isset($data['phase']) ? (string) $data['phase'] : null;
        $this->step = isset($data['step']) ? (string) $data['step'] : null;
        $this->activePlayer = isset($data['active_p']) ? (int) $data['active_p'] : null;
        $this->priority = isset($data['priority_p']) ? (int) $data['priority_p'] : null;

        foreach ($data['players'] ?? [] as $p) {
            $slot = (int) ($p['p'] ?? -1);
            if (! isset($this->players[$slot])) {
                continue;
            }
            $player = ['Id' => $slot, 'Name' => $this->players[$slot]['Name']];
            $player['Life'] = (int) ($p['life'] ?? 20);
            $player['HandCount'] = (int) ($p['hand'] ?? 0);
            $player['LibraryCount'] = (int) ($p['library'] ?? 0);
            if (isset($p['clock_ms'])) {
                $player['TimeLeft'] = (int) $p['clock_ms'];
            }
            if (array_key_exists('pool', $p)) {
                $player['Pool'] = self::pool($p['pool']);
            }
            $this->players[$slot] = $player;
        }

        $this->cards = [];
        foreach ($data['cards'] ?? [] as $c) {
            $id = (int) ($c['c'] ?? 0);
            $card = [
                'Id' => $id,
                'CatalogID' => (int) ($c['catalog_id'] ?? 0),
                'Zone' => (string) ($c['zone'] ?? 'Nowhere'),
                'Owner' => (int) ($c['owner_p'] ?? 0),
                'Controller' => (int) ($c['controller_p'] ?? 0),
                'Tapped' => (bool) ($c['tapped'] ?? false),
            ];
            if ($card['Zone'] === 'Nowhere') {
                continue;
            }
            if (isset($c['power'])) {
                $card['Power'] = (int) $c['power'];
            }
            if (isset($c['toughness'])) {
                $card['Toughness'] = (int) $c['toughness'];
            }
            $counters = self::counters($c['counters'] ?? []);
            if ($counters !== []) {
                $card['Counters'] = $counters;
            }
            if (isset($c['attacking'])) {
                $card['Attacking'] = (int) $c['attacking'];
            }
            if (isset($c['blocking'])) {
                $card['Blocking'] = (int) $c['blocking'];
            }
            if (isset($c['damage'])) {
                $card['Damage'] = (int) $c['damage'];
            }
            $this->cards[$id] = $card;
        }

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function applyTurnStarted(array $data): bool
    {
        $this->turn = isset($data['turn']) ? (int) $data['turn'] : $this->turn;
        $this->activePlayer = isset($data['active_p']) ? (int) $data['active_p'] : $this->activePlayer;
        foreach (array_keys($this->cards) as $id) {
            foreach (self::COMBAT_KEYS as $key) {
                unset($this->cards[$id][$key]);
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function setPriority(array $data): bool
    {
        $this->priority = isset($data['p']) ? (int) $data['p'] : $this->priority;

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function applyPhaseChanged(array $data): bool
    {
        $this->phase = isset($data['phase']) ? (string) $data['phase'] : $this->phase;
        $this->step = isset($data['step']) ? (string) $data['step'] : $this->step;

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function applyClockTick(array $data): bool
    {
        $this->setPlayer($data, 'TimeLeft', (int) ($data['remaining_ms'] ?? 0));

        return false;
    }

    /**
     * A reveal only ever narrows an unknown card. An absent or zero catalog_id
     * tells us nothing, so it must not overwrite a CatalogID we already know.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyCardRevealed(array $data): bool
    {
        if (empty($data['catalog_id'])) {
            return false;
        }

        return $this->setCard($data, 'CatalogID', (int) $data['catalog_id']);
    }

    /** @param  array<string, mixed>  $data */
    private function applyPtChanged(array $data): bool
    {
        $id = (int) ($data['c'] ?? 0);
        if (! isset($this->cards[$id])) {
            return false;
        }
        $this->cards[$id]['Power'] = (int) ($data['power'] ?? 0);
        $this->cards[$id]['Toughness'] = (int) ($data['toughness'] ?? 0);

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function applyZoneChange(array $data): bool
    {
        $id = (int) ($data['c'] ?? 0);
        $to = (string) ($data['to'] ?? 'Nowhere');

        if ($to === 'Nowhere') {
            unset($this->cards[$id]);

            return true;
        }

        $card = $this->cards[$id] ?? ['Id' => $id, 'CatalogID' => 0, 'Zone' => $to, 'Owner' => 0, 'Controller' => 0, 'Tapped' => false];
        $card['Zone'] = $to;
        $card['Owner'] = (int) ($data['owner_p'] ?? $card['Owner']);
        $card['Controller'] = (int) ($data['controller_p'] ?? $card['Controller']);
        if (! empty($data['catalog_id'])) {
            $card['CatalogID'] = (int) $data['catalog_id'];
        }
        foreach (self::COMBAT_KEYS as $key) {
            unset($card[$key]);
        }
        $this->cards[$id] = $card;

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function setCounters(array $data): bool
    {
        $id = (int) ($data['c'] ?? 0);
        if (! isset($this->cards[$id])) {
            return false;
        }
        $counters = self::counters($data['counters'] ?? []);
        if ($counters === []) {
            unset($this->cards[$id]['Counters']);
        } else {
            $this->cards[$id]['Counters'] = $counters;
        }

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function setCard(array $data, string $key, mixed $value): bool
    {
        $id = (int) ($data['c'] ?? 0);
        if (! isset($this->cards[$id])) {
            return false;
        }
        $this->cards[$id][$key] = $value;

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function setPlayer(array $data, string $key, mixed $value): bool
    {
        $slot = (int) ($data['p'] ?? -1);
        if (! isset($this->players[$slot])) {
            return false;
        }
        $this->players[$slot][$key] = $value;

        return true;
    }

    /** @return array<string, int> */
    private static function pool(mixed $pool): array
    {
        $out = [];
        foreach (is_array($pool) ? $pool : [] as $colour => $n) {
            $out[(string) $colour] = (int) $n;
        }

        return $out;
    }

    /** @return array<string, int> */
    private static function counters(mixed $counters): array
    {
        $out = [];
        foreach (is_array($counters) ? $counters : [] as $kind => $n) {
            if ((int) $n !== 0) {
                $out[(string) $kind] = (int) $n;
            }
        }

        return $out;
    }
}
