<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

/**
 * The numeric figures a narrated paragraph quotes, normalized so the same
 * quantity written two ways compares equal: "6,247.50" and "6247.5" are one
 * figure, and a unit or percent sign is not part of one.
 */
final class QuotedFigures
{
    private const string PATTERN = '/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?::\d{2})+|\d+(?:\.\d+)?/';

    /**
     * @return list<string>
     */
    public static function in(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_map(self::normalize(...), $matches[0])));
    }

    /**
     * The figures $text quotes that none of $allowed accounts for.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function outside(string $text, array $allowed): array
    {
        $permitted = [];
        foreach ($allowed as $entry) {
            $permitted = [...$permitted, ...self::in($entry)];
        }

        return array_values(array_diff(self::in($text), $permitted));
    }

    private static function normalize(string $figure): string
    {
        if (str_contains($figure, ':')) {
            $parts = explode(':', $figure);
            $parts[0] = ltrim($parts[0], '0');

            return implode(':', [$parts[0] === '' ? '0' : $parts[0], ...array_slice($parts, 1)]);
        }

        $figure = str_replace(',', '', $figure);

        return str_contains($figure, '.') ? rtrim(rtrim($figure, '0'), '.') : $figure;
    }
}
