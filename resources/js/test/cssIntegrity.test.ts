import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Guards `resources/css/app.css` against a doc comment closing early, which
 * strands prose and a second terminator in rule position: the CSS parser then
 * swallows the stray text and the whole following declaration block, the build
 * still succeeds, and no tool warns. A source-level scan is the only guard that
 * runs on every push, since CI never builds the bundle.
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
 * Walks the stylesheet tracking comment state and reports terminators that close
 * nothing. Counting the opening delimiter against the closing one does not work:
 * the `@source` globs at the top of the file embed both sequences inside string
 * literals, and a stray terminator's position, not the tally, is what destroys
 * the following rule. So the scan skips quoted strings and reports by position.
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

    // Every `.pressable { … }` block is collected rather than the first, because
    // the selector also appears ending the `user-select` grouping, and a
    // first-match assertion would pass while the press-feedback rule is gone.
    // `touch-manipulation` is the load-bearing half: without it every control
    // keeps the ~300ms double-tap-zoom wait.
    it('keeps a popup trigger still without cancelling opacity feedback', () => {
        // The popup is anchored to this element, so pressing it must not move
        // it — but the base opacity dip remains as feedback.
        const block = css.match(
            /\.pressable\[aria-haspopup\]\s*\{[^}]*\}/,
        )?.[0];

        expect(block).toBeDefined();
        expect(block).not.toContain('active:opacity-100');
        expect(block).toContain('active:scale-100');
    });

    it('keeps the popover keyframes off the x axis so they cannot double the centring offset', () => {
        const keyframes = [
            ...css.matchAll(
                /@keyframes (popover-reveal(?:-out)?)\s*\{([\s\S]*?)\n\}/g,
            ),
        ];

        expect(keyframes.map((m) => m[1]).sort()).toEqual([
            'popover-reveal',
            'popover-reveal-out',
        ]);
        keyframes.forEach(([, , body]) => {
            expect(body).not.toMatch(/translate(?:X|3d)?\(|translate:/);
        });
    });

    it('defines the wide page column in rem so it scales with the type step', () => {
        expect(css).toMatch(/--container-column-wide:\s*54rem;/);
    });

    it('still declares the press-feedback rule', () => {
        const blocks = [...css.matchAll(/\.pressable\s*\{[^}]*\}/g)].map(
            (m) => m[0],
        );
        expect(blocks.length).toBeGreaterThan(0);
        expect(
            blocks.some(
                (b) =>
                    b.includes('touch-manipulation') &&
                    b.includes('active:opacity-70'),
            ),
        ).toBe(true);
    });
});
