import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import type { NarrationOverviewProps } from './types';

import Overview from './Overview';

const baseProps: NarrationOverviewProps = {
    range: 'custom',
    from: '2026-05-01',
    to: '2026-05-19',
    kind: null,
    origin: null,
    athlete: null,
    totals: {
        prompt: 1000,
        completion: 400,
        total: 1400,
        calls: 6,
        cost: 0.42,
        truncated_calls: 0,
    },
    previousTotals: null,
    byKind: [
        {
            kind: 'run-insight',
            prompt: 700,
            completion: 300,
            total: 1000,
            calls: 4,
            cost: 0.3,
            truncated_calls: 0,
            avg_latency_ms: 1200,
            max_latency_ms: 2400,
            avg_steps: 2,
            cached_pct: 40,
            reasoning_pct: 10,
        },
    ],
    byDeployment: [
        {
            deployment: 'nuki-mini',
            prompt: 1000,
            completion: 400,
            total: 1400,
            calls: 6,
            cost: 0.42,
            inputPer1m: 0.15,
            outputPer1m: 0.6,
        },
    ],
    byOrigin: [
        {
            origin: 'ingest',
            label: 'Ingest cascade',
            prompt: 1000,
            completion: 400,
            total: 1400,
            calls: 6,
            cost: 0.42,
        },
    ],
    availableKinds: [{ value: 'run-insight', label: 'RunInsight' }],
    availableOrigins: [{ value: 'ingest', label: 'Ingest cascade' }],
    budget: {
        todayCost: 0.42,
        dailyCeiling: 3,
        perUserCeiling: 1,
        totalCeiling: 5,
        athletes: 3,
        currency: 'USD',
        trippedAt: null,
        degradedFills: 0,
    },
    contentFilter: { trips: 0, pct: 0 },
    chart: {
        kinds: [{ kind: 'run-insight', label: 'RunInsight', cost: 0.42 }],
        days: [
            {
                day: '2026-05-18',
                cost: 0.42,
                byKind: { 'run-insight': 0.42 },
            },
        ],
    },
    athletes: [
        {
            user_id: 7,
            user_name: 'Alice',
            is_demo: false,
            deleted: false,
            today: 0.42,
            last7: 0.42,
            last30: 0.42,
            calls: 6,
            ceiling: 1,
            ceiling_overridden: false,
            capped: false,
            sparkline: [{ day: '2026-05-18', cost: 0.42 }],
            served: { llm: 5, rule_based: 1, unknown: 0 },
            flags: 0,
            dead_lettered: 0,
        },
    ],
    cappedToday: 0,
    pauseReason: null,
};

describe('Narration overview page', () => {
    it('is the page module app.tsx resolves for the name "Narration/Overview"', async () => {
        const pages = import.meta.glob('./**/*.tsx');
        const importer = pages['./Overview.tsx'];

        expect(importer).toBeTypeOf('function');
        const module = (await importer()) as { default: unknown };
        expect(module.default).toBe(Overview);
    });

    it('renders the devtools header', () => {
        render(<Overview {...baseProps} />);

        expect(screen.getByText('narration')).toBeInTheDocument();
        expect(
            screen.getByText('What the narration pipeline costs, per athlete.'),
        ).toBeInTheDocument();
    });

    it('opens on the overview tab: ceiling strip, KPIs, cost chart and athletes', () => {
        render(<Overview {...baseProps} />);

        expect(screen.getByText('today, app-wide')).toBeInTheDocument();
        expect(screen.getByText('daily cost')).toBeInTheDocument();
        expect(screen.getByText('athletes')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Alice' })).toBeInTheDocument();
    });

    it('keeps the kind, deployment and origin tables off the overview tab', () => {
        render(<Overview {...baseProps} />);

        expect(screen.queryByText('by kind')).not.toBeInTheDocument();
        expect(screen.queryByText('by deployment')).not.toBeInTheDocument();
        expect(screen.queryByText('by origin')).not.toBeInTheDocument();
    });

    it('swaps to the breakdown tables on the breakdown tab', () => {
        render(<Overview {...baseProps} />);

        fireEvent.click(screen.getByRole('tab', { name: 'breakdown' }));

        expect(screen.getByText('by kind')).toBeInTheDocument();
        expect(screen.getByText('by deployment')).toBeInTheDocument();
        expect(screen.getByText('by origin')).toBeInTheDocument();
        expect(screen.queryByText('daily cost')).not.toBeInTheDocument();
    });

    it('keeps the range filters visible on both tabs', () => {
        render(<Overview {...baseProps} />);

        fireEvent.click(screen.getByRole('tab', { name: 'breakdown' }));

        expect(screen.getByText('2026-05-01')).toBeInTheDocument();
    });

    it('renders the flash info banner when present', () => {
        setMockPage({ flash: { info: 'Retrying 2 blocks for Charlie.' } });
        render(<Overview {...baseProps} />);

        expect(
            screen.getByText('Retrying 2 blocks for Charlie.'),
        ).toBeInTheDocument();
    });

    it('renders no flash banner when there is nothing to confirm', () => {
        render(<Overview {...baseProps} />);

        expect(screen.queryByLabelText('Close')).not.toBeInTheDocument();
    });

    it('passes the budget currency down to every money figure', () => {
        render(
            <Overview
                {...baseProps}
                budget={{ ...baseProps.budget, currency: 'IDR' }}
            />,
        );

        expect(screen.getAllByText(/^Rp /).length).toBeGreaterThan(1);
    });
});
