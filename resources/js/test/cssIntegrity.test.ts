import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Guards `resources/css/app.css` against the failure mode that shipped in #395:
 * a doc comment closed early, leaving prose and a second terminator stranded in rule
 * position. The CSS parser swallowed the stray text *and the whole `.pressable`
 * declaration block that followed it*, then recovered at the next rule — so the
 * build succeeded, no tool warned, and every tappable control in the app lost
 * its press feedback in production.
 *
 * Nothing in the existing pipeline would have caught it: the source diff looked
 * fine, and CI runs vitest but never a bundle build, so no one was ever looking
 * at the emitted CSS. A source-level scan is therefore the only guard that
 * actually runs on every push.
 */
const CSS_PATH = path.resolve(__dirname, '../../css/app.css');
const css = readFileSync(CSS_PATH, 'utf8');

interface CommentScan {
    /** 1-based lines carrying a terminator that closed nothing. */
    strayTerminators: number[];
    /** True when the file ends mid-comment. */
    unterminated: boolean;
}

/**
 * Walks the stylesheet tracking comment state and reports terminators that
 * close nothing. Simply counting `/*` against the closing delimiter does not
 * work here, for two independent reasons: the `@source` globs at the top of the
 * file embed both sequences inside string literals, and the #395 bug had
 * *balanced* counts anyway — it is the position of the extra terminator, not
 * the tally, that destroys the following rule. So the scan skips quoted strings
 * and reports by position.
 */
function scanComments(source: string): CommentScan {
    const strayTerminators: number[] = [];
    let inComment = false;
    let quote: string | null = null;
    let line = 1;

    for (let i = 0; i < source.length; i++) {
        const char = source[i];
        if (char === '\n') {
            line++;
            continue;
        }

        if (quote !== null) {
            if (char === '\\') {
                i++;
            } else if (char === quote) {
                quote = null;
            }
            continue;
        }

        if (!inComment && (char === "'" || char === '"')) {
            quote = char;
            continue;
        }

        const pair = source.slice(i, i + 2);
        if (!inComment && pair === '/*') {
            inComment = true;
            i++;
        } else if (inComment && pair === '*/') {
            inComment = false;
            i++;
        } else if (!inComment && pair === '*/') {
            strayTerminators.push(line);
            i++;
        }
    }

    return { strayTerminators, unterminated: inComment };
}

describe('app.css integrity', () => {
    it('has no comment terminator that closes nothing', () => {
        expect(scanComments(css).strayTerminators).toEqual([]);
    });

    it('leaves no comment unterminated at end of file', () => {
        expect(scanComments(css).unterminated).toBe(false);
    });

    // The rule the stray terminator destroyed.
    //
    // Every `.pressable { … }` block is collected rather than the first,
    // because the selector legitimately appears twice: once ending the
    // `user-select` grouping near the top, and once as the press-feedback rule
    // itself. A first-match assertion finds the grouping and passes happily
    // while the rule that matters is gone — which is exactly the state prod was
    // in. Asserting by content also means gutting the declarations, not just
    // deleting the selector, fails here. `touch-manipulation` is the
    // load-bearing half: without it every control keeps the ~300ms
    // double-tap-zoom wait.
    it('still declares the press-feedback rule', () => {
        const blocks = [...css.matchAll(/\.pressable\s*\{[^}]*\}/g)].map((m) => m[0]);
        expect(blocks.length).toBeGreaterThan(0);
        expect(
            blocks.some((b) => b.includes('touch-manipulation') && b.includes('active:opacity-70')),
        ).toBe(true);
    });
});
