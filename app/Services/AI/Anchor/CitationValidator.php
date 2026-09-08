<?php

declare(strict_types=1);

namespace App\Services\AI\Anchor;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the citations a narrator emitted that actually resolve, and unwraps the
 * rest back to plain prose.
 *
 * Narration carries a citation as the markdown inline-link form
 * `[text](anchor)`. Model output is untrusted twice over: the anchor may name
 * something the subject does not have, and the model may mark several spans
 * when the voice allows one. Both degrade to the words themselves, so a block
 * is never lost to a bad citation and the row stays done.
 */
final class CitationValidator
{
    private const string TOKEN = '/\[([^\]\n]+)\]\(([^)\s]+)\)/';

    /**
     * @param  Closure(string): bool  $resolves  Whether this anchor names
     *                                          something the subject has.
     */
    public function keepResolving(string $text, Closure $resolves, string $kind): string
    {
        $kept = false;

        return (string) preg_replace_callback(
            self::TOKEN,
            function (array $match) use ($resolves, &$kept, $kind): string {
                [$token, $words, $anchor] = $match;

                if ($kept) {
                    return $words;
                }

                if (! $resolves($anchor)) {
                    Log::info('narrator.citation.rejected', [
                        'kind' => $kind,
                        'anchor' => $anchor,
                    ]);

                    return $words;
                }

                $kept = true;

                return $token;
            },
            $text,
        );
    }
}
