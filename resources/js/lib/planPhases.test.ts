import { describe, expect, it } from 'vitest';

import { defined } from '@/test/defined';

import type { SeasonSummaryWeek } from './plan';

import { phaseGroupKey, phasesOf } from './planPhases';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'block',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        sessions: 5,
        ...overrides,
    };
}

const RACE_SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-06-15', phase: 'base', planned_km: 30 }),
    week({
        week_start: '2026-06-22',
        phase: 'build',
        planned_km: 40,
        type: 'current',
    }),
    week({
        week_start: '2026-06-29',
        phase: 'peak',
        planned_km: 50,
        type: 'lookahead',
    }),
];

describe('phasesOf', () => {
    it('averages each phase’s weekly volume', () => {
        const phases = phasesOf([
            week({ phase: 'base', planned_km: 30 }),
            week({ week_start: '2026-06-22', phase: 'base', planned_km: 40 }),
        ]);

        expect(phases).toEqual([{ key: 'base', avgKm: 35, state: 'done' }]);
    });

    it('marks the phase holding the current week as current', () => {
        expect(phasesOf(RACE_SEASON).map((p) => p.state)).toEqual([
            'done',
            'current',
            'upcoming',
        ]);
    });

    it('keeps a self-scaled season’s repeating build/deload cycle as two phases, not four', () => {
        const phases = phasesOf([
            week({ phase: 'build', type: 'history' }),
            week({
                week_start: '2026-06-22',
                phase: 'deload',
                type: 'current',
            }),
            week({
                week_start: '2026-06-29',
                phase: 'build',
                type: 'lookahead',
            }),
        ]);

        expect(phases.map((p) => p.key)).toEqual(['build', 'deload']);
    });

    it('merges every general-zone week into one "general" entry, ignoring its own build/deload phase', () => {
        const phases = phasesOf([
            week({ zone: 'general', phase: 'build', type: 'history' }),
            week({
                week_start: '2026-06-22',
                zone: 'general',
                phase: 'deload',
                type: 'current',
            }),
            week({
                week_start: '2026-06-29',
                zone: 'block',
                phase: 'base',
                type: 'lookahead',
            }),
        ]);

        expect(phases.map((p) => p.key)).toEqual(['general', 'base']);
        expect(defined(phases[0]).state).toBe('current');
    });

    it('puts the general entry first, ahead of the block phases it precedes', () => {
        const phases = phasesOf([
            week({ zone: 'general', phase: 'build' }),
            week({
                week_start: '2026-06-22',
                zone: 'block',
                phase: 'base',
                type: 'current',
            }),
        ]);

        expect(phases.map((p) => p.key)).toEqual(['general', 'base']);
    });
});

describe('phaseGroupKey', () => {
    it('reads a block week’s own phase', () => {
        expect(phaseGroupKey(week({ zone: 'block', phase: 'peak' }))).toBe(
            'peak',
        );
    });

    it('collapses any general-zone week to the shared "general" key, whatever its own phase', () => {
        expect(phaseGroupKey(week({ zone: 'general', phase: 'build' }))).toBe(
            'general',
        );
        expect(phaseGroupKey(week({ zone: 'general', phase: 'deload' }))).toBe(
            'general',
        );
    });
});
