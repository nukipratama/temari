<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Services\AI\Narrators\OutcomeLabels;

final class NarrationEvalChecks
{
    private const float NUMBER_TOLERANCE = 0.05;

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array{required?: list<string>, forbidden?: list<string>}  $direction
     * @return array{outcome_labels: ?string, raw_enum: ?string, markdown: ?string, numbers: ?string, direction: ?string}
     */
    public static function run(string $text, array $evidence, array $direction): array
    {
        return [
            'outcome_labels' => OutcomeLabels::complaint($text, 'the text'),
            'raw_enum' => self::rawEnum($text),
            'markdown' => self::markdown($text),
            'numbers' => self::numbers($text, $evidence),
            'direction' => self::direction($text, $direction),
        ];
    }

    private static function rawEnum(string $text): ?string
    {
        if (preg_match('/\b[a-z]+(?:_[a-z0-9]+)+\b/', $text, $match) !== 1) {
            return null;
        }

        return "snake_case token \"{$match[0]}\"";
    }

    private static function markdown(string $text): ?string
    {
        if (preg_match('/`|^\s*#{1,6}\s|^\s*[-+]\s/m', $text, $match) !== 1) {
            return null;
        }

        return 'markdown syntax "'.trim($match[0]).'"';
    }

    /** @param  array<string, mixed>  $evidence */
    private static function numbers(string $text, array $evidence): ?string
    {
        $held = self::tokens((string) json_encode($evidence, JSON_UNESCAPED_UNICODE));
        $outside = [];

        foreach (self::tokens($text) as $token) {
            if (! self::held($token, $held)) {
                $outside[] = $token;
            }
        }

        return $outside === [] ? null : 'not in the evidence: '.implode(', ', array_unique($outside));
    }

    /**
     * @param  array{required?: list<string>, forbidden?: list<string>}  $direction
     */
    private static function direction(string $text, array $direction): ?string
    {
        foreach ($direction['forbidden'] ?? [] as $pattern) {
            if (preg_match('~'.$pattern.'~iu', $text, $match) === 1) {
                return "says \"{$match[0]}\", which the known answer rules out";
            }
        }

        $required = $direction['required'] ?? [];
        if ($required === []) {
            return null;
        }

        foreach ($required as $pattern) {
            if (preg_match('~'.$pattern.'~iu', $text) === 1) {
                return null;
            }
        }

        return 'none of the expected wording appears: '.implode(' | ', $required);
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        preg_match_all('/\d+(?:[.:]\d+)*/', $text, $matches);

        return $matches[0];
    }

    /** @param  list<string>  $held */
    private static function held(string $token, array $held): bool
    {
        if (in_array($token, $held, true)) {
            return true;
        }

        if (str_contains($token, ':') || substr_count($token, '.') > 1) {
            return false;
        }

        $value = (float) $token;
        $isWhole = ! str_contains($token, '.');

        foreach ($held as $candidate) {
            if (str_contains($candidate, ':') || substr_count($candidate, '.') > 1) {
                continue;
            }
            $other = (float) $candidate;
            if (abs($value - $other) < self::NUMBER_TOLERANCE || ($isWhole && (float) round($other) === $value)) {
                return true;
            }
        }

        return false;
    }
}
