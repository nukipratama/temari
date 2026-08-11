import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type {
    WeekBucket,
    RunWithDetail,
} from '@/pages/Activities/useJejakFilters';
import type { AnalysisPayload, WeeklySnapshotWithRecap } from '@/types/inertia';

import { run } from '@/pages/Activities/runFixture';
import { makeUser, setMockPage } from '@/test/setup';

import WeekSection from './WeekSection';

vi.mock('@/components/run/RunListRow', () => ({
    default: ({ detail }: { detail: { name: string } }) => (
        <div data-testid="run-row">{detail.name}</div>
    ),
}));

function recapAnalysis(
    overrides: Partial<AnalysisPayload> = {},
): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'Consistent week.',
        type: 'weekly_recap',
        subject_type: 'weekly_snapshot',
        subject_id: 7,
        discriminator: null,
        ...overrides,
    };
}

function bucket(runs: RunWithDetail[] = [run(101, 'Pagi')]): WeekBucket {
    return {
        weekStart: '2026-05-18',
        weekEnding: '2026-05-24',
        label: '18 Mei - 24 Mei 2026',
        runs,
        totalKm: runs.length * 5,
        totalTrimp: runs.length * 50,
    };
}

function snapshot(
    overrides: Partial<WeeklySnapshotWithRecap> = {},
): WeeklySnapshotWithRecap {
    return {
        id: 7,
        user_id: 1,
        week_ending: '2026-05-24',
        distance_km: 35.5,
        runs: 4,
        weekly_trimp: 320,
        atl_7d: 44.5,
        ctl_42d: 42,
        form: -2.5,
        form_status: 'optimal',
        avg_decoupling: 3.2,
        monotony: 1.2,
        strain: 384,
        is_current_week: false,
        is_chain_head: true,
        recap_analysis: recapAnalysis(),
        notification_retry_after_seconds: null,
        ...overrides,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
        flash: {},
        demoLoginEnabled: false,
        stravaSync: { state: 'ready', last_synced_at: '2026-01-01' },
    });
});

describe('WeekSection', () => {
    it('falls back to the bucket totals when the week has no snapshot', async () => {
        render(
            <WeekSection
                bucket={bucket([run(101, 'Pagi'), run(102, 'Sore')])}
                snapshot={null}
                notes={{}}
                moods={{}}
                filtered={false}
            />,
        );

        // The header stats tally up from 0 (tier-2 count-up), so wait for them
        // to settle rather than asserting the target value synchronously.
        await waitFor(() =>
            expect(screen.getByText('2 run')).toBeInTheDocument(),
        );
        expect(screen.getByText('10.0 km')).toBeInTheDocument();
        expect(screen.getByText('100 TRIMP')).toBeInTheDocument();
        expect(screen.getAllByTestId('run-row').length).toBe(2);
    });

    it('shows the snapshot totals (not the range-truncated bucket count) when a snapshot exists', async () => {
        // Only 1 of the week's runs falls inside rangeStart, but the WeeklySnapshot
        // (computed independently of the range filter) says the week had 4 runs /
        // 35.5 km — the header must agree with that, not the truncated bucket.
        render(
            <WeekSection
                bucket={bucket()}
                snapshot={snapshot()}
                notes={{}}
                moods={{}}
                filtered={false}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('4 run')).toBeInTheDocument(),
        );
        expect(screen.getByText('35.5 km')).toBeInTheDocument();
        expect(screen.getByText(/Consistent week/)).toBeInTheDocument();
    });

    it('shows the live bucket totals (not a stale snapshot) for the in-progress week', async () => {
        // The snapshot for the current week is recomputed by a queued listener,
        // so right after a fresh sync it can lag behind the runs this request
        // just fetched live. The header must reflect what's actually rendered.
        render(
            <WeekSection
                bucket={bucket([run(101, 'Pagi'), run(102, 'Sore')])}
                snapshot={snapshot({
                    distance_km: 5,
                    runs: 1,
                    weekly_trimp: 50,
                    is_current_week: true,
                })}
                notes={{}}
                moods={{}}
                filtered={false}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('2 run')).toBeInTheDocument(),
        );
        expect(screen.getByText('10.0 km')).toBeInTheDocument();
    });

    it('renders the form-status chip label for every FormStatus value', () => {
        const labels: Record<string, string> = {
            fresh: 'Feeling Fresh',
            optimal: 'Right on Track',
            fatigued: 'Getting Tired',
            overreaching: 'Overreaching',
        };
        for (const status of [
            'fresh',
            'optimal',
            'fatigued',
            'overreaching',
        ] as const) {
            const { unmount } = render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot({ form_status: status })}
                    notes={{}}
                    moods={{}}
                    filtered={false}
                />,
            );
            expect(screen.getByText(labels[status])).toBeInTheDocument();
            unmount();
        }
    });

    it('renders no metric chips when the snapshot carries no metrics', () => {
        render(
            <WeekSection
                bucket={bucket()}
                snapshot={snapshot({
                    atl_7d: null,
                    ctl_42d: null,
                    form: null,
                    form_status: null,
                    avg_decoupling: null,
                    monotony: null,
                })}
                notes={{}}
                moods={{}}
                filtered={false}
            />,
        );

        expect(screen.queryByText('Monotony')).not.toBeInTheDocument();
        expect(screen.queryByText('Drift')).not.toBeInTheDocument();
        expect(screen.queryByText('Fatigue')).not.toBeInTheDocument();
    });

    // Monotony ≥ 1.5 and decoupling ≥ 8% are the runner-relevant alarm thresholds.
    it('renders the metric chips in their alert tone past the alarm thresholds', () => {
        render(
            <WeekSection
                bucket={bucket()}
                snapshot={snapshot({ monotony: 2.1, avg_decoupling: 9.4 })}
                notes={{}}
                moods={{}}
                filtered={false}
            />,
        );

        expect(screen.getByText('2.10').parentElement).toHaveClass(
            'bg-mood-gassed/15',
        );
        expect(screen.getByText('9.4%').parentElement).toHaveClass(
            'bg-mood-gassed/15',
        );
    });

    // Real filtering removes non-matching runs, so a week loses the context the
    // old dimmed rows conveyed. The snapshot's own total names the gap.
    describe('hidden-run count', () => {
        it('names how many runs the filter hid in that week', () => {
            render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot({ runs: 4 })}
                    notes={{}}
                    moods={{}}
                    filtered
                />,
            );

            expect(
                screen.getByText(/3 other runs this week don't match/),
            ).toBeInTheDocument();
            expect(screen.getByText('1 of 4 run')).toBeInTheDocument();
        });

        it('says nothing when the filter hid nothing', () => {
            render(
                <WeekSection
                    bucket={bucket([run(101, 'A'), run(102, 'B')])}
                    snapshot={snapshot({ runs: 2 })}
                    notes={{}}
                    moods={{}}
                    filtered
                />,
            );

            expect(
                screen.queryByText(/don't match the filter/),
            ).not.toBeInTheDocument();
        });

        it('says nothing when no filter is active', () => {
            render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot({ runs: 4 })}
                    notes={{}}
                    moods={{}}
                    filtered={false}
                />,
            );

            expect(
                screen.queryByText(/don't match the filter/),
            ).not.toBeInTheDocument();
        });

        // The in-progress week's snapshot is recomputed by a queued worker, so it
        // can lag the live bucket and would report a bogus gap.
        it('stays quiet for the in-progress week', () => {
            render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot({ runs: 4, is_current_week: true })}
                    notes={{}}
                    moods={{}}
                    filtered
                />,
            );

            expect(
                screen.queryByText(/don't match the filter/),
            ).not.toBeInTheDocument();
        });

        it('stays quiet when the snapshot has no run count', () => {
            render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot({ runs: null })}
                    notes={{}}
                    moods={{}}
                    filtered
                />,
            );

            expect(
                screen.queryByText(/don't match the filter/),
            ).not.toBeInTheDocument();
        });
    });

    describe('weekly recap notification', () => {
        it('shows a muted button that nudges (no send) when no channel is wired', () => {
            // telegramConnected defaults to undefined (falsy) in beforeEach.
            vi.mocked(router.post).mockReset();
            render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot()}
                    notes={{}}
                    moods={{}}
                    filtered={false}
                />,
            );

            fireEvent.click(screen.getByText('Send notification'));
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
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot()}
                    notes={{}}
                    moods={{}}
                    filtered={false}
                />,
            );

            fireEvent.click(screen.getByText('Send notification'));
            expect(router.post).toHaveBeenCalledWith(
                '/recaps/weekly/7/send',
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });

        it('offers no send while the narration is pending, and keeps the rule-based fallback visible so the block is not empty', () => {
            render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot({
                        is_current_week: true,
                        recap_analysis: recapAnalysis({
                            status: 'pending',
                            content: null,
                        }),
                    })}
                    notes={{}}
                    moods={{}}
                    filtered={false}
                />,
            );

            expect(
                screen.queryByText('Send notification'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByText(/You ran 4x this week for 35.5 km/),
            ).toBeInTheDocument();
        });

        it('falls back to a plain nudge when the snapshot has no numbers to quote', () => {
            render(
                <WeekSection
                    bucket={bucket()}
                    snapshot={snapshot({
                        runs: null,
                        distance_km: null,
                        form: null,
                        form_status: null,
                        is_current_week: true,
                        recap_analysis: recapAnalysis({
                            status: 'pending',
                            content: null,
                        }),
                    })}
                    notes={{}}
                    moods={{}}
                    filtered={false}
                />,
            );

            expect(
                screen.getByText(/No data for this week yet, hang tight/),
            ).toBeInTheDocument();
        });
    });
});
