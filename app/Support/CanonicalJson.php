<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical JSON exists so two devices running the same builder produce
 * byte-identical output for the same logical match, which is what makes the
 * hash an identity. Maps are recursively key-sorted; lists keep their order,
 * because element order in a list is content.
 *
 * Duplicated in the API repo by design (about thirty lines, a shared package
 * would cost more than the duplication). The server never verifies
 * canonicality; it only compares hashes it computed.
 */
class CanonicalJson
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return json_encode(
            self::sortRecursively($data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function hash(array $data): string
    {
        return hash('sha256', self::encode($data));
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortRecursively(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
