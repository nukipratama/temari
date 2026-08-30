import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import RunsIndex from './Feed';
import { run } from './runFixture';

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
    it('renders the Feed ⇄ Calendar nav with feed active', () => {
        render(<RunsIndex runs={[]} rangeFilter="8w" weeklySnapshots={[]} />);
        expect(screen.getByText('Feed').closest('a')).toHaveClass('bg-card');
    });

    it('renders the empty state when no runs exist', () => {
        render(<RunsIndex runs={[]} rangeFilter="1y" weeklySnapshots={[]} />);
        expect(screen.getByText(/Pulling in your runs/i)).toBeInTheDocument();
    });

    it('shows the connection-state empty copy without asking the user to widen', () => {
        setMockPage({
            auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { state: 'ready', last_synced_at: '2026-01-01' },
        });
        render(<RunsIndex runs={[]} rangeFilter="8w" weeklySnapshots={[]} />);
        // The page auto-widens, so there is no "widen the range yourself" nudge.
        expect(screen.getByText(/No runs to show yet/i)).toBeInTheDocument();
    });

    it('hides the sync button while a sync is already running', () => {
        // state defaults to 'syncing' in beforeEach.
        render(<RunsIndex runs={[]} rangeFilter="8w" weeklySnapshots={[]} />);
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
                weeklySnapshots={[]}
            />,
        );
        expect(screen.getByTestId('run-row')).toBeInTheDocument();
        expect(
            screen.queryByText(/Range automatically widened/i),
        ).not.toBeInTheDocument();
    });

    it('shows the auto-widened banner when the server widened the range', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Morning', '2026-05-19T06:00:00')]}
                rangeFilter="1y"
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
            <RunsIndex runs={[orphan]} rangeFilter="1y" weeklySnapshots={[]} />,
        );
        expect(screen.getAllByText('No date').length).toBeGreaterThan(0);
    });

    // The prototype's structure: only the most recent weeks show up front, an
    // explicit tap reveals the rest (already-loaded, no extra fetch).
    describe('load older weeks', () => {
        function weekRuns() {
            return [
                run(101, 'Week 1', '2026-05-19T06:00:00'),
                run(102, 'Week 2', '2026-05-12T06:00:00'),
                run(103, 'Week 3', '2026-05-05T06:00:00'),
            ];
        }

        it('shows only the two most recent weeks and a reveal button', () => {
            render(
                <RunsIndex
                    runs={weekRuns()}
                    rangeFilter="1y"
                    weeklySnapshots={[]}
                />,
            );

            expect(screen.getByText('Week 1')).toBeInTheDocument();
            expect(screen.getByText('Week 2')).toBeInTheDocument();
            expect(screen.queryByText('Week 3')).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Load older weeks' }),
            ).toBeInTheDocument();
        });

        it('reveals every week once tapped', () => {
            render(
                <RunsIndex
                    runs={weekRuns()}
                    rangeFilter="1y"
                    weeklySnapshots={[]}
                />,
            );

            fireEvent.click(
                screen.getByRole('button', { name: 'Load older weeks' }),
            );

            expect(screen.getByText('Week 3')).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Load older weeks' }),
            ).not.toBeInTheDocument();
        });

        it('shows no reveal button when there are two weeks or fewer', () => {
            render(
                <RunsIndex
                    runs={[run(101, 'Only week', '2026-05-19T06:00:00')]}
                    rangeFilter="8w"
                    weeklySnapshots={[]}
                />,
            );

            expect(
                screen.queryByRole('button', { name: 'Load older weeks' }),
            ).not.toBeInTheDocument();
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
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByText(/Viewing the week of/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /View all runs/ }),
        ).toHaveAttribute('href', '/history');
    });

    it('shows no week note on the normal list', () => {
        render(
            <RunsIndex
                runs={[run(101, 'Easy morning', '2026-05-13T06:00:00')]}
                rangeFilter="8w"
                weeklySnapshots={[]}
            />,
        );

        expect(
            screen.queryByText(/Viewing the week of/),
        ).not.toBeInTheDocument();
    });
});
