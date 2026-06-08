import { describe, expect, it } from 'vitest';
import { CTA, MOOD_EMOJI } from './copy';
import { METRIC_GLOSSARY } from './metricGlossary';

/**
 * Em-dash / en-dash regression guard for shipped UI copy.
 *
 * Em-dashes (—) and en-dashes (–) read as an AI/translation tell in casual
 * Indonesian (see feedback_no_em_dash). This walks the *string values* of the
 * canonical copy modules (not their source/comments) and asserts none slip in.
 * The '—' glyph as a null placeholder in data display is a separate concern and
 * never lives in these copy constants.
 */
const EM_DASH = '—';
const EN_DASH = '–';

function collectStrings(value: unknown, acc: string[]): void {
    if (typeof value === 'string') {
        acc.push(value);
        return;
    }
    if (value && typeof value === 'object') {
        for (const v of Object.values(value)) {
            collectStrings(v, acc);
        }
    }
}

const COPY_SOURCES: Record<string, unknown> = {
    'copy.ts (CTA)': CTA,
    'copy.ts (MOOD_EMOJI)': MOOD_EMOJI,
    'metricGlossary.ts (METRIC_GLOSSARY)': METRIC_GLOSSARY,
};

describe('UI copy has no em-dash or en-dash', () => {
    for (const [label, source] of Object.entries(COPY_SOURCES)) {
        it(`${label} is free of — and –`, () => {
            const strings: string[] = [];
            collectStrings(source, strings);

            const offenders = strings.filter(
                (s) => s.includes(EM_DASH) || s.includes(EN_DASH),
            );

            expect(
                offenders,
                `These ${label} strings contain an em-dash or en-dash. Use comma, period, colon, or parentheses instead:\n  ${offenders.join('\n  ')}`,
            ).toEqual([]);
        });
    }
});
