<?php

namespace App\Actions\Util;

class ExtractKeyValueBlock
{
    public static function run(string $text): array
    {
        $data = [];

        // Everything after "Receiver:" is metadata
        if (! str_contains($text, 'Receiver:')) {
            return $data;
        }

        $tail = substr($text, strpos($text, 'Receiver:'));

        foreach (preg_split('/\R/', $tail) as $line) {
            if (preg_match('/^([^=]+?)\s*=\s*(.+)$/', trim($line), $m)) {
                $data[$m[1]] = trim($m[2]);
            }
        }

        return $data;
    }
}
