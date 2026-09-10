import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import type { AthletePageProps } from './types';

import Athlete from './Athlete';

function props(overrides: Partial<AthletePageProps> = {}): AthletePageProps {
    return {
        tab: 'narrations',
        filters: { kind: null, status: null, before: null },
        availableKinds: ['trend_read'],
        availableStatuses: ['done'],
        header: {
            athlete: { id: 7, name: 'Dina', is_demo: false },
            currency: 'USD',
            today_spend: 0.25,
            ceiling: { value: 1, source: 'config' },
            sparkline: [{ day: '2026-09-10', cost: 0.25 }],
            forecast: {
                month_to_date: 2,
                projected: 6,
                days_remaining: 20,
                daily_rate: 0.2,
            },
        },
        narrations: [
            {
                id: 12,
                kind: 'trend_read',
                discriminator: null,
                status: 'done',
                served_by: 'llm',
                origin: 'scheduled',
                cost: 0.02,
                last_cost: 0.02,
                prompt_tokens: 100,
                completion_tokens: 40,
                latency_ms: 500,
                steps: 1,
                tool_calls: [],
                content: 'a read.',
                error: null,
                generated_at: null,
                flag: null,
                version_count: 0,
                previous_content: null,
            },
        ],
        nextCursor: null,
        costByKind: [
            {
                kind: 'trend_read',
                today: { cost: 0.02, calls: 1 },
                week: { cost: 0.1, calls: 4 },
                month: { cost: 0.4, calls: 16 },
            },
        ],
        attention: { failed: [], dead_lettered: [], stuck: [] },
        audit: [],
        override: null,
        replayBudget: { cap: 0.5, spent_today: 0, cap_reached: false },
        ...overrides,
    };
}

describe('Narration/Athlete', () => {
    it('names the athlete and draws the narrations tab by default', () => {
        render(<Athlete {...props()} />);

        expect(
            screen.getByRole('heading', { name: 'Dina' }),
        ).toBeInTheDocument();
        expect(screen.getByText('a read.')).toBeInTheDocument();
        expect(screen.getByText('$0.25')).toBeInTheDocument();
    });

    it('marks the demo account in the subtitle', () => {
        render(
            <Athlete
                {...props({
                    header: {
                        ...props().header,
                        athlete: { id: 7, name: 'Demo', is_demo: true },
                    },
                })}
            />,
        );

        expect(screen.getByText(/demo account/)).toBeInTheDocument();
    });

    it('links each tab to its own query string', () => {
        render(<Athlete {...props()} />);

        expect(screen.getByText('cost by kind').getAttribute('href')).toBe(
            '/devtools/narration/athletes/7?tab=cost',
        );
        expect(screen.getByText('attention').getAttribute('href')).toBe(
            '/devtools/narration/athletes/7?tab=attention',
        );
    });

    it('draws the cost tab when it is selected', () => {
        render(<Athlete {...props({ tab: 'cost' })} />);

        expect(screen.queryByText('a read.')).not.toBeInTheDocument();
        expect(screen.getByText('16 calls')).toBeInTheDocument();
    });

    it('draws the attention tab when it is selected', () => {
        render(<Athlete {...props({ tab: 'attention' })} />);

        expect(
            screen.getByText('today-only ceiling override'),
        ).toBeInTheDocument();
    });

    it('renders the flash a completed action left behind', () => {
        setMockPage({ flash: { info: 'retrying 2 block(s).' } });

        render(<Athlete {...props()} />);

        expect(screen.getByText('retrying 2 block(s).')).toBeInTheDocument();
    });
});
