import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    CostChart as CostChartData,
    UsageRow,
} from '@/pages/Narration/types';

import NarratorRanking from './NarratorRanking';

const CHART: CostChartData = {
    kinds: [
        { kind: 'briefing', label: 'BriefingMascotVoice', cost: 0.4 },
        { kind: 'weekly_recap', label: 'WeeklyRecap', cost: 0.1 },
    ],
    days: [],
};

const BY_KIND: UsageRow[] = [
    {
        kind: 'briefing',
        prompt: 700,
        completion: 300,
        total: 1000,
        cached: 500,
        calls: 4,
        cost: 0.4,
        truncated_calls: 0,
        avg_latency_ms: null,
        max_latency_ms: null,
        avg_steps: null,
        cached_pct: null,
        reasoning_pct: null,
    },
    {
        kind: 'weekly_recap',
        prompt: 100,
        completion: 40,
        total: 140,
        cached: 20,
        calls: 1,
        cost: 0.1,
        truncated_calls: 0,
        avg_latency_ms: null,
        max_latency_ms: null,
        avg_steps: null,
        cached_pct: null,
        reasoning_pct: null,
    },
];

describe('NarratorRanking', () => {
    it('ranks each narrator by cost with its call count beside it', () => {
        render(
            <NarratorRanking chart={CHART} byKind={BY_KIND} currency="USD" />,
        );

        expect(screen.getByText('BriefingMascotVoice')).toBeInTheDocument();
        expect(screen.getByText('$0.40')).toBeInTheDocument();
        expect(screen.getByText('1,000 tok · 4 calls')).toBeInTheDocument();
    });

    it('keeps the chart order, most expensive kind first', () => {
        render(
            <NarratorRanking chart={CHART} byKind={BY_KIND} currency="USD" />,
        );

        const names = screen.getAllByText(/BriefingMascotVoice|WeeklyRecap/);
        expect(names[0]).toHaveTextContent('BriefingMascotVoice');
        expect(names[1]).toHaveTextContent('WeeklyRecap');
    });

    it('shows a dash rather than 0 tokens/calls when the breakdown has no matching row', () => {
        render(<NarratorRanking chart={CHART} byKind={[]} currency="USD" />);

        // One dash per kind (2 kinds in CHART).
        expect(screen.getAllByText('—')).toHaveLength(2);
    });

    it('falls back to an empty state when no narrator has billed', () => {
        render(
            <NarratorRanking
                chart={{ kinds: [], days: [] }}
                byKind={[]}
                currency="USD"
            />,
        );

        expect(
            screen.getByText(/No narrator has billed yet/),
        ).toBeInTheDocument();
    });
});
