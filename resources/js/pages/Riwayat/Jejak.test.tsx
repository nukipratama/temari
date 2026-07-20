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
                notification_retry_after_seconds: null,
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

    it('shows the snapshot totals (not the range-truncated bucket count) when a snapshot exists', () => {
        // Only 1 of the week's runs falls inside rangeStart, but the WeeklySnapshot
        // (computed independently of the range filter) says the week had 4 runs /
        // 35.5 km — the header must agree with that, not the truncated bucket.
        const runs = [run(101, 'Long run pelan', '2026-05-19T06:00:00')];
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
                    content: 'Minggu ini kekumpul 35.5 km dari 4 sesi.',
                    type: 'weekly_recap' as const,
                    subject_type: 'weekly_snapshot',
                    subject_id: 1,
                    discriminator: null,
                },
                notification_retry_after_seconds: null,
            },
        ];
        render(
            <RunsIndex
                runs={runs}
                rangeFilter="8w"
                rangeStart="2026-05-18"
                weeklySnapshots={snapshots}
            />,
        );
        expect(screen.getByText('4 run')).toBeInTheDocument();
        expect(screen.getByText('35.5 km')).toBeInTheDocument();
    });

    it('shows the live bucket totals (not a stale snapshot) for the in-progress week', () => {
        // The snapshot for the current week is recomputed by a queued listener,
        // so right after a fresh sync it can lag behind the runs this request
        // just fetched live. The header must reflect what's actually rendered.
        const runs = [
            run(101, 'Pagi', '2026-05-19T06:00:00'),
            run(102, 'Sore', '2026-05-20T18:00:00'),
        ];
        const snapshots = [
            {
                id: 1,
                week_ending: '2026-05-24',
                distance_km: 5,
                runs: 1,
                weekly_trimp: 50,
                atl_7d: 44.5,
                ctl_42d: 42,
                form: -2.5,
                form_status: 'optimal' as const,
                avg_decoupling: 3.2,
                monotony: 1.2,
                strain: 384,
                is_current_week: true,
                is_chain_head: false,
                recap_analysis: {
                    id: 1,
                    status: 'pending' as const,
                    content: null,
                    type: 'weekly_recap' as const,
                    subject_type: 'weekly_snapshot',
                    subject_id: 1,
                    discriminator: null,
                },
                notification_retry_after_seconds: null,
            },
        ];
        render(
            <RunsIndex
                runs={runs}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={snapshots}
            />,
        );
        expect(screen.getByText('2 run')).toBeInTheDocument();
        expect(screen.getByText('10.0 km')).toBeInTheDocument();
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
        notification_retry_after_seconds: null,
    };

    it('shows a muted weekly recap Telegram button that nudges (no send) when not connected', () => {
        // telegramConnected defaults to undefined (falsy) in beforeEach.
        vi.mocked(router.post).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Pagi', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[doneWeekSnapshot]}
            />,
        );
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(router.post).not.toHaveBeenCalled();
    });

    it('force-sends the weekly recap when a channel is wired and the button is clicked', () => {
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
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(router.post).toHaveBeenCalledWith(
            '/rekap-mingguan/7/kirim',
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
            notification_retry_after_seconds: null,
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

    // The mood filter is server-side and lives in the URL, so toggling one is a
    // partial reload rather than local state.
    it('toggles a mood filter by visiting the url with it', () => {
        vi.mocked(router.get).mockReset();
        const runs = [run(101, 'Pagi santai', '2026-05-19T06:00:00')];
        render(
            <RunsIndex
                runs={runs}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Buka filter'));
        // Anchored: the removable chip for the same mood is also a button, but
        // it is named "Hapus filter Enteng".
        fireEvent.click(screen.getByRole('button', { name: /^Enteng$/ }));

        expect(router.get).toHaveBeenCalledWith(
            '/aktivitas',
            // '8w' is the default range, so it is omitted from the URL.
            { mood: 'enteng' },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('reflects the server-applied mood filter as the pressed state', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['enteng']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Buka filter'));
        expect(screen.getByRole('button', { name: /^Enteng$/ })).toHaveAttribute('aria-pressed', 'true');
    });

    it('drops an already-selected mood from the url when toggled off', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['enteng']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Buka filter'));
        fireEvent.click(screen.getByRole('button', { name: /^Enteng$/ }));

        expect(router.get).toHaveBeenCalledWith(
            '/aktivitas',
            {},
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('resets range + mood filters back to a bare /aktivitas', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['enteng']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Buka filter'));
        fireEvent.click(screen.getByRole('button', { name: 'Reset' }));

        // Defaults are omitted, so the unfiltered view is a clean URL.
        expect(router.get).toHaveBeenCalledWith(
            '/aktivitas',
            {},
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('counts results rather than activities while a mood filter is on', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['enteng']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/1 hasil/)).toBeInTheDocument();
    });

    // A filtered view that matched nothing is a different story from an empty
    // history: the user has runs, they just narrowed past them.
    it('shows a no-match state with a way out when a filter matches nothing', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="8w"
                moodFilter={['enteng']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText('Gak ada lari yang cocok.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Reset filter/ }));
        expect(router.get).toHaveBeenCalledWith('/aktivitas', {}, expect.anything());
    });

    it('carries every active filter forward when one of them changes', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
                moodFilter={['enteng']}
                distanceFilter="21up"
                searchFilter="tempo"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Buka filter'));
        fireEvent.click(screen.getByRole('button', { name: /^Di bawah 5K/ }));

        expect(router.get).toHaveBeenCalledWith(
            '/aktivitas',
            { range: '1y', mood: 'enteng', dist: '0-5', q: 'tempo' },
            expect.anything(),
        );
    });

    it('clears the distance band when the active one is tapped again', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                distanceFilter="21up"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Buka filter'));
        fireEvent.click(screen.getByRole('button', { name: /^Half ke atas/ }));

        expect(router.get).toHaveBeenCalledWith('/aktivitas', {}, expect.anything());
    });

    it('submits a search term into the url', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Buka filter'));
        const input = screen.getByLabelText('Cari nama lari');
        fireEvent.change(input, { target: { value: '  tempo  ' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        // Trimmed, so a stray space can't produce a different shareable URL.
        expect(router.get).toHaveBeenCalledWith('/aktivitas', { q: 'tempo' }, expect.anything());
    });

    it('treats a distance or search filter as active for the result count', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                distanceFilter="21up"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/1 hasil/)).toBeInTheDocument();
    });

    describe('sort', () => {
        it('puts a non-default sort in the url', () => {
            vi.mocked(router.get).mockReset();
            render(
                <RunsIndex
                    runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            fireEvent.click(screen.getByLabelText('Buka filter'));
            fireEvent.click(screen.getByRole('button', { name: /^Paling jauh/ }));

            expect(router.get).toHaveBeenCalledWith('/aktivitas', { sort: 'longest' }, expect.anything());
        });

        it('omits the default sort from the url', () => {
            vi.mocked(router.get).mockReset();
            render(
                <RunsIndex
                    runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    sortMode="longest"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            fireEvent.click(screen.getByLabelText('Buka filter'));
            fireEvent.click(screen.getByRole('button', { name: /^Terbaru dulu/ }));

            expect(router.get).toHaveBeenCalledWith('/aktivitas', {}, expect.anything());
        });

        // Ranking globally is a mode switch: weekly recap cards only mean
        // something in date order, so they are absent from the ranked view.
        it('drops the week grouping for a ranked sort', () => {
            const runs = [
                run(101, 'Pagi santai', '2026-05-19T06:00:00'),
                run(102, 'Sore panjang', '2026-05-12T17:00:00'),
            ];
            const { rerender } = render(
                <RunsIndex runs={runs} rangeFilter="8w" rangeStart="2026-04-13" weeklySnapshots={[]} />,
            );
            // Grouped view labels each week.
            expect(screen.queryByText('Paling jauh')).not.toBeInTheDocument();

            rerender(
                <RunsIndex
                    runs={runs}
                    rangeFilter="8w"
                    sortMode="longest"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            // "Paling jauh" now labels both the ranked header and its removable
            // chip, so assert on the header's own unique text.
            expect(screen.getByText(/2 lari · diurutkan/)).toBeInTheDocument();
            // Both runs still render, just without week cards.
            expect(screen.getByText('Pagi santai')).toBeInTheDocument();
            expect(screen.getByText('Sore panjang')).toBeInTheDocument();
        });

        it('resets the sort back to newest along with the filters', () => {
            vi.mocked(router.get).mockReset();
            render(
                <RunsIndex
                    runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    sortMode="fastest"
                    moodFilter={['enteng']}
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            fireEvent.click(screen.getByLabelText('Buka filter'));
            fireEvent.click(screen.getByRole('button', { name: 'Reset' }));

            expect(router.get).toHaveBeenCalledWith('/aktivitas', {}, expect.anything());
        });
    });

    // Reached from the weekly-recap notification. Without the note the view
    // looks like a history that mysteriously lost most of its runs.
    it('explains the week scope and offers a way back to the full list', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-13T06:00:00')]}
                rangeFilter="8w"
                weekFilter="2026-05-17"
                rangeStart="2026-05-11"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/Lagi lihat minggu/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Lihat semua lari/ })).toHaveAttribute('href', '/aktivitas');
    });

    it('counts a week-scoped view as filtered', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-13T06:00:00')]}
                rangeFilter="8w"
                weekFilter="2026-05-17"
                rangeStart="2026-05-11"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/1 hasil/)).toBeInTheDocument();
    });

    it('shows no week note on the normal list', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Pagi santai', '2026-05-13T06:00:00')]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.queryByText(/Lagi lihat minggu/)).not.toBeInTheDocument();
    });

    // Real filtering removes non-matching runs, so a week loses the context the
    // old dimmed rows conveyed. The snapshot's own total names the gap.
    describe('hidden-run count', () => {
        const weekSnapshot = (runs: number | null, isCurrent = false) => [{
            id: 1,
            week_ending: '2026-05-24',
            distance_km: 35.5,
            runs,
            weekly_trimp: 320,
            atl_7d: 44.5,
            ctl_42d: 42,
            form: -2.5,
            form_status: 'optimal' as const,
            avg_decoupling: 3.2,
            monotony: 1.2,
            strain: 384,
            is_current_week: isCurrent,
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
            notification_retry_after_seconds: null,
        }];

        it('names how many runs the filter hid in that week', () => {
            render(
                <RunsIndex
                    runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                    rangeFilter="1y"
                    moodFilter={['enteng']}
                    rangeStart="2025-05-19"
                    weeklySnapshots={weekSnapshot(4)}
                />,
            );

            expect(screen.getByText(/3 lari lain di minggu ini gak cocok/)).toBeInTheDocument();
            expect(screen.getByText('1 dari 4 run')).toBeInTheDocument();
        });

        it('says nothing when the filter hid nothing', () => {
            render(
                <RunsIndex
                    runs={[run(101, 'A', '2026-05-19T06:00:00'), run(102, 'B', '2026-05-20T06:00:00')]}
                    rangeFilter="1y"
                    moodFilter={['enteng']}
                    rangeStart="2025-05-19"
                    weeklySnapshots={weekSnapshot(2)}
                />,
            );

            expect(screen.queryByText(/gak cocok sama filternya/)).not.toBeInTheDocument();
        });

        it('says nothing when no filter is active', () => {
            render(
                <RunsIndex
                    runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                    rangeFilter="1y"
                    rangeStart="2025-05-19"
                    weeklySnapshots={weekSnapshot(4)}
                />,
            );

            expect(screen.queryByText(/gak cocok sama filternya/)).not.toBeInTheDocument();
        });

        // The in-progress week's snapshot is recomputed by a queued worker, so it
        // can lag the live bucket and would report a bogus gap.
        it('stays quiet for the in-progress week', () => {
            render(
                <RunsIndex
                    runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                    rangeFilter="1y"
                    moodFilter={['enteng']}
                    rangeStart="2025-05-19"
                    weeklySnapshots={weekSnapshot(4, true)}
                />,
            );

            expect(screen.queryByText(/gak cocok sama filternya/)).not.toBeInTheDocument();
        });

        it('stays quiet when the snapshot has no run count', () => {
            render(
                <RunsIndex
                    runs={[run(101, 'Pagi santai', '2026-05-19T06:00:00')]}
                    rangeFilter="1y"
                    moodFilter={['enteng']}
                    rangeStart="2025-05-19"
                    weeklySnapshots={weekSnapshot(null)}
                />,
            );

            expect(screen.queryByText(/gak cocok sama filternya/)).not.toBeInTheDocument();
        });
    });

    it('keeps the onboarding empty state when there is no filter and no runs', () => {
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.queryByText('Gak ada lari yang cocok.')).not.toBeInTheDocument();
    });
});
