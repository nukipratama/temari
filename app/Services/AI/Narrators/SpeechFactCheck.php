<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Enums\IntentVerdict;

/**
 * Whether a post-run speech quotes only paces, distances and shares its tools
 * returned, and tells the day's stored verdict the same way round. See
 * `docs/decisions/the-post-run-speech-is-checked-before-it-is-saved.md`.
 */
final class SpeechFactCheck
{
    private const string PACE = '/\b\d{1,2}:\d{2}(?::\d{2})?\b/';

    private const string DISTANCE = '/\b\d+(?:\.\d+)?(?=\s?km\b)/i';

    private const string SHARE = '/\b\d+(?:\.\d+)?(?=\s?(?:%|percent\b))/i';

    private const string AGAINST_THE_ASK = ' than (?:written|planned|asked|prescribed|the plan|the day asked|the session asked|it asked|the script)\b/i';

    private const string EASIER = '/\b(?:easier|softer|gentler|slower|lighter)'.self::AGAINST_THE_ASK;

    private const string HARDER = '/\b(?:harder|faster|quicker|heavier)'.self::AGAINST_THE_ASK;

    /**
     * The corrective sentence the model is re-asked with, or null to accept.
     *
     * @param  array<string, mixed>  $facts  each tool's result, keyed by tool name
     */
    public static function complaint(string $speech, array $facts, ?IntentVerdict $verdict): ?string
    {
        $reasons = [];

        $unsourced = array_values(array_diff(self::cited($speech), self::sourced($facts)));
        if ($unsourced !== []) {
            $reasons[] = 'it quotes '.implode(', ', $unsourced).', which no tool returned';
        }

        if (self::turnsAround($speech, $verdict)) {
            $reasons[] = "it sets the run against the plan the other way round from the day's verdict (intent in get_planned_sessions)";
        }

        if ($reasons === []) {
            return null;
        }

        return 'Rewrite the speech: '.implode('; also ', $reasons).'. Quote only paces, distances and percentages '
            .'a tool returned, take how the session went from intent and intent_detail, and change nothing else.';
    }

    /** @return list<string> */
    private static function cited(string $speech): array
    {
        $figures = [];
        foreach ([self::PACE, self::DISTANCE, self::SHARE] as $pattern) {
            preg_match_all($pattern, $speech, $matches);
            foreach ($matches[0] as $match) {
                $figures = [...$figures, ...QuotedFigures::in($match)];
            }
        }

        return array_values(array_unique($figures));
    }

    /**
     * Every figure the tools returned, plus each decimal rounded to the
     * precision a sentence quotes it at.
     *
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private static function sourced(array $facts): array
    {
        $figures = QuotedFigures::in((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        foreach ($figures as $figure) {
            if (! str_contains($figure, ':')) {
                $figures[] = (string) (int) round((float) $figure);
                $figures = [...$figures, ...QuotedFigures::in((string) round((float) $figure, 1))];
            }
        }

        return array_values(array_unique($figures));
    }

    private static function turnsAround(string $speech, ?IntentVerdict $verdict): bool
    {
        $easier = preg_match(self::EASIER, $speech) === 1;
        $harder = preg_match(self::HARDER, $speech) === 1;

        return match ($verdict) {
            IntentVerdict::TooHard => $easier,
            IntentVerdict::Missed => $harder,
            IntentVerdict::Hit => $easier || $harder,
            IntentVerdict::Unknown, null => false,
        };
    }
}
