<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Em-dash / en-dash regression guard for the LLM prompt + persona surface.
 *
 * Em-dashes (—) and en-dashes (–) in Indonesian copy read as an AI/translation
 * tell (see CLAUDE.md voice rules + feedback_no_em_dash). The whole prompt
 * surface under app/Services/AI is currently clean; this keeps it that way.
 *
 * Only *string literals* are inspected (via PhpToken), so doc comments and
 * inline comments that legitimately discuss the rule are ignored. In the
 * `structure` group so it runs in the fast pre-coverage gate.
 *
 * Two narrow, conscious exceptions:
 *   1. TemariPersona's persona prompt contains the literal instruction
 *      "JANGAN em dash (—) atau en dash (–)" — telling the model not to use
 *      them. That line MUST keep the glyphs to be meaningful.
 *   2. The '—' glyph used as a null placeholder in data display (not prose) is
 *      allowed by the skill. A bare single-glyph literal counts as a placeholder.
 */
it('has no em-dash or en-dash in any AI prompt/persona/context string literal', function (): void {
    $emDash = "\u{2014}";
    $enDash = "\u{2013}";

    /** @var array<int, string> $offenders */
    $offenders = [];

    foreach (File::allFiles(app_path('Services/AI')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getRealPath());
        $tokens = PhpToken::tokenize(File::get($file->getRealPath()));

        foreach ($tokens as $token) {
            if (! $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML])) {
                continue;
            }

            $text = $token->text;

            if (! str_contains($text, $emDash) && ! str_contains($text, $enDash)) {
                continue;
            }

            // Exception 2: a bare null-placeholder glyph, e.g. '—' / "—".
            $unquoted = trim($text, "'\"");
            if ($unquoted === $emDash || $unquoted === $enDash) {
                continue;
            }

            // Exception 1: the persona's deliberate "JANGAN em dash" instruction.
            if (str_contains($text, 'JANGAN em dash')) {
                continue;
            }

            $offenders[] = $relative.':'.$token->line;
        }
    }

    expect($offenders)->toBe(
        [],
        "These AI prompt/persona/context string literals contain an em-dash or en-dash. Use comma, period, colon, or parentheses instead:\n  ".implode("\n  ", $offenders),
    );
})->group('structure');
