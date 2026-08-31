import { describe, expect, it } from 'vitest';

import { CHART_GROUND, PALETTE, PHASE_COLORS, hrZone } from './chartTokens';

describe('PALETTE chart token bridge', () => {
    it('mirrors the canonical hex values from app.css @theme', () => {
        expect(PALETTE.leaf).toBe('#2f8f63');
        expect(PALETTE.ember).toBe('#b23a4f');
        expect(PALETTE.overloaded).toBe('#6b3fa0');
        expect(PALETTE.horizon).toBe('#ade047');
        expect(PALETTE.citrus).toBe('#c9971f');
        expect(PALETTE.cream).toBe('#f1f5f8');
        expect(PALETTE.ink2).toBe('#34373c');
        expect(PALETTE.ink3).toBe('#60666d');
    });

    it('exposes every value as a 6-digit lowercase hex', () => {
        for (const value of Object.values(PALETTE)) {
            expect(value).toMatch(/^#[0-9a-f]{6}$/);
        }
    });
});

describe('CHART_GROUND', () => {
    it('mirrors the light/dark text-2 and text-3 token pairs', () => {
        expect(CHART_GROUND.light.tick).toBe('#34373c'); // = text-2 on light
        expect(CHART_GROUND.dark.tick).toBe('#c1c2c8'); // = text-2 on dark
        expect(CHART_GROUND.light.secondaryLine).toBe('#60666d'); // = text-3 on light
        expect(CHART_GROUND.dark.secondaryLine).toBe('#9c9ea7'); // = text-3 on dark
    });

    it('matches the frozen prototype grid rgba on both grounds', () => {
        expect(CHART_GROUND.light.grid).toBe('rgba(22,24,27,.08)');
        expect(CHART_GROUND.dark.grid).toBe('rgba(241,245,248,.10)');
    });

    it('gives point markers a cutout ring matching the card colour per ground', () => {
        expect(CHART_GROUND.light.pointBorder).toBe('#f1f5f8'); // = card on light
        expect(CHART_GROUND.dark.pointBorder).toBe('#171f28'); // = card on dark
    });

    it('swaps the ink-safe horizonInk line for raw horizon on dark, where the darkened variant would disappear', () => {
        expect(CHART_GROUND.light.line).toBe(PALETTE.horizonInk);
        expect(CHART_GROUND.dark.line).toBe(PALETTE.horizon);
    });

    it('mirrors the light/dark border token pair for neutral marker outlines', () => {
        expect(CHART_GROUND.light.border).toBe('#bfc5cc'); // = border on light
        expect(CHART_GROUND.dark.border).toBe('#4d5560'); // = border on dark
    });
});

describe('PHASE_COLORS', () => {
    it('covers all five plan phases', () => {
        expect(Object.keys(PHASE_COLORS)).toEqual([
            'base',
            'build',
            'peak',
            'taper',
            'deload',
        ]);
    });

    it('never reuses a Mood-committed PALETTE color (overloaded/gassed/chill)', () => {
        const moodColors = new Set<string>([
            PALETTE.overloaded,
            PALETTE.gassed,
            PALETTE.chill,
        ]);
        for (const value of Object.values(PHASE_COLORS)) {
            expect(moodColors.has(value)).toBe(false);
        }
    });

    it('exposes every value as a 6-digit lowercase hex', () => {
        for (const value of Object.values(PHASE_COLORS)) {
            expect(value).toMatch(/^#[0-9a-f]{6}$/);
        }
    });
});

describe('hrZone map', () => {
    it('covers all five HR zones', () => {
        expect(Object.keys(hrZone)).toEqual(['Z1', 'Z2', 'Z3', 'Z4', 'Z5']);
    });

    it('ramps cool teal (recovery) → warm red (max) as named hex', () => {
        expect(hrZone.Z1).toBe('#35c6da'); // bright cool teal: barely working
        expect(hrZone.Z5).toBe('#b8302f'); // red: maxed
        for (const value of Object.values(hrZone)) {
            expect(value).toMatch(/^#[0-9a-f]{6}$/);
        }
    });
});
