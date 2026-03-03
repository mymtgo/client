<?php

namespace App\Actions\Cards;

use App\Facades\Mtgo;
use App\Models\Card;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;

class PopulateTokensFromXml
{
    /**
     * Parse local MTGO XMLs to identify and populate token Card records.
     *
     * @param  Collection<int, Card>|null  $cards  Stub cards to check against tokens. If null, queries all cards missing a name.
     * @return int Number of cards updated.
     */
    public static function run(?Collection $cards = null): int
    {
        $cardDataPath = static::findCardDataSourceDir();

        if (! $cardDataPath) {
            return 0;
        }

        $cards ??= Card::whereNull('name')->get();

        if ($cards->isEmpty()) {
            return 0;
        }

        $tokenFile = $cardDataPath.DIRECTORY_SEPARATOR.'client_TOK.xml';

        if (! file_exists($tokenFile) || ! is_readable($tokenFile)) {
            return 0;
        }

        $names = static::parseStringTable($cardDataPath, 'CARDNAME_STRING');
        $colors = static::parseStringTable($cardDataPath, 'COLOR');
        $creatureTypes = static::parseStringTable($cardDataPath, 'CREATURE_TYPE_STRING0');

        if (empty($names)) {
            return 0;
        }

        $tokens = static::parseTokenFile($tokenFile);

        $cardsByMtgoId = $cards->keyBy('mtgo_id');
        $updated = 0;

        foreach ($tokens as $token) {
            $mtgoId = $token['mtgo_id'];

            if (! $cardsByMtgoId->has((string) $mtgoId)) {
                continue;
            }

            $card = $cardsByMtgoId->get((string) $mtgoId);
            $name = $names[$token['cardname_id']] ?? null;

            if (! $name) {
                continue;
            }

            $card->update([
                'name' => $name,
                'type' => static::buildTypeLine($token),
                'sub_type' => $creatureTypes[$token['creature_type_id']] ?? null,
                'rarity' => 'token',
                'color_identity' => static::mapColor($colors[$token['color_id']] ?? null),
            ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * Find the CardDataSource directory within the MTGO data path.
     */
    public static function findCardDataSourceDir(): ?string
    {
        $basePath = Mtgo::getLogDataPath();

        if (empty($basePath) || ! is_dir($basePath)) {
            return null;
        }

        try {
            $finder = Finder::create()
                ->directories()
                ->in($basePath)
                ->ignoreUnreadableDirs()
                ->name('CardDataSource');

            foreach ($finder as $dir) {
                return $dir->getPathname();
            }
        } catch (\Throwable) {
            // Directory not accessible
        }

        return null;
    }

    /**
     * Parse a string table XML (CARDNAME_STRING, COLOR, CREATURE_TYPE_STRING0).
     *
     * @return array<string, string> Map of id => value
     */
    public static function parseStringTable(string $cardDataPath, string $tableName): array
    {
        $file = $cardDataPath.DIRECTORY_SEPARATOR.$tableName.'.xml';

        if (! file_exists($file) || ! is_readable($file)) {
            return [];
        }

        try {
            $xml = simplexml_load_file($file);
        } catch (\Throwable) {
            return [];
        }

        if ($xml === false) {
            return [];
        }

        $map = [];
        $itemTag = $tableName.'_ITEM';

        foreach ($xml->{$itemTag} as $item) {
            $id = (string) $item['id'];
            $value = (string) $item;

            if ($id && $value !== '') {
                $map[$id] = $value;
            }
        }

        return $map;
    }

    /**
     * Parse client_TOK.xml into an array of token definitions, skipping CLONE_ID entries (foils).
     *
     * @return array<int, array{mtgo_id: string, cardname_id: string, color_id: string|null, creature_type_id: string|null, is_creature: bool, is_artifact: bool, is_enchantment: bool, is_land: bool}>
     */
    public static function parseTokenFile(string $tokenFile): array
    {
        try {
            $xml = simplexml_load_file($tokenFile);
        } catch (\Throwable) {
            return [];
        }

        if ($xml === false) {
            return [];
        }

        $tokens = [];

        foreach ($xml->DigitalObject as $obj) {
            // Skip foil clones
            if (isset($obj->CLONE_ID)) {
                continue;
            }

            $catalogId = (string) $obj['DigitalObjectCatalogID'];

            if (! $catalogId || ! str_starts_with($catalogId, 'DOC_')) {
                continue;
            }

            $mtgoId = substr($catalogId, 4);

            $tokens[] = [
                'mtgo_id' => $mtgoId,
                'cardname_id' => (string) ($obj->CARDNAME_STRING['id'] ?? ''),
                'color_id' => (string) ($obj->COLOR['id'] ?? ''),
                'creature_type_id' => (string) ($obj->CREATURE_TYPE_STRING0['id'] ?? ''),
                'is_creature' => isset($obj->IS_CREATURE),
                'is_artifact' => isset($obj->IS_ARTIFACT),
                'is_enchantment' => isset($obj->IS_ENCHANTMENT),
                'is_land' => isset($obj->IS_LAND),
            ];
        }

        return $tokens;
    }

    /**
     * Build a type line from token flags: "Token Creature", "Token Artifact Creature", etc.
     */
    public static function buildTypeLine(array $token): string
    {
        $parts = ['Token'];

        if ($token['is_artifact']) {
            $parts[] = 'Artifact';
        }

        if ($token['is_enchantment']) {
            $parts[] = 'Enchantment';
        }

        if ($token['is_land']) {
            $parts[] = 'Land';
        }

        if ($token['is_creature']) {
            $parts[] = 'Creature';
        }

        // If none of the type flags were set, it's just a "Token"
        return implode(' ', $parts);
    }

    /**
     * Map MTGO color string to WUBRG color identity.
     *
     * "COLOR_WHITE|COLOR_BLUE" → "W,U"
     * "COLOR_COLORLESS" → "C"
     * "COLOR_GREEN" → "G"
     */
    public static function mapColor(?string $mtgoColor): string
    {
        if (! $mtgoColor) {
            return 'C';
        }

        $colorMap = [
            'COLOR_WHITE' => 'W',
            'COLOR_BLUE' => 'U',
            'COLOR_BLACK' => 'B',
            'COLOR_RED' => 'R',
            'COLOR_GREEN' => 'G',
            'COLOR_COLORLESS' => 'C',
        ];

        $parts = explode('|', $mtgoColor);
        $colors = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if (isset($colorMap[$part])) {
                $colors[] = $colorMap[$part];
            }
        }

        if (empty($colors) || $colors === ['C']) {
            return 'C';
        }

        // Remove colorless if actual colors are present
        $colors = array_filter($colors, fn ($c) => $c !== 'C');

        return implode(',', $colors);
    }
}
