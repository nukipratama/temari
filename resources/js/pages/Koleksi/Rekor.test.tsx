import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import KoleksiRekor from './Rekor';
import { makeUser, setMockPage } from '@/test/setup';

vi.mock('@/components/koleksi/ProgressionChart', () => ({
    default: () => <div data-testid="progression-chart" />,
}));

vi.mock('@/components/koleksi/MilestoneStrip', () => ({
    default: () => <div data-testid="milestone-strip" />,
}));

vi.mock('@/components/run/SplitsSparkline', () => ({
    default: () => <div data-testid="splits-sparkline" />,
}));

function pr(category: string, valueSec: number, id = 1, activityId: number | null = 99) {
    return {
        id,
        user_id: 1,
        category,
        value: valueSec,
        value_sec: valueSec,
        activity_id: activityId as number,
        set_at: '2026-05-16T07:00:00',
        activity: { detail: { name: 'Lari pagi' } },
    };
}

const featuredExtras = {
    pr_id: 1,
    splits_pace_sec: [360, 350, 345, 350, 346],
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

describe('Koleksi/Rekor', () => {
    it('shows the empty state when no PRs exist', () => {
        render(<KoleksiRekor personalRecords={[]} />);
        expect(screen.getByText(/Belum ada PR/)).toBeInTheDocument();
    });

    it('renders the hero scoreboard for the highest distance PR', () => {
        render(
            <KoleksiRekor
                personalRecords={[pr('5km', 1751)]}
                featuredExtras={featuredExtras}
            />,
        );
        expect(screen.getByText(/Senayan/)).toBeInTheDocument();
    });

    it('renders progression chart when a category series has weeks', () => {
        const series = {
            category: '5km',
            weeks: ['2026-04-13', '2026-04-20', '2026-04-27'],
            times_sec: [1800, 1770, 1751],
            goal_sec: 1740,
        };
        render(
            <KoleksiRekor
                personalRecords={[pr('5km', 1751)]}
                featuredExtras={featuredExtras}
                progressionByCategory={{ '5km': series }}
            />,
        );
        expect(screen.getByTestId('progression-chart')).toBeInTheDocument();
    });

    it('renders a distance selector when multiple category series exist', () => {
        const mk = (category: string) => ({
            category,
            weeks: ['2026-04-13', '2026-04-20'],
            times_sec: [1800, 1751],
            goal_sec: 1740,
        });
        render(
            <KoleksiRekor
                personalRecords={[pr('5km', 1751, 1), pr('marathon', 12000, 2)]}
                featuredExtras={featuredExtras}
                progressionByCategory={{ '5km': mk('5km'), marathon: mk('marathon') }}
            />,
        );
        expect(screen.getByRole('tab', { name: 'FM' })).toBeInTheDocument();
        const fiveK = screen.getByRole('tab', { name: '5K' });
        expect(fiveK).toBeInTheDocument();

        // Selecting a progression category swaps the active tab.
        fireEvent.click(fiveK);
        expect(fiveK).toHaveAttribute('aria-selected', 'true');
    });

    it('renders the trophy wall for distance PRs', () => {
        render(
            <KoleksiRekor
                personalRecords={[pr('5km', 1751, 1), pr('10km', 3500, 2), pr('half_marathon', 7200, 3)]}
                featuredExtras={featuredExtras}
            />,
        );
        expect(screen.getByText(/Trophy wall/)).toBeInTheDocument();
    });

    it('renders the pace ticker for effort PRs', () => {
        render(
            <KoleksiRekor
                personalRecords={[pr('best_5min', 320, 10, null), pr('best_20min', 349, 11, null)]}
            />,
        );
        expect(screen.getByText(/Pace ticker/)).toBeInTheDocument();
    });

    it('renders the featured PR context narrative when context_analysis is provided', () => {
        const featuredPr = {
            ...pr('5km', 1751),
            context_analysis: {
                id: 5,
                status: 'done' as const,
                content: 'Tempo terbaru kamu konsisten.',
                type: 'pr_context' as const,
                subject_type: 'personal_record',
                subject_id: 1,
                discriminator: null,
            },
        };
        render(<KoleksiRekor personalRecords={[featuredPr]} featuredExtras={featuredExtras} />);
        expect(screen.getByText(/Tempo terbaru kamu konsisten/)).toBeInTheDocument();
    });
});
