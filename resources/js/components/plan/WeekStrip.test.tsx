import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanDay } from '@/lib/plan';

import WeekStrip from './WeekStrip';

const TODAY = '2026-06-17';

function day(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-15',
        phase: 'base',
        session_type: 'easy',
        segments: [],
        distance_km: 6,
        asked_km: 6,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        prescription_reason: null,
        clamp: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        hot_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        credited_km: null,
        activities: [],
        ...overrides,
    };
}

/** Mon done, Tue missed, Wed today, Thu rest, Fri tempo, Sat skipped, Sun long. */
const WEEK: PlanDay[] = [
    day({
        id: 1,
        date: '2026-06-15',
        status: 'done',
        actual_km: 6.4,
        prescribed_km: 6,
    }),
    day({ id: 2, date: '2026-06-16', status: 'missed', prescribed_km: 7 }),
    day({ id: 3, date: '2026-06-17', session_type: 'easy', distance_km: 5 }),
    day({
        id: 4,
        date: '2026-06-18',
        session_type: 'rest',
        distance_km: 0,
    }),
    day({ id: 5, date: '2026-06-19', session_type: 'tempo', distance_km: 8 }),
    day({ id: 6, date: '2026-06-20', skipped: true }),
    day({ id: 7, date: '2026-06-21', session_type: 'long', distance_km: 14 }),
];

function renderStrip(overrides: Partial<Parameters<typeof WeekStrip>[0]> = {}) {
    const onSelect = vi.fn();
    render(
        <WeekStrip
            days={WEEK}
            today={TODAY}
            selectedDate={TODAY}
            onSelect={onSelect}
            tabId={(date) => `tab-${date}`}
            panelId="panel"
            {...overrides}
        />,
    );
    return { onSelect, tabs: screen.getAllByRole('tab') };
}

describe('WeekStrip', () => {
    it('draws seven tiles, Monday to Sunday, each with its day, km and one word', () => {
        const { tabs } = renderStrip();

        expect(tabs.map((tab) => tab.getAttribute('aria-label'))).toEqual([
            'Mon, 6.4 km, done',
            'Tue, 7.0 km, missed',
            'Wed, 5.0 km, easy, today',
            'Thu, rest',
            'Fri, 8.0 km, tempo',
            'Sat, 6.0 km, skipped',
            'Sun, 14.0 km, long',
        ]);
        expect(tabs[3]).toHaveTextContent('—');
    });

    it('marks today, a missed day, a done day, rest and a planned day apart', () => {
        const { tabs } = renderStrip();

        expect(tabs.map((tab) => tab.dataset.state)).toEqual([
            'done',
            'missed',
            'today',
            'rest',
            'planned',
            'rest',
            'planned',
        ]);
    });

    it('reads an honoured rest day as rest and a rest day run anyway as done', () => {
        const { tabs } = renderStrip({
            days: [
                day({
                    date: '2026-06-15',
                    session_type: 'rest',
                    status: 'done',
                }),
                day({
                    date: '2026-06-16',
                    session_type: 'rest',
                    status: 'done',
                    ran_anyway: true,
                    actual_km: 3,
                }),
            ],
        });

        expect(tabs[0]).toHaveAttribute('data-state', 'rest');
        expect(tabs[0]).toHaveAttribute('aria-label', 'Mon, rest');
        expect(tabs[1]).toHaveAttribute('data-state', 'done');
        expect(tabs[1]).toHaveAttribute('aria-label', 'Tue, 3.0 km, done');
    });

    it('names a short run partial', () => {
        const { tabs } = renderStrip({
            days: [day({ status: 'partial', actual_km: 4, prescribed_km: 6 })],
        });

        expect(tabs[0]).toHaveAttribute('aria-label', 'Mon, 4.0 km, partial');
        expect(tabs[0]).toHaveTextContent('short');
    });

    it('exposes the selection as one selected tab controlling the panel', () => {
        const { tabs } = renderStrip({ selectedDate: '2026-06-19' });

        expect(screen.getByRole('tablist')).toBeInTheDocument();
        expect(tabs[4]).toHaveAttribute('aria-selected', 'true');
        expect(tabs[4]).toHaveAttribute('tabindex', '0');
        expect(tabs[4]).toHaveAttribute('id', 'tab-2026-06-19');
        expect(tabs[2]).toHaveAttribute('aria-selected', 'false');
        expect(tabs[2]).toHaveAttribute('tabindex', '-1');
        tabs.forEach((tab) =>
            expect(tab).toHaveAttribute('aria-controls', 'panel'),
        );
    });

    it('selects a tile on click', () => {
        const { onSelect, tabs } = renderStrip();
        fireEvent.click(tabs[4]);

        expect(onSelect).toHaveBeenCalledWith('2026-06-19');
    });

    it('moves the selection and focus with the arrow keys, wrapping at the ends', () => {
        const { onSelect, tabs } = renderStrip();

        fireEvent.keyDown(tabs[2], { key: 'ArrowRight' });
        expect(onSelect).toHaveBeenLastCalledWith('2026-06-18');
        expect(tabs[3]).toHaveFocus();

        fireEvent.keyDown(tabs[0], { key: 'ArrowLeft' });
        expect(onSelect).toHaveBeenLastCalledWith('2026-06-21');
        expect(tabs[6]).toHaveFocus();
    });

    it('jumps to the first and last day on Home and End', () => {
        const { onSelect, tabs } = renderStrip();

        fireEvent.keyDown(tabs[2], { key: 'End' });
        expect(onSelect).toHaveBeenLastCalledWith('2026-06-21');

        fireEvent.keyDown(tabs[2], { key: 'Home' });
        expect(onSelect).toHaveBeenLastCalledWith('2026-06-15');
    });

    it('ignores any other key', () => {
        const { onSelect, tabs } = renderStrip();
        fireEvent.keyDown(tabs[2], { key: 'a' });

        expect(onSelect).not.toHaveBeenCalled();
    });

    it('shows short forms of the long words on the tile, the full word in its label', () => {
        const { tabs } = renderStrip({
            days: [
                day({ session_type: 'interval', status: 'planned' }),
                day({ id: 2, date: '2026-06-16', skipped: true }),
            ],
        });

        expect(tabs[0]).toHaveTextContent('reps');
        expect(tabs[0]).toHaveAttribute(
            'aria-label',
            expect.stringContaining('interval'),
        );
        expect(tabs[1]).toHaveTextContent('skip');
        expect(tabs[1]).toHaveAttribute(
            'aria-label',
            expect.stringContaining('skipped'),
        );
    });
});
