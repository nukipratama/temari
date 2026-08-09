<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use Illuminate\Support\Str;

/**
 * Single home for the connected-chain continuity rule and its prev_opener
 * derivation, shared by every chained narrator (post-run speech, run insight,
 * daily greeting, mascot voice, weekly + monthly recap) so the "don't repeat the
 * previous opener" instruction and the way it's computed live in one place.
 */
final class NarratorContinuity
{
    /**
     * Appended to a chained narrator's system prompt. Reads prev_narrative +
     * prev_opener (the same subject's previous chain link) to steer away from
     * repetition instead of forcing a literal callback opener.
     */
    /**
     * The continuity context keys every chained narrator carries. The single
     * source of truth for both {@see self::fields()} (which populates them) and
     * StructuredChatCaller (which strips them to retry past a content filter).
     *
     * @var list<string>
     */
    public const array CONTEXT_KEYS = ['prev_narrative', 'prev_opener'];

    public const string RULE = <<<'PROMPT'
        CONTINUITY: prev_narrative and prev_opener are your OWN previous narration
        for the same subject. Use them to AVOID repetition: don't open in a way
        similar to prev_opener, and don't repeat the same sentence or number.
        Reference the previous one ONLY when there's real progress or a change worth
        telling, and even then through content, not through a connector in the
        opener. If prev_narrative is missing, write standalone without referencing
        anything before it.
        PROMPT;

    /**
     * First few words of the previous narrative, passed alongside it so the
     * model has a concrete opener to steer away from. Null when there is no
     * previous narrative.
     */
    public static function opener(?string $narrative, int $words = 10): ?string
    {
        return $narrative === null ? null : Str::words($narrative, $words, '');
    }

    /**
     * The prev_narrative + prev_opener pair every chained narrator's context
     * array carries. Spread into the return array so the two keys and their
     * derivation stay defined in one place.
     *
     * @return array{prev_narrative: string|null, prev_opener: string|null}
     */
    public static function fields(?string $prevNarrative): array
    {
        return [
            self::CONTEXT_KEYS[0] => $prevNarrative,
            self::CONTEXT_KEYS[1] => self::opener($prevNarrative),
        ];
    }
}
