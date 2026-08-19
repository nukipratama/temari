import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage, stubSyncAnimationFrame } from '@/test/setup';

import RunsIndex from './Feed';
import { run } from './runFixture';

vi.mock('@/components/activities/JourneyStrip', () => ({
    default: () => <div data-testid="journey-strip" />,
}));

vi.mock('@/components/run/RunListRow', () => ({
    default: ({ detail }: { detail: { name: string } }) => (
        <div data-testid="run-row">{detail.name}</div>
    ),
}));

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
        flash: {},
        demoLoginEnabled: false,
        stravaSync: { state: 'syncing', last_synced_at: null },
    });
});

describe('Activities/Feed', () => {
    it('coach-marks the filter control on a first visit', () => {
        window.localStorage.clear();
        stubSyncAnimationFrame();
        render(
            <RunsIndex
                runs={[run(101, 'Morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );
        expect(
            screen.getByRole('dialog', { name: 'Filter the log' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /narrow it down by mood, distance, rarity, or week/,
            ),
        ).toBeInTheDocument();
    });

    it('renders the empty state when no runs exist', () => {
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByText(/Pulling in your runs/i)).toBeInTheDocument();
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
        expect(screen.getByText(/No runs to show yet/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/Perlebar rentang waktu/i),
        ).not.toBeInTheDocument();
    });

    it('hides the sync button while a sync is already running', () => {
        // state defaults to 'syncing' in beforeEach.
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByText(/Pulling in your runs/i)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /sync/i }),
        ).not.toBeInTheDocument();
    });

    it('renders runs with no auto-widen banner by default', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByTestId('run-row')).toBeInTheDocument();
        expect(
            screen.queryByText(/diperlebar otomatis|Menampilkan semua lari/i),
        ).not.toBeInTheDocument();
    });

    it('shows the auto-widened banner when the server widened the range', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Morning', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                rangeAutoWidened
                weeklySnapshots={[]}
            />,
        );
        expect(
            screen.getByText(/Range automatically widened/i),
        ).toBeInTheDocument();
    });

    it('shows the truncation note when runs are capped', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Morning', '2026-05-19T06:00:00')]}
                rangeFilter="all"
                rangeStart={null}
                runsTruncated
                maxRuns={365}
                weeklySnapshots={[]}
            />,
        );
        expect(
            screen.getByText(/Showing the 365 most recent runs/i),
        ).toBeInTheDocument();
    });

    it('groups runs into weekly buckets + renders weekly snapshot stats', () => {
        const runs = [
            run(101, 'Morning negative-split', '2026-05-19T06:00:00'),
            run(102, 'Long easy run', '2026-05-17T06:00:00'),
        ];
        const snapshots = [
            {
                id: 1,
                user_id: 1,
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
                    content: 'A consistent week.',
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
        expect(screen.getByText(/A consistent week/)).toBeInTheDocument();
        expect(screen.getByText(/Right on Track/)).toBeInTheDocument();
    });

    it('renders an orphans bucket when a run has no start_date_local', () => {
        const orphan = run(999, 'No date', null);
        render(
            <RunsIndex
                runs={[orphan]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getAllByText('No date').length).toBeGreaterThan(0);
    });

    it('renders the journey strip when journeyMatch is provided', () => {
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="1y"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
                journeyMatch={{
                    first: {
                        date: '2024-08-12',
                        name: 'First',
                        distance_km: 3,
                        pace_sec_per_km: 400,
                        avg_hr: 140,
                    },
                    current: {
                        date: '2026-05-19',
                        name: 'Now',
                        distance_km: 5,
                        pace_sec_per_km: 350,
                        avg_hr: 145,
                    },
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
        const runs = [run(101, 'Easy morning', '2026-05-19T06:00:00')];
        render(
            <RunsIndex
                runs={runs}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Open filter'));
        // Anchored: the removable chip for the same mood is also a button, but
        // it is named "Remove filter Easy".
        fireEvent.click(screen.getByRole('button', { name: /^Easy$/ }));

        expect(router.get).toHaveBeenCalledWith(
            '/history',
            // '8w' is the default range, so it is omitted from the URL.
            { mood: 'easy' },
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });

    it('reflects the server-applied mood filter as the pressed state', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['easy']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Open filter'));
        expect(screen.getByRole('button', { name: /^Easy$/ })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('drops an already-selected mood from the url when toggled off', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['easy']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Open filter'));
        fireEvent.click(screen.getByRole('button', { name: /^Easy$/ }));

        expect(router.get).toHaveBeenCalledWith(
            '/history',
            {},
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });

    it('resets range + mood filters back to a bare /activities', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['easy']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Open filter'));
        fireEvent.click(screen.getByRole('button', { name: 'Reset' }));

        // Defaults are omitted, so the unfiltered view is a clean URL.
        expect(router.get).toHaveBeenCalledWith(
            '/history',
            {},
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });

    it('counts results rather than activities while a mood filter is on', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                moodFilter={['easy']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/1 results/)).toBeInTheDocument();
    });

    // A filtered view that matched nothing is a different story from an empty
    // history: the user has runs, they just narrowed past them.
    it('shows a no-match state with a way out when a filter matches nothing', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[]}
                rangeFilter="8w"
                moodFilter={['easy']}
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText('No runs match.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Reset filter/ }));
        expect(router.get).toHaveBeenCalledWith(
            '/history',
            {},
            expect.anything(),
        );
    });

    it('carries every active filter forward when one of them changes', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
                moodFilter={['easy']}
                distanceFilter="21up"
                rangeStart="2025-05-19"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Open filter'));
        fireEvent.click(screen.getByRole('button', { name: /^Under 5K/ }));

        expect(router.get).toHaveBeenCalledWith(
            '/history',
            { range: '1y', mood: 'easy', dist: '0-5' },
            expect.anything(),
        );
    });

    it('clears the distance band when the active one is tapped again', () => {
        vi.mocked(router.get).mockReset();
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                distanceFilter="21up"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Open filter'));
        fireEvent.click(screen.getByRole('button', { name: /^Half and up/ }));

        expect(router.get).toHaveBeenCalledWith(
            '/history',
            {},
            expect.anything(),
        );
    });

    it('treats a distance filter as active for the result count', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                rangeFilter="8w"
                distanceFilter="21up"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/1 results/)).toBeInTheDocument();
    });

    describe('sort', () => {
        it('puts a non-default sort in the url', () => {
            vi.mocked(router.get).mockReset();
            render(
                <RunsIndex
                    runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            fireEvent.click(screen.getByLabelText('Open filter'));
            fireEvent.click(screen.getByRole('button', { name: /^Longest/ }));

            expect(router.get).toHaveBeenCalledWith(
                '/history',
                { sort: 'longest' },
                expect.anything(),
            );
        });

        it('omits the default sort from the url', () => {
            vi.mocked(router.get).mockReset();
            render(
                <RunsIndex
                    runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    sortMode="longest"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            fireEvent.click(screen.getByLabelText('Open filter'));
            fireEvent.click(
                screen.getByRole('button', { name: /^Newest first/ }),
            );

            expect(router.get).toHaveBeenCalledWith(
                '/history',
                {},
                expect.anything(),
            );
        });

        // Ranking globally is a mode switch: weekly recap cards only mean
        // something in date order, so they are absent from the ranked view.
        it('drops the week grouping for a ranked sort', () => {
            const runs = [
                run(101, 'Easy morning', '2026-05-19T06:00:00'),
                run(102, 'Long evening run', '2026-05-12T17:00:00'),
            ];
            const { rerender } = render(
                <RunsIndex
                    runs={runs}
                    rangeFilter="8w"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );
            // Grouped view labels each week.
            expect(screen.queryByText('Longest')).not.toBeInTheDocument();

            rerender(
                <RunsIndex
                    runs={runs}
                    rangeFilter="8w"
                    sortMode="longest"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            // "Longest" now labels both the ranked header and its removable
            // chip, so assert on the header's own unique text.
            expect(screen.getByText(/2 runs · sorted/)).toBeInTheDocument();
            // Both runs still render, just without week cards.
            expect(screen.getByText('Easy morning')).toBeInTheDocument();
            expect(screen.getByText('Long evening run')).toBeInTheDocument();
        });

        it('resets the sort back to newest along with the filters', () => {
            vi.mocked(router.get).mockReset();
            render(
                <RunsIndex
                    runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    sortMode="fastest"
                    moodFilter={['easy']}
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            fireEvent.click(screen.getByLabelText('Open filter'));
            fireEvent.click(screen.getByRole('button', { name: 'Reset' }));

            expect(router.get).toHaveBeenCalledWith(
                '/history',
                {},
                expect.anything(),
            );
        });
    });

    // Reached from the weekly-recap notification. Without the note the view
    // looks like a history that mysteriously lost most of its runs.
    it('explains the week scope and offers a way back to the full list', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-13T06:00:00')]}
                rangeFilter="8w"
                weekFilter="2026-05-17"
                rangeStart="2026-05-11"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/Viewing the week of/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /View all runs/ }),
        ).toHaveAttribute('href', '/history');
    });

    it('counts a week-scoped view as filtered', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-13T06:00:00')]}
                rangeFilter="8w"
                weekFilter="2026-05-17"
                rangeStart="2026-05-11"
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/1 results/)).toBeInTheDocument();
    });

    it('shows no week note on the normal list', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-13T06:00:00')]}
                rangeFilter="8w"
                rangeStart="2026-04-13"
                weeklySnapshots={[]}
            />,
        );

        expect(
            screen.queryByText(/Viewing the week of/),
        ).not.toBeInTheDocument();
    });

    // Remembered, but never applied behind the user's back: landing on a
    // silently pre-filtered list reads as a history that lost runs.
    describe('resume last filter', () => {
        const KEY = 'temari:riwayat:last-filter';

        afterEach(() => window.localStorage.clear());

        it('offers a saved filter above the list, and applies it only when tapped', () => {
            vi.mocked(router.get).mockReset();
            window.localStorage.setItem(
                KEY,
                JSON.stringify({ mood: 'blazing', dist: '21up' }),
            );
            render(
                <RunsIndex
                    runs={[run(101, 'Easy morning', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    rangeStart="2026-04-13"
                    weeklySnapshots={[]}
                />,
            );

            expect(
                screen.getByText(/Resume: Half and up · Blazing/),
            ).toBeInTheDocument();
            expect(router.get).not.toHaveBeenCalled();

            fireEvent.click(screen.getByText(/Resume:/));
            expect(router.get).toHaveBeenCalledWith(
                '/history',
                { mood: 'blazing', dist: '21up' },
                expect.anything(),
            );
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

        expect(screen.queryByText('No runs match.')).not.toBeInTheDocument();
    });
});
