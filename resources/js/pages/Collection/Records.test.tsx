import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import Records from './Records';

vi.mock('@/components/collection/MilestoneStrip', () => ({
    default: () => <div data-testid="milestone-strip" />,
}));

vi.mock('@/components/run/SplitsSparkline', () => ({
    default: ({ partialPaceSec }: { partialPaceSec?: number | null }) => (
        <div
            data-testid="splits-sparkline"
            data-partial={partialPaceSec ?? ''}
        />
    ),
}));

function pr(
    category: string,
    valueSec: number,
    id = 1,
    activityId: number | null = 99,
) {
    return {
        id,
        user_id: 1,
        category,
        value: valueSec,
        value_sec: valueSec,
        activity_id: activityId as number,
        set_at: '2026-05-16T07:00:00',
        activity: { detail: { name: 'Morning run' } },
    };
}

const featuredExtras = {
    pr_id: 1,
    splits_pace_sec: [360, 350, 345, 350, 346],
    splits_partial_pace_sec: null,
    location_name: 'Senayan',
    weather_temp_c: 28,
    weather_humidity_pct: 75,
    target_sec: 1740,
    delta_sec: 11,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Collection/Records', () => {
    it('shows the empty state when no PRs exist, with a sync CTA', () => {
        render(<Records personalRecords={[]} />);
        expect(screen.getByText(/No PRs yet/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Connect Strava/i }),
        ).toBeInTheDocument();
    });

    it('hides the sync CTA on the empty state while a sync is already running', () => {
        setMockPage({
            auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { state: 'syncing', last_synced_at: null },
        });
        render(<Records personalRecords={[]} />);
        expect(screen.getByText(/No PRs yet/)).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Connect Strava/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /sync/i }),
        ).not.toBeInTheDocument();
    });

    it('renders the hero scoreboard for the highest distance PR', () => {
        render(
            <Records
                personalRecords={[pr('5km', 1751)]}
                featuredExtras={featuredExtras}
            />,
        );
        expect(screen.getByText(/Senayan/)).toBeInTheDocument();
    });

    it('renders the trophy wall for distance PRs', () => {
        render(
            <Records
                personalRecords={[
                    pr('5km', 1751, 1),
                    pr('10km', 3500, 2),
                    pr('half_marathon', 7200, 3),
                ]}
                featuredExtras={featuredExtras}
            />,
        );
        expect(screen.getByText(/Trophy wall/)).toBeInTheDocument();
    });

    it('renders the pace ticker for effort PRs', () => {
        render(
            <Records
                personalRecords={[
                    pr('best_5min', 320, 10, null),
                    pr('best_20min', 349, 11, null),
                ]}
            />,
        );
        expect(screen.getByText(/Pace ticker/)).toBeInTheDocument();
    });

    it('labels a pace cell with the on-sky ink, not the vivid rarity fill', () => {
        render(<Records personalRecords={[pr('best_5min', 320, 10, null)]} />);
        const ticker = screen.getByText(/Pace ticker/).closest('section');
        const label = within(ticker!).getByText('Best 5 min').closest('div');

        expect(label).toHaveClass('text-ink-on-sky');
        expect(label).not.toHaveClass('text-rarity-rare');
    });

    it('renders pace-ticker effort PRs in ascending duration order, not data-arrival order', () => {
        render(
            <Records
                personalRecords={[
                    pr('best_60min', 3500, 10, null),
                    pr('best_5min', 320, 11, null),
                    pr('best_30min', 1800, 12, null),
                    pr('best_10min', 620, 13, null),
                    pr('best_20min', 1180, 14, null),
                ]}
            />,
        );
        const ticker = screen.getByText(/Pace ticker/).closest('section');
        const labels = within(ticker!)
            .getAllByText(/Best \d+ min/)
            .map((el) => el.textContent);
        expect(labels).toEqual([
            'Best 5 min',
            'Best 10 min',
            'Best 20 min',
            'Best 30 min',
            'Best 60 min',
        ]);
    });

    it('renders the featured PR context narrative when context_analysis is provided', () => {
        const featuredPr = {
            ...pr('5km', 1751),
            context_analysis: {
                id: 5,
                status: 'done' as const,
                content: 'Your recent pace has been steady.',
                type: 'pr_context' as const,
                subject_type: 'personal_record',
                subject_id: 1,
                discriminator: null,
            },
        };
        render(
            <Records
                personalRecords={[featuredPr]}
                featuredExtras={featuredExtras}
            />,
        );
        expect(
            screen.getByText(/Your recent pace has been steady/),
        ).toBeInTheDocument();
    });

    it('threads the trailing partial pace through to the sparkline', () => {
        render(
            <Records
                personalRecords={[pr('5km', 1751)]}
                featuredExtras={{
                    ...featuredExtras,
                    splits_partial_pace_sec: 300,
                }}
            />,
        );
        expect(screen.getByTestId('splits-sparkline')).toHaveAttribute(
            'data-partial',
            '300',
        );
    });
});
