import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    AthleteRow,
    Budget,
    ContentFilterSummary,
} from '@/pages/Narration/types';

import { formMock } from '@/test/setup';

import FaultStrip from './FaultStrip';

const BUDGET: Budget = {
    todayCost: 1,
    dailyCeiling: 3,
    perUserCeiling: 1,
    totalCeiling: 5,
    athletes: 3,
    currency: 'USD',
    trippedAt: null,
    degradedFills: 0,
};

const CONTENT_FILTER: ContentFilterSummary = { trips: 0, pct: null };

const ATHLETE: AthleteRow = {
    user_id: 7,
    user_name: 'Nuki',
    is_demo: false,
    deleted: false,
    today: 1,
    last7: 4,
    last30: 10,
    calls: 42,
    ceiling: 1,
    ceiling_overridden: false,
    capped: false,
    sparkline: [],
    served: {
        llm: 0,
        rule_based: 0,
        unknown: 0,
        reasons: {
            demo: 0,
            capped: 0,
            return: 0,
            dead_letter: 0,
            content_filter: 0,
            unattributed: 0,
        },
    },
    flags: 0,
    dead_lettered: 0,
};

function renderStrip(
    overrides: Partial<Parameters<typeof FaultStrip>[0]> = {},
) {
    return render(
        <FaultStrip
            pauseReason={null}
            budget={BUDGET}
            cappedToday={0}
            athletes={[ATHLETE]}
            contentFilter={CONTENT_FILTER}
            {...overrides}
        />,
    );
}

describe('FaultStrip', () => {
    it('collapses to a single quiet line when nothing is wrong', () => {
        renderStrip();

        expect(screen.getByText(/nothing on fire/)).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('surfaces a pause with the recover-all action', () => {
        renderStrip({ pauseReason: 'kill_switch' });

        expect(screen.getByText('generation paused')).toBeInTheDocument();
        expect(screen.getByText('kill switch off')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'recover all' }),
        ).toBeInTheDocument();
    });

    it('shows an unmapped pause reason as-is rather than hiding it', () => {
        renderStrip({ pauseReason: 'something_new' });

        expect(screen.getByText('something_new')).toBeInTheDocument();
    });

    it('names the app-wide ceiling trip time and how much degraded since', () => {
        renderStrip({
            budget: {
                ...BUDGET,
                trippedAt: '2026-09-17T14:22:00+07:00',
                degradedFills: 41,
            },
        });

        expect(
            screen.getByText('app-wide ceiling tripped'),
        ).toBeInTheDocument();
        expect(screen.getByText('14:22 today')).toBeInTheDocument();
        expect(
            screen.getByText('41 blocks served rule-based since.'),
        ).toBeInTheDocument();
    });

    it('names every athlete capped today', () => {
        renderStrip({
            cappedToday: 1,
            athletes: [{ ...ATHLETE, capped: true }],
        });

        expect(screen.getByText('1 athlete capped today')).toBeInTheDocument();
        expect(screen.getByText('Nuki')).toBeInTheDocument();
    });

    it('posts a per-athlete retry for every athlete with a dead-lettered block', () => {
        renderStrip({
            athletes: [
                { ...ATHLETE, dead_lettered: 2 },
                { ...ATHLETE, user_id: 8, user_name: 'Rani', dead_lettered: 1 },
            ],
        });

        expect(screen.getByText('3 dead-lettered blocks')).toBeInTheDocument();
        expect(screen.getByText('Nuki 2 · Rani 1')).toBeInTheDocument();

        const buttons = screen.getAllByRole('button', { name: 'retry failed' });
        expect(buttons).toHaveLength(2);

        fireEvent.click(buttons[0]);
        expect(formMock.post).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7/retry-failed',
            expect.anything(),
        );
    });

    it('names the content-filter trip rate', () => {
        renderStrip({ contentFilter: { trips: 7, pct: 0.4 } });

        expect(screen.getByText('7 content-filter trips')).toBeInTheDocument();
        expect(screen.getByText('0.4% of calls in range')).toBeInTheDocument();
    });

    it('stacks every open fault at once', () => {
        renderStrip({
            pauseReason: 'config',
            budget: {
                ...BUDGET,
                trippedAt: '2026-09-17T14:22:00+07:00',
                degradedFills: 41,
            },
            cappedToday: 1,
            athletes: [{ ...ATHLETE, capped: true, dead_lettered: 1 }],
            contentFilter: { trips: 3, pct: 1 },
        });

        expect(screen.getByText('5 things need you')).toBeInTheDocument();
    });
});
