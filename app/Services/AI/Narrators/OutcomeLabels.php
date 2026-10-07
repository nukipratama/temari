<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Enums\IntentVerdict;

/**
 * Wording that should never reach a stored read: a verdict or field label
 * where the outcome should have been described.
 */
final class OutcomeLabels
{
    public static function labelIn(string $text): bool
    {
        $values = implode('|', array_map(
            static fn (IntentVerdict $verdict): string => str_replace('_', '[_ ]', $verdict->value),
            IntentVerdict::cases(),
        ));

        return preg_match('/\b[a-z]+_[a-z_]+\b|\b(?:intent|verdict)\W{0,3}(?:'.$values.')\b|\b(?:'.$values.')\s+(?:mark|verdict)\b/i', $text) === 1;
    }

    /** A rewrite instruction naming what leaked into $field, or null when nothing did. */
    public static function complaint(string $text, string $field): ?string
    {
        if (self::labelIn($text)) {
            return "{$field} names the outcome with a label (a field or verdict name) instead of describing it. "
                ."Rewrite {$field} so it says what the run did in plain words, keeping the same meaning, and change nothing else.";
        }

        return null;
    }
}
