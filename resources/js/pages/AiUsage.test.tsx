import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import type { AiUsageProps } from './AiUsage/types';

import AiUsage from './AiUsage';

const baseProps: AiUsageProps = {
    range: 'custom',
    from: '2026-05-01',
    to: '2026-05-19',
    kind: null,
    totals: {
        prompt: 600,
        completion: 280,
        total: 880,
        calls: 3,
        cost: 0.05,
        truncated_calls: 0,
    },
    previousTotals: {
        prompt: 500,
        completion: 200,
        total: 700,
        calls: 2,
        cost: 0.04,
    },
    byKind: [
        {
            kind: 'run-insight',
            prompt: 300,
            completion: 150,
            total: 450,
            calls: 1,
            cost: 0.03,
            truncated_calls: 0,
            avg_latency_ms: 800,
            max_latency_ms: 800,
            avg_steps: 3.5,
            cached_pct: 71.2,
            reasoning_pct: 18.4,
        },
        {
            kind: 'briefing',
            prompt: 300,
            completion: 130,
            total: 430,
            calls: 2,
            cost: 0.02,
            truncated_calls: 0,
            avg_latency_ms: 1000,
            max_latency_ms: 1200,
            avg_steps: null,
            cached_pct: null,
            reasoning_pct: null,
        },
    ],
    byUser: [
        {
            user_id: 1,
            user_name: 'Alice',
            strava_athlete_id: null,
            deleted: false,
            prompt: 500,
            completion: 230,
            total: 730,
            calls: 2,
        },
        {
            user_id: 2,
            user_name: 'Bob',
            strava_athlete_id: null,
            deleted: false,
            prompt: 100,
            completion: 50,
            total: 150,
            calls: 1,
        },
    ],
    byDeployment: [
        {
            deployment: 'nuki-mini',
            prompt: 600,
            completion: 280,
            total: 880,
            calls: 3,
            cost: 0.05,
            inputPer1m: 0.15,
            outputPer1m: 0.6,
        },
    ],
    daily: [
        {
            day: '2026-05-18',
            prompt: 300,
            completion: 150,
            total: 450,
            calls: 1,
            cost: 0.03,
        },
        {
            day: '2026-05-19',
            prompt: 300,
            completion: 130,
            total: 430,
            calls: 2,
            cost: 0.02,
        },
    ],
    availableKinds: [
        { value: 'briefing', label: 'BriefingMascotVoice' },
        { value: 'run-insight', label: 'RunInsightTechnical' },
    ],
    budget: { todayCost: 0.02, dailyCeiling: 0.1, currency: 'USD' },
    deadLettered: [],
    failedUnderBudget: [],
    nyangkut: [],
};

const deadLetteredGroup = {
    user_id: 7,
    user_name: 'Charlie',
    count: 2,
    blocks: [
        {
            type: 'weekly_recap',
            error: 'Azure down',
            failed_at: '2026-05-19T10:00:00+00:00',
        },
        {
            type: 'pr_context',
            error: null,
            failed_at: '2026-05-19T09:00:00+00:00',
        },
    ],
};

describe('AiUsage page', () => {
    it('is the page module app.tsx resolves for the name "AiUsage", not a directory beside it', async () => {
        const pages = import.meta.glob('./**/*.tsx');
        const importer = pages['./AiUsage.tsx'];

        expect(importer).toBeTypeOf('function');
        const module = (await importer()) as { default: unknown };
        expect(module.default).toBe(AiUsage);
        expect(pages['./AiUsage/index.tsx']).toBeUndefined();
    });

    it('renders the devtools header', () => {
        render(<AiUsage {...baseProps} />);

        expect(screen.getByText('AI Usage')).toBeInTheDocument();
        expect(
            screen.getByText('Azure OpenAI token consumption per date range.'),
        ).toBeInTheDocument();
    });

    it('composes the filters, KPI tiles, budget gauge and all three breakdown tables', () => {
        render(<AiUsage {...baseProps} />);

        expect(screen.getByText('2026-05-01')).toBeInTheDocument();
        expect(screen.getByText("Today's Budget")).toBeInTheDocument();
        expect(
            screen.getByText('Breakdown per Deployment'),
        ).toBeInTheDocument();
        expect(screen.getByText('Breakdown per Kind')).toBeInTheDocument();
        expect(screen.getByText('Breakdown per User')).toBeInTheDocument();
        expect(screen.getByText('nuki-mini')).toBeInTheDocument();
        expect(screen.getByText('run-insight')).toBeInTheDocument();
        expect(screen.getByText('Alice')).toBeInTheDocument();
    });

    it('feeds both share-bearing tables the same grand total', () => {
        render(<AiUsage {...baseProps} />);

        expect(
            screen.getByRole('progressbar', { name: '51.1% of total' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('progressbar', { name: '83.0% of total' }),
        ).toBeInTheDocument();
    });

    it('renders the daily chart section when the window has days', () => {
        render(<AiUsage {...baseProps} />);

        expect(screen.getByText('Daily Consumption')).toBeInTheDocument();
        expect(screen.getByText('2 days')).toBeInTheDocument();
    });

    it('drops the daily chart section entirely when there is nothing to plot', () => {
        render(<AiUsage {...baseProps} daily={[]} />);

        expect(screen.queryByText('Daily Consumption')).not.toBeInTheDocument();
    });

    it('renders the flash info banner when present', () => {
        setMockPage({ flash: { info: 'Mencoba ulang 2 blok untuk Charlie.' } });
        render(<AiUsage {...baseProps} />);

        expect(
            screen.getByText('Mencoba ulang 2 blok untuk Charlie.'),
        ).toBeInTheDocument();
    });

    it('renders no flash banner when there is nothing to confirm', () => {
        render(<AiUsage {...baseProps} />);

        expect(screen.queryByLabelText('Tutup')).not.toBeInTheDocument();
    });

    it('hides the attention area when nothing is stuck', () => {
        render(<AiUsage {...baseProps} />);

        expect(screen.queryByText('Needs attention')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Pulihkan semua/ }),
        ).not.toBeInTheDocument();
    });

    it('shows the attention area once a bucket is filled', () => {
        render(<AiUsage {...baseProps} deadLettered={[deadLetteredGroup]} />);

        expect(screen.getByText('Needs attention')).toBeInTheDocument();
        expect(screen.getByText('Charlie')).toBeInTheDocument();
    });

    it('passes the budget currency down to every money figure', () => {
        render(
            <AiUsage
                {...baseProps}
                budget={{
                    todayCost: 1000,
                    dailyCeiling: 5000,
                    currency: 'IDR',
                }}
            />,
        );

        expect(screen.getByText('Rp 1,000.00')).toBeInTheDocument();
        expect(screen.getAllByText(/^Rp /).length).toBeGreaterThan(1);
    });
});
