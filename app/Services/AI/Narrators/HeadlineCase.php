<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Enums\Rarity;

final class HeadlineCase
{
    private const array NAMES = [
        'Strava',
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
        'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /** @param list<string> $names */
    public static function lower(string $text, array $names = []): string
    {
        $parts = preg_split('/(?<=[.!?])(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return self::lowerFirst($text, $names);
        }

        return implode('', array_map(
            fn (string $part): string => trim($part) === '' ? $part : self::lowerFirst($part, $names),
            $parts,
        ));
    }

    /** @param list<string> $names */
    private static function lowerFirst(string $text, array $names): string
    {
        if (preg_match('/^(\s*)(\S+)(.*)$/su', $text, $match) !== 1) {
            return $text;
        }

        [, $lead, $token, $rest] = $match;

        if (preg_match("/^I(['’]\\w+)?[^\\p{L}\\p{N}]*$/u", $token) === 1) {
            return $lead.'i'.mb_substr($token, 1).$rest;
        }

        preg_match('/^\p{Lu}[\p{L}\p{N}]*/u', $token, $word);
        $word = $word[0] ?? '';

        if ($word === ''
            || preg_match('/\p{N}|\p{Lu}/u', mb_substr($word, 1)) === 1
            || array_any([...self::NAMES, ...$names], fn (string $name): bool => $name !== ''
                && preg_match('/^'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $token.$rest) === 1)
            || Rarity::tryFrom(mb_strtolower($word))?->label() === $word) {
            return $text;
        }

        return $lead.mb_strtolower(mb_substr($token, 0, 1)).mb_substr($token, 1).$rest;
    }

    /** @param list<string> $names */
    public static function oneLiner(string $text, array $names = []): string
    {
        return preg_match('/[.!?]\s+\S/u', trim($text)) === 1 ? $text : self::lower($text, $names);
    }
}
