import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RunsIndex from './Linimasa';
import { setMockPage } from '@/test/setup';
import type { Activity, ActivityDetail } from '@/types/inertia';

vi.mock('@/components/aktivitas/JourneyStrip', () => ({
    default: () => <div data-testid="journey-strip" />,
}));

vi.mock('@/components/run/RunListRow', () => ({
    default: ({ detail }: { detail: { name: string } }) => (
        <div data-testid="run-row">{detail.name}</div>
    ),
}));

function run(id: number, name: string, isoDate: string | null): Activity & { detail: ActivityDetail } {
    return {
        id,
        user_id: 1,
        analyzed_at: '2026-05-19',
        detail: {
            id,
            activity_id: id,
            name,
            start_date_local: isoDate,
            distance: 5000,
            moving_time: 1800,
            trimp_edwards: 50,
            average_heartrate: 145,
        } as ActivityDetail,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Riwayat/Linimasa', () => {
    it('renders the empty state when no runs exist', () => {
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByText(/Belum ada lari/i)).toBeInTheDocument();
    });

    it('groups runs into weekly buckets + renders weekly snapshot stats', () => {
        const runs = [
            run(101, 'Pagi negatif-split', '2026-05-19T06:00:00'),
            run(102, 'Long run pelan', '2026-05-17T06:00:00'),
        ];
        const snapshots = [
            {
                id: 1,
                week_ending: '2026-05-24',
                distance_km: 35.5,
                runs: 4,
                weekly_trimp: 320,
                atl_7d: 44.5,
                ctl_42d: 42,
                form: -2.5,
                form_status: 'optimal' as const,
                avg_decoupling: 3.2,
                monotony: 1.2,
                strain: 384,
                recap_analysis: {
                    id: 1,
                    status: 'done' as const,
                    content: 'Minggu konsisten.',
                    type: 'weekly_recap' as const,
                    subject_type: 'weekly_snapshot',
                    subject_id: 1,
                    discriminator: null,
                },
            },
        ];
        render(
            <RunsIndex
                runs={runs}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={snapshots}
            />,
        );
        expect(screen.getAllByTestId('run-row').length).toBe(2);
        expect(screen.getByText(/Minggu konsisten/)).toBeInTheDocument();
        expect(screen.getByText(/Pas/)).toBeInTheDocument();
    });

    it('maps every FormStatus value to a Temari pose (fresh / fatigued / overreaching / null)', () => {
        const baseSnap = {
            distance_km: 35.5,
            runs: 4,
            weekly_trimp: 320,
            atl_7d: 44.5,
            ctl_42d: 42,
            form: -2.5,
            avg_decoupling: 3.2,
            monotony: 1.2,
            strain: 384,
            recap_analysis: {
                id: 1,
                status: 'done' as const,
                content: 'Recap',
                type: 'weekly_recap' as const,
                subject_type: 'weekly_snapshot',
                subject_id: 1,
                discriminator: null,
            },
        };
        // Four weekly buckets, one run + one matching snapshot per bucket, each
        // snapshot using a different FormStatus value so every branch in
        // poseForFormStatus fires.
        const runs = [
            run(101, 'Minggu A', '2026-05-19T06:00:00'),
            run(102, 'Minggu B', '2026-05-12T06:00:00'),
            run(103, 'Minggu C', '2026-05-05T06:00:00'),
            run(104, 'Minggu D', '2026-04-28T06:00:00'),
        ];
        const snapshots = [
            { ...baseSnap, id: 1, week_ending: '2026-05-24', form_status: 'fresh' as const },
            { ...baseSnap, id: 2, week_ending: '2026-05-17', form_status: 'fatigued' as const },
            { ...baseSnap, id: 3, week_ending: '2026-05-10', form_status: 'overreaching' as const },
            { ...baseSnap, id: 4, week_ending: '2026-05-03', form_status: null },
        ];
        render(
            <RunsIndex
                runs={runs}
                rangeFilter="1y"
                rangeStart="2025-04-28"
                weeklySnapshots={snapshots}
            />,
        );
        expect(screen.getAllByTestId('run-row').length).toBe(4);
    });

    it('renders an orphans bucket when a run has no start_date_local', () => {
        const orphan = run(999, 'Tanpa tanggal', null);
        render(
            <RunsIndex
                runs={[orphan]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getAllByText('Tanpa tanggal').length).toBeGreaterThan(0);
    });

    it('renders the journey strip when journeyMatch is provided', () => {
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
                journeyMatch={{
                    first: { date: '2024-08-12', name: 'First', distance_km: 3, pace_sec_per_km: 400, avg_hr: 140 },
                    current: { date: '2026-05-19', name: 'Now', distance_km: 5, pace_sec_per_km: 350, avg_hr: 145 },
                    pace_improvement_sec: 50,
                    hr_improvement_bpm: -5,
                    total_km: 544.1,
                }}
            />,
        );
        expect(screen.getByTestId('journey-strip')).toBeInTheDocument();
    });
});
