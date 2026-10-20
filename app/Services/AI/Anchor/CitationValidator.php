<?php

declare(strict_types=1);

namespace App\Services\AI\Anchor;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the citations a narrator emitted that actually resolve, and unwraps the rest back to plain
 * prose.
 *
 * A citation is the markdown inline-link form `[text](anchor)`. Model output is untrusted twice
 * over (an anchor the subject does not have, several spans where the voice allows one); both
 * degrade to the words themselves, so the row stays done.
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
