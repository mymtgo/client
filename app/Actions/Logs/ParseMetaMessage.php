<?php

namespace App\Actions\Logs;

use App\Enums\MetaMessageKind;

/**
 * Parse one MetaMessage byte array (the integer array seen inside MTGO
 * "GsMessageMessage" log lines) into a structured event.
 *
 * Format (observed):
 *   - bytes 0..3   uint32 LE  remaining length
 *   - byte  4      uint8      event type
 *   - bytes 5..11  7 bytes    sender/session token
 *   - bytes 12+    payload    type-dependent
 *
 * Common types:
 *   3   = chat / game log line (ascii payload, "@P" prefix)
 *   4   = opponent username (raw ascii)
 *   9   = system message (ascii)
 *   44  = client UI prompt (button labels, hints)
 *   82  = deck list (uint32 count then count * uint64 multiverse IDs)
 */
class ParseMetaMessage
{
    /**
     * @param  array<int, int>  $bytes
     * @return array{
     *     type: int,
     *     kind: string,
     *     text: ?string,
     *     cards: ?array<int, int>,
     *     event: ?array{action: string, player?: string, value?: int|string, card?: array{name: string, multiverse_id: int, instance_id: int}}
     * }|null
     */
    public static function run(array $bytes): ?array
    {
        if (count($bytes) < 5) {
            return null;
        }

        $type = $bytes[4];
        $text = self::extractText($bytes);
        $cards = $type === 82 ? self::extractDeckCards($bytes) : null;
        $event = $text !== null ? self::classifyChat($type, $text) : null;

        return [
            'type' => $type,
            'kind' => self::kindFor($type, $event),
            'text' => $text,
            'cards' => $cards,
            'event' => $event,
        ];
    }

    /**
     * Find a trailing ASCII payload. Many event types end with:
     *   ...[4 bytes uint32 LE length N][N ascii bytes]
     * Falling back to longest printable run keeps unknown types usable.
     */
    private static function extractText(array $bytes): ?string
    {
        $count = count($bytes);

        if ($count >= 5) {
            for ($payloadStart = 16; $payloadStart <= $count - 4; $payloadStart += 4) {
                $lenAt = $payloadStart - 4;
                $declared = $bytes[$lenAt]
                    | ($bytes[$lenAt + 1] << 8)
                    | ($bytes[$lenAt + 2] << 16)
                    | ($bytes[$lenAt + 3] << 24);

                if ($declared <= 0 || $declared > $count) {
                    continue;
                }

                if ($payloadStart + $declared !== $count) {
                    continue;
                }

                $slice = array_slice($bytes, $payloadStart, $declared);
                $ascii = self::bytesToPrintable($slice);

                if ($ascii !== null) {
                    return $ascii;
                }
            }
        }

        return self::longestPrintableRun($bytes, min: 4);
    }

    private static function bytesToPrintable(array $bytes): ?string
    {
        $out = '';
        foreach ($bytes as $b) {
            if ($b === 0) {
                continue;
            }

            if ($b < 32 || $b > 126) {
                return null;
            }

            $out .= chr($b);
        }

        return $out !== '' ? $out : null;
    }

    private static function longestPrintableRun(array $bytes, int $min): ?string
    {
        $best = '';
        $current = '';

        foreach ($bytes as $b) {
            if ($b >= 32 && $b <= 126) {
                $current .= chr($b);

                continue;
            }

            if (strlen($current) > strlen($best)) {
                $best = $current;
            }

            $current = '';
        }

        if (strlen($current) > strlen($best)) {
            $best = $current;
        }

        return strlen($best) >= $min ? $best : null;
    }

    /**
     * Extract main deck multiverse IDs from a type=82 message.
     *
     * @return array<int, int>|null
     */
    private static function extractDeckCards(array $bytes): ?array
    {
        $count = count($bytes);

        if ($count < 24) {
            return null;
        }

        $declaredCount = $bytes[12]
            | ($bytes[13] << 8)
            | ($bytes[14] << 16)
            | ($bytes[15] << 24);

        if ($declaredCount <= 0 || $declaredCount > 100) {
            return null;
        }

        $needed = 16 + ($declaredCount * 8);

        if ($needed > $count) {
            return null;
        }

        $cards = [];
        for ($i = 16; $i < $needed; $i += 8) {
            $cards[] = self::readUint64LE($bytes, $i);
        }

        return $cards;
    }

    private static function readUint64LE(array $bytes, int $offset): int
    {
        $low = $bytes[$offset]
            | ($bytes[$offset + 1] << 8)
            | ($bytes[$offset + 2] << 16)
            | ($bytes[$offset + 3] << 24);

        $high = $bytes[$offset + 4]
            | ($bytes[$offset + 5] << 8)
            | ($bytes[$offset + 6] << 16)
            | ($bytes[$offset + 7] << 24);

        return ($high << 32) | ($low & 0xFFFFFFFF);
    }

    private static function kindFor(int $type, ?array $event): string
    {
        if ($type === 82) {
            return MetaMessageKind::DeckList->value;
        }

        if ($type === 4) {
            return MetaMessageKind::OpponentName->value;
        }

        if ($event !== null) {
            return match ($event['action']) {
                'die_roll' => MetaMessageKind::DieRoll->value,
                'play_choice' => MetaMessageKind::PlayChoice->value,
                'mulligan' => MetaMessageKind::Mulligan->value,
                'starting_hand' => MetaMessageKind::StartingHand->value,
                'game_winner' => MetaMessageKind::GameWinner->value,
                'concede' => MetaMessageKind::Concede->value,
                'turn_start' => MetaMessageKind::TurnStart->value,
                'joined' => MetaMessageKind::Joined->value,
                'cast_card' => MetaMessageKind::CastCard->value,
                'play_card' => MetaMessageKind::PlayCard->value,
                default => MetaMessageKind::Chat->value,
            };
        }

        return match ($type) {
            3 => MetaMessageKind::Chat->value,
            9 => MetaMessageKind::System->value,
            44 => MetaMessageKind::UiPrompt->value,
            default => MetaMessageKind::Unknown->value,
        };
    }

    /**
     * Classify the human-readable text of a chat line (type=3) into a
     * semantic game event so the pipeline doesn't need to re-regex later.
     *
     * @return array{action: string, player?: string, value?: int|string, card?: array{name: string, multiverse_id: int, instance_id: int}}|null
     */
    private static function classifyChat(int $type, string $text): ?array
    {
        if ($type !== 3) {
            return null;
        }

        $clean = preg_replace('/^(?:@P){1,2}/', '', $text) ?? $text;

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) rolled a (?<value>\d+)\.$/', $clean, $m)) {
            return ['action' => 'die_roll', 'player' => $m['player'], 'value' => (int) $m['value']];
        }

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) chooses to (?<choice>play|draw)/', $clean, $m)) {
            return ['action' => 'play_choice', 'player' => $m['player'], 'value' => $m['choice']];
        }

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) mulligans to (?<value>\w+)(?: cards?)?[.\s]?/', $clean, $m)) {
            return ['action' => 'mulligan', 'player' => $m['player'], 'value' => self::wordToInt($m['value'])];
        }

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) (?:puts? (?:a card|\w+ cards) on the bottom of their library and )?begins the game with (?<value>\w+) cards? in hand/', $clean, $m)) {
            return ['action' => 'starting_hand', 'player' => $m['player'], 'value' => self::wordToInt($m['value'])];
        }

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) wins the game/', $clean, $m)) {
            return ['action' => 'game_winner', 'player' => $m['player']];
        }

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) has conceded from the game/', $clean, $m)) {
            return ['action' => 'concede', 'player' => $m['player']];
        }

        if (preg_match('/^Turn (?<value>\d+): (?<player>[A-Za-z][A-Za-z0-9_]+)$/', $clean, $m)) {
            return ['action' => 'turn_start', 'player' => $m['player'], 'value' => (int) $m['value']];
        }

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) joined the game/', $clean, $m)) {
            return ['action' => 'joined', 'player' => $m['player']];
        }

        if (preg_match('/^(?<player>[A-Za-z][A-Za-z0-9_]+) (plays|casts) @\[(?<name>[^@]+)@:(?<multi>\d+),(?<inst>\d+):@\]/', $clean, $m)) {
            return [
                'action' => $m[2] === 'plays' ? 'play_card' : 'cast_card',
                'player' => $m['player'],
                'card' => [
                    'name' => $m['name'],
                    'multiverse_id' => (int) $m['multi'],
                    'instance_id' => (int) $m['inst'],
                ],
            ];
        }

        return null;
    }

    private static function wordToInt(string $word): int
    {
        return match (strtolower($word)) {
            'zero' => 0,
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
            default => is_numeric($word) ? (int) $word : -1,
        };
    }
}
