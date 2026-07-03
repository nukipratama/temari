import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import RunsIndex from './Jejak';
import { makeUser, setMockPage } from '@/test/setup';
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
        auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
        flash: {},
        demoLoginEnabled: false,
        stravaSync: { state: 'syncing', last_synced_at: null },
    });
});

describe('Riwayat/Jejak', () => {
    it('renders the empty state when no runs exist', () => {
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByText(/Aku lagi narik lari kamu/i)).toBeInTheDocument();
    });

    it('shows the connection-state empty copy without asking the user to widen', () => {
        setMockPage({
            auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { state: 'ready', last_synced_at: '2026-01-01' },
        });
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );
        // The page auto-widens, so there is no "widen the range yourself" nudge.
        expect(screen.getByText(/Belum ada lari yang bisa ditampilkan/i)).toBeInTheDocument();
        expect(screen.queryByText(/Perlebar rentang waktu/i)).not.toBeInTheDocument();
    });

    it('hides the sync button while a sync is already running', () => {
        // state defaults to 'syncing' in beforeEach.
        render(
            <RunsIndex runs={[]} rangeFilter="8w" rangeStart="2026-04-13" weeklySnapshots={[]} />,
        );
        expect(screen.getByText(/Aku lagi narik lari kamu/i)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /sync/i })).not.toBeInTheDocument();
    });

    it('renders runs with no auto-widen banner by default', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByTestId('run-row')).toBeInTheDocument();
        expect(screen.queryByText(/diperlebar otomatis|Menampilkan semua lari/i)).not.toBeInTheDocument();
    });

    it('shows the auto-widened banner when the server widened the range', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                rangeAutoWidened
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByText(/Rentang diperlebar otomatis/i)).toBeInTheDocument();
    });

    it('shows the truncation note when runs are capped', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi', '2026-05-19T06:00:00')]}
                rangeFilter="all"
                rangeStart={null}
                runsTruncated
                maxRuns={365}
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByText(/Menampilkan 365 lari terbaru/i)).toBeInTheDocument();
    });

    it('shows the "semua lari" note when widened all the way', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi', '2026-05-19T06:00:00')]}
                rangeFilter="all"
                rangeStart={null}
                rangeAutoWidened
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByText(/Menampilkan semua lari kamu/i)).toBeInTheDocument();
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
                is_current_week: false,
                is_chain_head: true,
                recap_analysis: {
                    id: 1,
                    status: 'done' as const,
                    content: 'Minggu konsisten.',
                    type: 'weekly_recap' as const,
                    subject_type: 'weekly_snapshot',
                    subject_id: 1,
                    discriminator: null,
                },
                telegram_retry_after_seconds: null,
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

    const doneWeekSnapshot = {
        id: 7,
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
        is_current_week: false,
        is_chain_head: true,
        recap_analysis: {
            id: 1,
            status: 'done' as const,
            content: 'Minggu konsisten.',
            type: 'weekly_recap' as const,
            subject_type: 'weekly_snapshot',
            subject_id: 7,
            discriminator: null,
        },
        telegram_retry_after_seconds: null,
    };

    it('hides the weekly recap Telegram button when not connected', () => {
        // telegramConnected defaults to undefined (falsy) in beforeEach.
        render(
            <RunsIndex
                runs={[run(101, 'Pagi', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[doneWeekSnapshot]}
            />,
        );
        expect(screen.queryByText('Kirim ke Telegram')).not.toBeInTheDocument();
    });

    it('force-sends the weekly recap to Telegram when connected and the button is clicked', () => {
        vi.mocked(router.post).mockReset();
        setMockPage({
            auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { state: 'ready', last_synced_at: '2026-01-01' },
            telegramConnected: true,
        });
        render(
            <RunsIndex
                runs={[run(101, 'Pagi', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[doneWeekSnapshot]}
            />,
        );
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(router.post).toHaveBeenCalledWith(
            '/rekap-mingguan/7/telegram',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
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
            is_current_week: false,
            is_chain_head: false,
            recap_analysis: {
                id: 1,
                status: 'done' as const,
                content: 'Recap',
                type: 'weekly_recap' as const,
                subject_type: 'weekly_snapshot',
                subject_id: 1,
                discriminator: null,
            },
            telegram_retry_after_seconds: null,
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
