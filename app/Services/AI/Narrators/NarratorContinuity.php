<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

/**
 * Single home for the connected-chain continuity rule and its prev_opener
 * derivation, shared by every chained narrator (post-run speech, daily
 * greeting, mascot voice, weekly + monthly recap) so the "don't repeat the
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
    public const array CONTEXT_KEYS = ['prev_narrative', 'prev_opener', 'recent_openers'];

    public const int WINDOW = 5;

    public const string RULE = <<<'PROMPT'
        CONTINUITY: prev_narrative and prev_opener are your OWN previous narration
        for the same subject. Use them to AVOID repetition: don't open in a way
        similar to prev_opener, and don't repeat the same sentence or number.
        Reference the previous one ONLY when there's real progress or a change worth
        telling, and even then through content, not through a connector in the
        opener. If prev_narrative is missing, write standalone without referencing
        anything before it. recent_openers is how your last few narrations for this
        subject opened (headline or first words): open differently from all of them
        and take a different angle from the one they show, unless the data really is
        the same story.
        PROMPT;

    /**
     * The prev_narrative, prev_opener and recent_openers every chained narrator's context array
     * carries, from the subject's previous same-kind Done contents (raw stored strings, newest
     * first). Spread into the return array so the keys and their derivation stay defined in one
     * place.
     *
     * @param  list<string>  $previousContents
     * @return array{prev_narrative: string|null, prev_opener: string|null, recent_openers: list<string>}
     */
    public static function fields(array $previousContents): array
    {
        $narrations = array_values(array_filter(array_map(
            NarrationContent::fromStored(...),
            array_slice($previousContents, 0, self::WINDOW),
        )));
        $newest = $narrations[0] ?? null;

        return [
            self::CONTEXT_KEYS[0] => $newest?->plainText(),
            self::CONTEXT_KEYS[1] => $newest?->opener(),
            self::CONTEXT_KEYS[2] => array_map(fn (NarrationContent $narration): string => $narration->opener(), $narrations),
        ];
    }
}
