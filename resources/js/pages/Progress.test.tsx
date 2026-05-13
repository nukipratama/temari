import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Progress from './Progress';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Progress', () => {
    it('renders empty state when no snapshots + no PRs', () => {
        render(<Progress snapshots={[]} personalRecords={[]} />);
        expect(screen.getByText(/Belum ada PR/)).toBeInTheDocument();
    });

    it('renders weekly snapshots when present', () => {
        render(
            <Progress
                snapshots={[
                    {
                        id: 1,
                        user_id: 1,
                        week_ending: '2026-05-04',
                        runs: 4,
                        distance_km: 35.5,
                        weekly_trimp: 320,
                        ctl_42d: 42,
                        atl_7d: 44.5,
                        form: -2.5,
                        avg_decoupling: 3.2,
                        form_status: 'optimal',
                    },
                ]}
                personalRecords={[]}
            />,
        );
        expect(screen.getByText('Riwayat Mingguan')).toBeInTheDocument();
        expect(screen.getByText(/35.5 km/)).toBeInTheDocument();
    });

    it.each([
        { status: 'fresh' as const, klass: /text-mood-bouncy/ },
        { status: 'fatigued' as const, klass: /text-mood-glow/ },
        { status: 'overreaching' as const, klass: /text-mood-cooked/ },
        { status: 'optimal' as const, klass: /text-ink/ },
    ])('colors form_status $status', ({ status, klass }) => {
        render(
            <Progress
                snapshots={[
                    {
                        id: 1,
                        user_id: 1,
                        week_ending: '2026-05-04',
                        runs: 4,
                        distance_km: 30,
                        weekly_trimp: 320,
                        ctl_42d: null,
                        atl_7d: null,
                        form: null,
                        avg_decoupling: null,
                        form_status: status,
                    },
                ]}
                personalRecords={[]}
            />,
        );
        expect(screen.getByText(status)).toHaveClass(klass);
    });

    it('handles snapshot with null form_status (default tone branch)', () => {
        render(
            <Progress
                snapshots={[
                    {
                        id: 1,
                        user_id: 1,
                        week_ending: '2026-05-04',
                        runs: 4,
                        distance_km: 30,
                        weekly_trimp: 320,
                        ctl_42d: null,
                        atl_7d: null,
                        form: null,
                        avg_decoupling: null,
                        form_status: null,
                    },
                ]}
                personalRecords={[]}
            />,
        );
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it('renders PR ledger with distance value formatted as time', () => {
        render(
            <Progress
                snapshots={[]}
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category: '5km',
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                        activity: { detail: { name: '5K Race' } },
                    },
                ]}
            />,
        );
        expect(screen.getByText('5km')).toBeInTheDocument();
        expect(screen.getByText('25:00')).toBeInTheDocument();
        expect(screen.getByText('5K Race')).toBeInTheDocument();
    });

    it('renders non-distance PR as pace/km', () => {
        render(
            <Progress
                snapshots={[]}
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category: 'best_pace',
                        value: 300,
                        value_sec: 300,
                        set_at: '2026-05-01',
                        activity: { detail: { name: 'Tempo' } },
                    },
                ]}
            />,
        );
        expect(screen.getByText('5:00/km')).toBeInTheDocument();
    });

    it('renders dash when PR has no activity', () => {
        render(
            <Progress
                snapshots={[]}
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: null as unknown as number,
                        category: '5km',
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                    },
                ]}
            />,
        );
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('falls back to "Run" when PR activity has no detail name', () => {
        render(
            <Progress
                snapshots={[]}
                personalRecords={[
                    {
                        id: 1,
                        user_id: 1,
                        activity_id: 99,
                        category: '5km',
                        value: 1500,
                        value_sec: 1500,
                        set_at: '2026-05-01',
                        activity: {},
                    },
                ]}
            />,
        );
        expect(screen.getByText('Run')).toBeInTheDocument();
    });
});
