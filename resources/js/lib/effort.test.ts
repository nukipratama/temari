import { describe, expect, it } from 'vitest';

import type { Effort } from '@/types/inertia';

import {
    EFFORT_EDGE_CLASS,
    EFFORT_STRIPE_CLASS,
    sessionTypeEffort,
} from './effort';

const ALL_EFFORTS: Effort[] = ['easy', 'steady', 'hard', 'rest', 'unknown'];

describe('effort', () => {
    describe('EFFORT_STRIPE_CLASS', () => {
        it('colors each effort per MASTER.md: easy leaf, steady citrus, hard ember', () => {
            expect(EFFORT_STRIPE_CLASS.easy).toContain('border-l-leaf');
            expect(EFFORT_STRIPE_CLASS.steady).toContain('border-l-citrus');
            expect(EFFORT_STRIPE_CLASS.hard).toContain('border-l-ember');
        });

        it('colours only the left border, so a divider on the same element keeps its own colour and style', () => {
            for (const effort of ALL_EFFORTS) {
                expect(EFFORT_STRIPE_CLASS[effort]).not.toMatch(
                    /(^|\s)border-(solid|leaf|citrus|ember|border)(\s|$)/,
                );
            }
        });

        it('keeps rest and unknown achromatic, told apart only by border style', () => {
            expect(EFFORT_STRIPE_CLASS.rest).toContain('border-dashed');
            expect(EFFORT_STRIPE_CLASS.rest).toContain('border-l-border');
            expect(EFFORT_STRIPE_CLASS.unknown).not.toContain('border-dashed');
            expect(EFFORT_STRIPE_CLASS.unknown).toContain('border-l-border');
        });

        it('gives every stripe a square 3px leading edge, never a fill', () => {
            for (const effort of ALL_EFFORTS) {
                expect(EFFORT_STRIPE_CLASS[effort]).toContain('border-l-[3px]');
                expect(EFFORT_STRIPE_CLASS[effort]).not.toMatch(/\bbg-/);
                expect(EFFORT_STRIPE_CLASS[effort]).not.toMatch(/rounded/);
            }
        });
    });

    describe('EFFORT_EDGE_CLASS', () => {
        it('matches EFFORT_STRIPE_CLASS colors and dash style, moved to the bottom edge', () => {
            const colour = (classes: string) =>
                /border-(?:[lb]-)?(leaf|citrus|ember|border)\b/.exec(
                    classes,
                )?.[1];

            for (const effort of ALL_EFFORTS) {
                expect(colour(EFFORT_EDGE_CLASS[effort])).toBe(
                    colour(EFFORT_STRIPE_CLASS[effort]),
                );
                expect(
                    EFFORT_EDGE_CLASS[effort].includes('border-dashed'),
                ).toBe(EFFORT_STRIPE_CLASS[effort].includes('border-dashed'));
            }
        });

        it('gives every edge a square 3px bottom border, never a fill', () => {
            for (const effort of ALL_EFFORTS) {
                expect(EFFORT_EDGE_CLASS[effort]).toContain('border-b-[3px]');
                expect(EFFORT_EDGE_CLASS[effort]).not.toMatch(/\bbg-/);
                expect(EFFORT_EDGE_CLASS[effort]).not.toMatch(/rounded/);
            }
        });
    });

    describe('sessionTypeEffort', () => {
        it('maps each session type per MASTER.md: easy leaf, long/tempo citrus, interval/race ember, rest achromatic', () => {
            expect(sessionTypeEffort('easy')).toBe('easy');
            expect(sessionTypeEffort('long')).toBe('steady');
            expect(sessionTypeEffort('tempo')).toBe('steady');
            expect(sessionTypeEffort('interval')).toBe('hard');
            expect(sessionTypeEffort('race')).toBe('hard');
            expect(sessionTypeEffort('rest')).toBe('rest');
        });

        it('falls back to unknown for an unrecognized session type', () => {
            expect(sessionTypeEffort('something-new')).toBe('unknown');
        });
    });
});
