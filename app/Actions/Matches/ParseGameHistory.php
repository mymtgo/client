<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;
use Symfony\Component\Finder\Finder;

/**
 * Parses the MTGO mtgo_game_history binary file (.NET BinaryFormatter format)
 * and returns an array of historical match records.
 */
class ParseGameHistory
{
    /** .NET epoch offset: ticks between 0001-01-01 and 1970-01-01. */
    private const DOTNET_EPOCH_OFFSET = 621355968000000000;

    // BinaryFormatter primitive type IDs
    private const PT_BOOLEAN = 1;

    private const PT_BYTE = 2;

    private const PT_CHAR = 3;

    private const PT_DECIMAL = 5;

    private const PT_DOUBLE = 6;

    private const PT_INT16 = 7;

    private const PT_INT32 = 8;

    private const PT_INT64 = 9;

    private const PT_SBYTE = 10;

    private const PT_SINGLE = 11;

    private const PT_TIMESPAN = 12;

    private const PT_DATETIME = 13;

    private const PT_UINT16 = 14;

    private const PT_UINT32 = 15;

    private const PT_UINT64 = 16;

    private const PT_STRING = 18;

    // BinaryFormatter member type IDs
    private const MT_PRIMITIVE = 0;

    private const MT_STRING = 1;

    private const MT_OBJECT = 2;

    private const MT_SYSTEM_CLASS = 3;

    private const MT_CLASS = 4;

    private const MT_OBJECT_ARRAY = 5;

    private const MT_STRING_ARRAY = 6;

    private const MT_PRIMITIVE_ARRAY = 7;

    // BinaryFormatter record type IDs
    private const RT_SERIALIZED_STREAM_HEADER = 0;

    private const RT_CLASS_WITH_ID = 1;

    private const RT_SYSTEM_CLASS_WITH_MEMBERS_AND_TYPES = 4;

    private const RT_CLASS_WITH_MEMBERS_AND_TYPES = 5;

    private const RT_BINARY_OBJECT_STRING = 6;

    private const RT_BINARY_ARRAY = 7;

    private const RT_MEMBER_PRIMITIVE_TYPED = 8;

    private const RT_MEMBER_REFERENCE = 9;

    private const RT_OBJECT_NULL = 10;

    private const RT_MESSAGE_END = 11;

    private const RT_BINARY_LIBRARY = 12;

    private const RT_OBJECT_NULL_MULTIPLE_256 = 13;

    private const RT_OBJECT_NULL_MULTIPLE = 14;

    private const RT_ARRAY_SINGLE_PRIMITIVE = 15;

    private const RT_ARRAY_SINGLE_OBJECT = 16;

    private const RT_ARRAY_SINGLE_STRING = 17;

    /** Raw binary data. */
    private string $data;

    /** Current byte position in the data. */
    private int $pos = 0;

    /** Total length of the data. */
    private int $len;

    /** @var array<int, mixed> Object store: objectId => value */
    private array $objects = [];

    /** @var array<int, array> Class definitions: classId => definition */
    private array $classDefs = [];

    /**
     * Parse the MTGO game history file and return match records.
     *
     * @return array<int, array{
     *     Id: ?int,
     *     StartTime: ?string,
     *     Opponents: array<string>,
     *     GameWins: int,
     *     GameLosses: int,
     *     MatchWinners: array<string>,
     *     MatchLosers: array<string>,
     *     GameIds: array<int>,
     *     GameWinsToWinMatch: ?int,
     *     Description: ?string,
     *     Round: ?int,
     *     Format: ?string,
     * }>
     */
    public static function run(?string $path = null): array
    {
        return Cache::remember('mtgo.game_history', now()->addSeconds(30), function () use ($path) {
            return static::parse($path);
        });
    }

    /**
     * Parse without caching.
     *
     * When a specific path is given, only that file is parsed.
     * Otherwise all discovered mtgo_game_history files are parsed
     * and merged (deduplicated by match Id).
     *
     * @return array<int, array>
     */
    public static function parse(?string $path = null): array
    {
        if ($path !== null) {
            return static::parseFile($path);
        }

        $paths = static::findFiles();

        if (empty($paths)) {
            Log::warning('ParseGameHistory: no game history files found');

            return [];
        }

        $allMatches = [];

        foreach ($paths as $filePath) {
            $matches = static::parseFile($filePath);

            foreach ($matches as $match) {
                $id = $match['Id'] ?? null;
                if ($id !== null) {
                    $allMatches[$id] = $match;
                }
            }
        }

        return array_values($allMatches);
    }

    /**
     * Parse a single mtgo_game_history file.
     *
     * @return array<int, array>
     */
    protected static function parseFile(string $path): array
    {
        try {
            if (! file_exists($path)) {
                Log::warning('ParseGameHistory: game history file not found', ['path' => $path]);

                return [];
            }

            $parser = new self(file_get_contents($path));

            return $parser->doParse();
        } catch (\Throwable $e) {
            Log::warning('ParseGameHistory: failed to parse game history', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'path' => $path,
                'trace' => array_slice($e->getTrace(), 0, 3),
            ]);

            return [];
        }
    }

    /**
     * Discover all mtgo_game_history files.
     *
     * MTGO's ClickOnce deployment creates multiple copies across
     * hashed subdirectories — one per installation/update. We parse
     * all of them and merge results to get the complete history.
     *
     * @return array<string>
     */
    public static function findFiles(): array
    {
        try {
            $dataPath = Mtgo::getLogDataPath();

            if (! is_dir($dataPath)) {
                return [];
            }

            $finder = Finder::create()
                ->files()
                ->name('mtgo_game_history')
                ->in($dataPath)
                ->ignoreUnreadableDirs()
                ->depth('< 10');

            $paths = [];

            foreach ($finder as $file) {
                $paths[] = $file->getRealPath();
            }

            Log::channel('pipeline')->debug('ParseGameHistory: discovered game history files', [
                'count' => count($paths),
            ]);

            return $paths;
        } catch (\Throwable $e) {
            Log::warning('ParseGameHistory: could not discover game history files', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    private function __construct(string $data)
    {
        $this->data = $data;
        $this->len = strlen($data);
    }

    /**
     * Execute the binary parse and return cleaned match records.
     *
     * @return array<int, array>
     */
    private function doParse(): array
    {
        $rootObject = null;

        while ($this->pos < $this->len) {
            $recordType = $this->readByte();

            if ($recordType === self::RT_MESSAGE_END) {
                break;
            }

            $result = $this->parseRecord($recordType);

            if ($rootObject === null && is_array($result) && isset($result['__type'])) {
                $rootObject = $result;
            }
        }

        if ($rootObject === null) {
            return [];
        }

        $resolved = $this->resolveRefs($rootObject);

        return $this->extractMatches($resolved);
    }

    // ── Primitive readers ──

    private function readByte(): int
    {
        return ord($this->data[$this->pos++]);
    }

    private function readBytes(int $n): string
    {
        $result = substr($this->data, $this->pos, $n);
        $this->pos += $n;

        return $result;
    }

    private function readInt32(): int
    {
        $val = unpack('V', $this->data, $this->pos)[1];
        $this->pos += 4;

        if ($val >= 0x80000000) {
            $val -= 0x100000000;
        }

        return $val;
    }

    private function readUInt32(): int
    {
        $val = unpack('V', $this->data, $this->pos)[1];
        $this->pos += 4;

        return $val;
    }

    private function readInt64(): int
    {
        $val = unpack('P', $this->data, $this->pos)[1];
        $this->pos += 8;

        return $val;
    }

    private function readDouble(): float
    {
        $val = unpack('e', $this->data, $this->pos)[1];
        $this->pos += 8;

        return $val;
    }

    private function readInt16(): int
    {
        $val = unpack('v', $this->data, $this->pos)[1];
        $this->pos += 2;

        if ($val >= 0x8000) {
            $val -= 0x10000;
        }

        return $val;
    }

    private function readSingle(): float
    {
        $val = unpack('g', $this->data, $this->pos)[1];
        $this->pos += 4;

        return $val;
    }

    private function readBoolean(): bool
    {
        return $this->readByte() !== 0;
    }

    private function readChar(): string
    {
        $b = ord($this->data[$this->pos]);

        if ($b < 0x80) {
            $this->pos++;

            return chr($b);
        } elseif ($b < 0xE0) {
            $ch = substr($this->data, $this->pos, 2);
            $this->pos += 2;

            return $ch;
        } else {
            $ch = substr($this->data, $this->pos, 3);
            $this->pos += 3;

            return $ch;
        }
    }

    /**
     * Read a length-prefixed string (.NET BinaryWriter format: 7-bit encoded length + UTF-8 bytes).
     */
    private function readString(): string
    {
        $length = $this->read7BitEncodedInt();

        if ($length === 0) {
            return '';
        }

        return $this->readBytes($length);
    }

    private function read7BitEncodedInt(): int
    {
        $result = 0;
        $shift = 0;

        do {
            $b = $this->readByte();
            $result |= ($b & 0x7F) << $shift;
            $shift += 7;
        } while ($b & 0x80);

        return $result;
    }

    /**
     * .NET DateTime: 64-bit value where bits 63-62 are DateTimeKind, bits 61-0 are ticks since 0001-01-01.
     */
    private function readDateTime(): ?string
    {
        $raw = $this->readInt64();
        $ticks = $raw & 0x3FFFFFFFFFFFFFFF;
        $unixTicks = $ticks - self::DOTNET_EPOCH_OFFSET;
        $unixSeconds = $unixTicks / 10000000;

        if ($unixSeconds < -62135596800 || $unixSeconds > 253402300800) {
            return null;
        }

        try {
            $tz = Settings::get('system_tz', 'UTC');
            $wallClock = gmdate('Y-m-d H:i:s', (int) $unixSeconds);

            return Carbon::parse($wallClock, $tz)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }

    private function readTimeSpan(): string
    {
        $ticks = $this->readInt64();
        $totalSeconds = abs($ticks) / 10000000;
        $hours = (int) ($totalSeconds / 3600);
        $minutes = (int) (($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;
        $sign = $ticks < 0 ? '-' : '';

        return sprintf('%s%02d:%02d:%02d', $sign, $hours, $minutes, $seconds);
    }

    private function readPrimitiveValue(int $primitiveType): mixed
    {
        return match ($primitiveType) {
            self::PT_BOOLEAN => $this->readBoolean(),
            self::PT_BYTE => $this->readByte(),
            self::PT_CHAR => $this->readChar(),
            self::PT_DOUBLE => $this->readDouble(),
            self::PT_INT16 => $this->readInt16(),
            self::PT_INT32 => $this->readInt32(),
            self::PT_INT64 => $this->readInt64(),
            self::PT_SBYTE => $this->readByte(),
            self::PT_SINGLE => $this->readSingle(),
            self::PT_TIMESPAN => $this->readTimeSpan(),
            self::PT_DATETIME => $this->readDateTime(),
            self::PT_UINT16 => unpack('v', $this->readBytes(2))[1],
            self::PT_UINT32 => $this->readUInt32(),
            self::PT_UINT64 => $this->readInt64(),
            self::PT_DECIMAL => $this->readString(),
            self::PT_STRING => $this->readString(),
            default => throw new \RuntimeException("Unknown primitive type: {$primitiveType}"),
        };
    }

    // ── BinaryFormatter record parsing ──

    private function readClassInfo(): array
    {
        $objectId = $this->readInt32();
        $name = $this->readString();
        $memberCount = $this->readInt32();
        $memberNames = [];

        for ($i = 0; $i < $memberCount; $i++) {
            $memberNames[] = $this->readString();
        }

        return [
            'objectId' => $objectId,
            'name' => $name,
            'memberCount' => $memberCount,
            'memberNames' => $memberNames,
        ];
    }

    private function readMemberTypeInfo(int $memberCount): array
    {
        $memberTypes = [];
        for ($i = 0; $i < $memberCount; $i++) {
            $memberTypes[] = $this->readByte();
        }

        $additionalInfos = [];
        for ($i = 0; $i < $memberCount; $i++) {
            $additionalInfos[] = match ($memberTypes[$i]) {
                self::MT_PRIMITIVE, self::MT_PRIMITIVE_ARRAY => $this->readByte(),
                self::MT_SYSTEM_CLASS => $this->readString(),
                self::MT_CLASS => [
                    'className' => $this->readString(),
                    'libraryId' => $this->readInt32(),
                ],
                default => null,
            };
        }

        return ['memberTypes' => $memberTypes, 'additionalInfos' => $additionalInfos];
    }

    private function readMemberValues(array $classDef): array
    {
        $values = [];
        $memberNames = $classDef['memberNames'];
        $memberTypes = $classDef['memberTypes'];
        $additionalInfos = $classDef['additionalInfos'];

        for ($i = 0; $i < count($memberNames); $i++) {
            $values[$memberNames[$i]] = $this->readMemberValue($memberTypes[$i], $additionalInfos[$i]);
        }

        return $values;
    }

    private function readMemberValue(int $memberType, mixed $additionalInfo): mixed
    {
        return match ($memberType) {
            self::MT_PRIMITIVE => $this->readPrimitiveValue($additionalInfo),
            self::MT_STRING,
            self::MT_OBJECT,
            self::MT_SYSTEM_CLASS,
            self::MT_CLASS,
            self::MT_OBJECT_ARRAY,
            self::MT_STRING_ARRAY,
            self::MT_PRIMITIVE_ARRAY => $this->readValueRecord(),
            default => throw new \RuntimeException("Unknown member type: {$memberType}"),
        };
    }

    /**
     * Read a value that appears as an inline record.
     */
    private function readValueRecord(): mixed
    {
        $recordType = $this->readByte();

        return $this->parseRecord($recordType);
    }

    private function parseRecord(int $recordType): mixed
    {
        switch ($recordType) {
            case self::RT_SERIALIZED_STREAM_HEADER:
                $this->readInt32(); // rootId
                $this->readInt32(); // headerId
                $this->readInt32(); // majorVersion
                $this->readInt32(); // minorVersion

                return null;

            case self::RT_BINARY_LIBRARY:
                $libraryId = $this->readInt32();
                $libraryName = $this->readString();

                return $this->readValueRecord();

            case self::RT_CLASS_WITH_MEMBERS_AND_TYPES:
                $classInfo = $this->readClassInfo();
                $typeInfo = $this->readMemberTypeInfo($classInfo['memberCount']);
                $libraryId = $this->readInt32();

                $classDef = [
                    'name' => $classInfo['name'],
                    'memberNames' => $classInfo['memberNames'],
                    'memberTypes' => $typeInfo['memberTypes'],
                    'additionalInfos' => $typeInfo['additionalInfos'],
                    'libraryId' => $libraryId,
                ];
                $this->classDefs[$classInfo['objectId']] = $classDef;

                $values = $this->readMemberValues($classDef);
                $values['__type'] = $classInfo['name'];
                $this->objects[$classInfo['objectId']] = $values;

                return $values;

            case self::RT_SYSTEM_CLASS_WITH_MEMBERS_AND_TYPES:
                $classInfo = $this->readClassInfo();
                $typeInfo = $this->readMemberTypeInfo($classInfo['memberCount']);

                $classDef = [
                    'name' => $classInfo['name'],
                    'memberNames' => $classInfo['memberNames'],
                    'memberTypes' => $typeInfo['memberTypes'],
                    'additionalInfos' => $typeInfo['additionalInfos'],
                    'libraryId' => null,
                ];
                $this->classDefs[$classInfo['objectId']] = $classDef;

                $values = $this->readMemberValues($classDef);
                $values['__type'] = $classInfo['name'];
                $this->objects[$classInfo['objectId']] = $values;

                return $values;

            case self::RT_CLASS_WITH_ID:
                $objectId = $this->readInt32();
                $metadataId = $this->readInt32();

                if (! isset($this->classDefs[$metadataId])) {
                    throw new \RuntimeException("ClassWithId references unknown class def {$metadataId} at pos {$this->pos}");
                }

                $classDef = $this->classDefs[$metadataId];
                $values = $this->readMemberValues($classDef);
                $values['__type'] = $classDef['name'];
                $this->objects[$objectId] = $values;

                return $values;

            case self::RT_BINARY_OBJECT_STRING:
                $objectId = $this->readInt32();
                $value = $this->readString();
                $this->objects[$objectId] = $value;

                return $value;

            case self::RT_MEMBER_REFERENCE:
                $refId = $this->readInt32();

                return ['__ref' => $refId];

            case self::RT_OBJECT_NULL:
                return null;

            case self::RT_OBJECT_NULL_MULTIPLE_256:
                $count = $this->readByte();

                return ['__nulls' => $count];

            case self::RT_OBJECT_NULL_MULTIPLE:
                $count = $this->readInt32();

                return ['__nulls' => $count];

            case self::RT_MEMBER_PRIMITIVE_TYPED:
                $primitiveType = $this->readByte();

                return $this->readPrimitiveValue($primitiveType);

            case self::RT_ARRAY_SINGLE_PRIMITIVE:
                $objectId = $this->readInt32();
                $length = $this->readInt32();
                $primitiveType = $this->readByte();

                $arr = [];
                for ($i = 0; $i < $length; $i++) {
                    $arr[] = $this->readPrimitiveValue($primitiveType);
                }
                $this->objects[$objectId] = $arr;

                return $arr;

            case self::RT_ARRAY_SINGLE_OBJECT:
                $objectId = $this->readInt32();
                $length = $this->readInt32();

                $arr = [];
                for ($i = 0; $i < $length; $i++) {
                    $arr[] = $this->readValueRecord();
                }

                $result = [];
                foreach ($arr as $item) {
                    if (is_array($item) && isset($item['__nulls'])) {
                        for ($n = 0; $n < $item['__nulls']; $n++) {
                            $result[] = null;
                        }
                    } else {
                        $result[] = $item;
                    }
                }
                $this->objects[$objectId] = $result;

                return $result;

            case self::RT_ARRAY_SINGLE_STRING:
                $objectId = $this->readInt32();
                $length = $this->readInt32();

                $arr = [];
                for ($i = 0; $i < $length; $i++) {
                    $arr[] = $this->readValueRecord();
                }
                $this->objects[$objectId] = $arr;

                return $arr;

            case self::RT_BINARY_ARRAY:
                $objectId = $this->readInt32();
                $arrayType = $this->readByte();
                $rank = $this->readInt32();

                $lengths = [];
                for ($i = 0; $i < $rank; $i++) {
                    $lengths[] = $this->readInt32();
                }

                if ($arrayType >= 3) {
                    for ($i = 0; $i < $rank; $i++) {
                        $this->readInt32(); // lower bounds
                    }
                }

                $typeEnum = $this->readByte();
                $additionalInfo = match ($typeEnum) {
                    self::MT_PRIMITIVE, self::MT_PRIMITIVE_ARRAY => $this->readByte(),
                    self::MT_SYSTEM_CLASS => $this->readString(),
                    self::MT_CLASS => ['className' => $this->readString(), 'libraryId' => $this->readInt32()],
                    default => null,
                };

                $totalLength = array_product($lengths);
                $arr = [];
                for ($i = 0; $i < $totalLength; $i++) {
                    $arr[] = $this->readMemberValue($typeEnum, $additionalInfo);
                }

                $this->objects[$objectId] = $arr;

                return $arr;

            case self::RT_MESSAGE_END:
                return ['__end' => true];

            default:
                throw new \RuntimeException(sprintf(
                    'Unknown record type 0x%02X at position 0x%X (%d)',
                    $recordType,
                    $this->pos - 1,
                    $this->pos - 1,
                ));
        }
    }

    // ── Reference resolution ──

    private function resolveRefs(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 50) {
            return $value;
        }

        if (is_array($value)) {
            if (isset($value['__ref'])) {
                $refId = $value['__ref'];

                if (isset($this->objects[$refId])) {
                    return $this->resolveRefs($this->objects[$refId], $depth + 1);
                }

                return "unresolved_ref_{$refId}";
            }

            if (isset($value['__end'])) {
                return null;
            }

            $resolved = [];
            foreach ($value as $k => $v) {
                $resolved[$k] = $this->resolveRefs($v, $depth + 1);
            }

            return $resolved;
        }

        return $value;
    }

    // ── Match extraction and cleanup ──

    /**
     * Extract match records from the resolved root object.
     *
     * @return array<int, array>
     */
    private function extractMatches(mixed $resolved): array
    {
        $matches = [];

        if (! isset($resolved['_items']) || ! is_array($resolved['_items'])) {
            return [];
        }

        $size = $resolved['_size'] ?? count($resolved['_items']);
        $items = array_slice($resolved['_items'], 0, $size);

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            $match = $this->resolveRefs($item);

            if (! is_array($match)) {
                continue;
            }

            $cleaned = $this->cleanMatch($match);

            $matches[] = $cleaned;
        }

        return $matches;
    }

    private function cleanMatch(array $match): array
    {
        $opponents = $this->extractList($match['Opponents'] ?? null);
        $matchWinners = $this->extractList($match['MatchWinners'] ?? null);
        $matchLosers = $this->extractList($match['MatchLosers'] ?? null);
        $gameIds = $this->extractList($match['GameIds'] ?? null);

        $gameWins = $match['GameWins'] ?? null;
        if (is_array($gameWins) && isset($gameWins['_items'])) {
            $gameWins = $this->extractList($gameWins);
        }

        $gameLosses = $match['GameLosses'] ?? null;
        if (is_array($gameLosses) && isset($gameLosses['_items'])) {
            $gameLosses = $this->extractList($gameLosses);
        }

        $format = null;
        if (isset($match['GameStructure']) && is_array($match['GameStructure'])) {
            $gs = $match['GameStructure'];
            $formatVal = $gs['GameStructureCd'] ?? null;

            if (is_array($formatVal) && isset($formatVal['value__'])) {
                $format = $formatVal['value__'];
            } else {
                $format = $formatVal;
            }
        }

        return [
            'Id' => $match['Id'] ?? null,
            'StartTime' => $match['StartTime'] ?? null,
            'Opponents' => $opponents,
            'GameWins' => is_int($gameWins) ? $gameWins : 0,
            'GameLosses' => is_int($gameLosses) ? $gameLosses : 0,
            'MatchWinners' => $matchWinners,
            'MatchLosers' => $matchLosers,
            'GameIds' => $gameIds,
            'GameWinsToWinMatch' => $match['GameWinsToWinMatch'] ?? null,
            'Description' => $match['Description'] ?? null,
            'Round' => $match['Round'] ?? null,
            'Format' => $format,
        ];
    }

    /**
     * Extract items from a .NET List wrapper or return as-is if already an array.
     *
     * @return array<mixed>
     */
    private function extractList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value) && isset($value['_items'])) {
            $size = $value['_size'] ?? count($value['_items']);
            $items = array_slice($value['_items'], 0, $size);

            return array_values(array_filter($items, fn ($i) => $i !== null));
        }

        if (is_array($value) && ! isset($value['__type'])) {
            return array_values(array_filter($value, fn ($i) => $i !== null));
        }

        return [$value];
    }
}
