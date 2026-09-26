import { describe, expect, it } from 'vitest';

import type { Effort } from '@/types/inertia';

import { EFFORT_EDGE_CLASS, EFFORT_LABEL, EFFORT_STRIPE_CLASS } from './effort';

const ALL_EFFORTS: Effort[] = ['easy', 'steady', 'hard', 'rest', 'unknown'];

describe('effort', () => {
    describe('EFFORT_LABEL', () => {
        it('labels every effort exactly once', () => {
            expect(Object.keys(EFFORT_LABEL).sort()).toEqual(
                [...ALL_EFFORTS].sort(),
            );
        });
    });

    describe('EFFORT_STRIPE_CLASS', () => {
        it('colors each effort per MASTER.md: easy leaf, steady citrus, hard ember', () => {
            expect(EFFORT_STRIPE_CLASS.easy).toContain('border-leaf');
            expect(EFFORT_STRIPE_CLASS.steady).toContain('border-citrus');
            expect(EFFORT_STRIPE_CLASS.hard).toContain('border-ember');
        });

        it('keeps rest and unknown achromatic, told apart only by border style', () => {
            expect(EFFORT_STRIPE_CLASS.rest).toContain('border-dashed');
            expect(EFFORT_STRIPE_CLASS.rest).toContain('border-border');
            expect(EFFORT_STRIPE_CLASS.unknown).toContain('border-solid');
            expect(EFFORT_STRIPE_CLASS.unknown).toContain('border-border');
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
        it('matches EFFORT_STRIPE_CLASS colors, moved to the bottom edge', () => {
            for (const effort of ALL_EFFORTS) {
                const stripe = EFFORT_STRIPE_CLASS[effort].replace(
                    'border-l-[3px]',
                    'border-b-[3px]',
                );

                expect(EFFORT_EDGE_CLASS[effort]).toBe(stripe);
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
});
