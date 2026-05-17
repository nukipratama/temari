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
        // "35.5 km" appears in both the hero KPI tile and the weekly row.
        expect(screen.getAllByText(/35.5 km/).length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('Minggu ini')).toBeInTheDocument();
    });

    it.each([
        { status: 'fresh' as const, label: 'Fresh' },
        { status: 'optimal' as const, label: 'Optimal' },
        { status: 'fatigued' as const, label: 'Fatigued' },
        { status: 'overreaching' as const, label: 'Overreaching' },
    ])('renders form_status $status as a chip with label "$label"', ({ status, label }) => {
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
        expect(screen.getByText(label)).toBeInTheDocument();
    });

    it('handles snapshot with null form_status (dash in chip slot)', () => {
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

    it('renders PR card with formatted distance category + time', () => {
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
        // Category label is humanised: '5km' → '5 KM'
        expect(screen.getByText('5 KM')).toBeInTheDocument();
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

    it('PR card without activity_id does not wrap in a link', () => {
        const { container } = render(
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
        // There should be no /runs/* link inside the PR card.
        const links = container.querySelectorAll('a[href^="/runs/"]');
        expect(links.length).toBe(0);
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

    it('renders hero KPI tiles when at least one snapshot is present', () => {
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
        expect(screen.getByText('Fitness')).toBeInTheDocument();
        expect(screen.getByText('Fatigue')).toBeInTheDocument();
        // "Form" also appears as the table column header — match all.
        expect(screen.getAllByText('Form').length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('Volume minggu ini')).toBeInTheDocument();
    });

    it('renders positive/negative/flat delta chips when prior week exists', () => {
        // Latest CTL grew (+2) and ATL dropped (-3) vs the prior week; Form
        // delta is < 0.05 so the chip renders the ±0 flat marker.
        render(
            <Progress
                snapshots={[
                    {
                        id: 2,
                        user_id: 1,
                        week_ending: '2026-05-11',
                        runs: 5,
                        distance_km: 40,
                        weekly_trimp: 340,
                        ctl_42d: 45,
                        atl_7d: 40,
                        form: 5,
                        avg_decoupling: 1,
                        form_status: 'fresh',
                    },
                    {
                        id: 1,
                        user_id: 1,
                        week_ending: '2026-05-04',
                        runs: 4,
                        distance_km: 35.5,
                        weekly_trimp: 320,
                        ctl_42d: 43,
                        atl_7d: 43,
                        form: 5.02,
                        avg_decoupling: 1,
                        form_status: 'optimal',
                    },
                ]}
                personalRecords={[]}
            />,
        );
        expect(screen.getByText('+2.0')).toBeInTheDocument();
        expect(screen.getByText('-3.0')).toBeInTheDocument();
        expect(screen.getByText('±0')).toBeInTheDocument();
    });

    it('renders dashes when latest snapshot has nullable metrics', () => {
        // delta() must return null when either side is null.
        render(
            <Progress
                snapshots={[
                    {
                        id: 1,
                        user_id: 1,
                        week_ending: '2026-05-04',
                        runs: 4,
                        distance_km: null,
                        weekly_trimp: 0,
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
        // Three hero KPI tiles + the row's metrics all render `—`.
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(3);
    });
});
