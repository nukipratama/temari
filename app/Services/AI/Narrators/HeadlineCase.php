<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

final class HeadlineCase
{
    private const array NAMES = ['Strava'];

    public static function lower(string $text): string
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
            || in_array($word, self::NAMES, true)) {
            return $text;
        }

        return $lead.mb_strtolower(mb_substr($token, 0, 1)).mb_substr($token, 1).$rest;
    }
}
