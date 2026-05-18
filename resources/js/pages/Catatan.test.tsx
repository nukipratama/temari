import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Catatan from './Catatan';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Catatan', () => {
    it('renders only header when no snapshots', () => {
        render(<Catatan snapshots={[]} />);
        expect(screen.getByRole('heading', { name: 'Catatan' })).toBeInTheDocument();
        expect(screen.queryByText('Riwayat Mingguan')).not.toBeInTheDocument();
    });

    it('renders weekly snapshots when present', () => {
        render(
            <Catatan
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
            />,
        );
        expect(screen.getByText('Riwayat Mingguan')).toBeInTheDocument();
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
            <Catatan
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
            />,
        );
        expect(screen.getByText(label)).toBeInTheDocument();
    });

    it('handles snapshot with null form_status (dash in chip slot)', () => {
        render(
            <Catatan
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
            />,
        );
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it('renders hero KPI tiles when at least one snapshot is present', () => {
        render(
            <Catatan
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
            />,
        );
        expect(screen.getByText('Fitness')).toBeInTheDocument();
        expect(screen.getByText('Fatigue')).toBeInTheDocument();
        expect(screen.getAllByText('Form').length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('Volume minggu ini')).toBeInTheDocument();
    });

    it('renders positive/negative/flat delta chips when prior week exists', () => {
        render(
            <Catatan
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
            />,
        );
        expect(screen.getByText('+2.0')).toBeInTheDocument();
        expect(screen.getByText('-3.0')).toBeInTheDocument();
        expect(screen.getByText('±0')).toBeInTheDocument();
    });

    it('renders dashes when latest snapshot has nullable metrics', () => {
        render(
            <Catatan
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
            />,
        );
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(3);
    });
});
