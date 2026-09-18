import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AthleteRow } from '@/pages/Narration/types';

import AthletesPanel from './AthletesPanel';

const ROW: AthleteRow = {
    user_id: 7,
    user_name: 'Nuki',
    is_demo: false,
    deleted: false,
    today: 0.25,
    last7: 1.5,
    last30: 4.75,
    calls: 42,
    tokens: 18400,
    ceiling: 1,
    ceiling_overridden: false,
    capped: false,
    sparkline: [
        { day: '2026-09-09', cost: 0.1 },
        { day: '2026-09-10', cost: 0.25 },
    ],
    served: {
        llm: 8,
        rule_based: 2,
        unknown: 0,
        reasons: {
            demo: 0,
            capped: 0,
            return: 2,
            dead_letter: 0,
            content_filter: 0,
            unattributed: 0,
        },
    },
    flags: 0,
    dead_lettered: 0,
};

function renderPanel(overrides: Partial<AthleteRow> = {}) {
    return render(
        <AthletesPanel rows={[{ ...ROW, ...overrides }]} currency="USD" />,
    );
}

describe('AthletesPanel', () => {
    it('links the athlete to their own page', () => {
        renderPanel();

        expect(screen.getByRole('link', { name: 'Nuki' })).toHaveAttribute(
            'href',
            '/devtools/narration/athletes/7',
        );
    });

    it('shows the three money windows', () => {
        renderPanel();

        expect(screen.getByText('$1.50')).toBeInTheDocument();
        expect(screen.getByText('$4.75')).toBeInTheDocument();
    });

    it('shows the 30-day token total beside calls', () => {
        renderPanel();

        expect(screen.getByText('tokens, 30d')).toBeInTheDocument();
        expect(screen.getByText('18,400')).toBeInTheDocument();
    });

    it('reads today against the athlete own ceiling', () => {
        renderPanel();

        expect(screen.getByText(/\$0\.25/)).toBeInTheDocument();
        expect(screen.getByText(/\$1\.00/)).toBeInTheDocument();
    });

    it('marks a ceiling that an operator overrode for today', () => {
        renderPanel({ ceiling: 3, ceiling_overridden: true });

        expect(screen.getByText('override')).toBeInTheDocument();
    });

    it('says when an athlete has no ceiling at all', () => {
        renderPanel({ ceiling: null });

        expect(screen.getByText('no ceiling')).toBeInTheDocument();
    });

    it('flags a capped athlete', () => {
        renderPanel({ capped: true });

        expect(screen.getByText('capped')).toBeInTheDocument();
    });

    it('labels the demo account', () => {
        renderPanel({ is_demo: true });

        expect(screen.getByText('demo')).toBeInTheDocument();
    });

    it('falls back to an empty state with no athletes at all', () => {
        render(<AthletesPanel rows={[]} currency="USD" />);

        expect(screen.queryByText('Nuki')).not.toBeInTheDocument();
    });

    it('keeps a live athlete out of the deleted rollup', () => {
        renderPanel();

        expect(screen.queryByText(/deleted account/)).not.toBeInTheDocument();
    });

    it('collapses deleted athletes into a rollup rather than a full row', () => {
        render(
            <AthletesPanel
                rows={[
                    ROW,
                    {
                        ...ROW,
                        user_id: 9,
                        user_name: 'Arif',
                        deleted: true,
                        last30: 2.14,
                        calls: 71,
                    },
                ]}
                currency="USD"
            />,
        );

        expect(screen.getByRole('link', { name: 'Nuki' })).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Arif' }),
        ).not.toBeInTheDocument();

        const summary = screen.getByText(/1 deleted account/);
        expect(summary).toBeInTheDocument();
        expect(summary.closest('details')).not.toBeNull();
        expect(screen.getByText('Arif')).toBeInTheDocument();
        expect(screen.getByText('30d $2.14')).toBeInTheDocument();
    });

    it('sums 30-day spend across every deleted account in the rollup summary', () => {
        render(
            <AthletesPanel
                rows={[
                    {
                        ...ROW,
                        user_id: 9,
                        user_name: 'Arif',
                        deleted: true,
                        last30: 2.14,
                    },
                    {
                        ...ROW,
                        user_id: 10,
                        user_name: 'Budi',
                        deleted: true,
                        last30: 1.0,
                    },
                ]}
                currency="USD"
            />,
        );

        expect(
            screen.getByText(/2 deleted accounts · \$3\.14/),
        ).toBeInTheDocument();
    });
});
