import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { AnalysisPayload, WeeklySnapshotWithRecap } from '@/types/inertia';

import { run } from '@/pages/Activities/runFixture';
import {
    type RunWithDetail,
    type WeekBucket,
} from '@/pages/Activities/weekBuckets';
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

function bucket(runs: RunWithDetail[] = [run(101, 'Morning')]): WeekBucket {
    return {
        weekStart: '2026-05-18',
        weekEnding: '2026-05-24',
        label: 'may 18–24',
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
        avg_decoupling_v2: 3.2,
        monotony: 1.2,
        strain: 384,
        is_current_week: false,
        is_chain_head: true,
        recap_analysis: recapAnalysis(),
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
                bucket={bucket([run(101, 'Morning'), run(102, 'Evening')])}
                snapshot={null}
                notes={{}}
                moods={{}}
            />,
        );

        // Header stats count up from 0, so wait for them to settle.
        await waitFor(() =>
            expect(screen.getByText(/2 runs/)).toBeInTheDocument(),
        );
        expect(screen.getByText(/10\.0 km/)).toBeInTheDocument();
        expect(screen.getByText(/100 TRIMP/)).toBeInTheDocument();
        expect(screen.getAllByTestId('run-row').length).toBe(2);
    });

    it('shows the snapshot totals (not the range-truncated bucket count) when a snapshot exists', async () => {
        render(
            <WeekSection
                bucket={bucket()}
                snapshot={snapshot()}
                notes={{}}
                moods={{}}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText(/4 runs/)).toBeInTheDocument(),
        );
        expect(screen.getByText(/35\.5 km/)).toBeInTheDocument();
        expect(screen.getByText(/Consistent week/)).toBeInTheDocument();
    });

    it('shows the live bucket totals (not a stale snapshot) for the in-progress week', async () => {
        render(
            <WeekSection
                bucket={bucket([run(101, 'Morning'), run(102, 'Evening')])}
                snapshot={snapshot({
                    distance_km: 5,
                    runs: 1,
                    weekly_trimp: 50,
                    is_current_week: true,
                })}
                notes={{}}
                moods={{}}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText(/2 runs/)).toBeInTheDocument(),
        );
        expect(screen.getByText(/10\.0 km/)).toBeInTheDocument();
    });

    it('renders "1 run" without an s for a single run', async () => {
        render(
            <WeekSection
                bucket={bucket([run(101, 'Morning')])}
                snapshot={null}
                notes={{}}
                moods={{}}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText(/1 run\b/)).toBeInTheDocument(),
        );
        expect(screen.queryByText(/1 runs/)).not.toBeInTheDocument();
    });

    it('renders no metric stat line when the snapshot carries no metrics', () => {
        render(
            <WeekSection
                bucket={bucket()}
                snapshot={snapshot({
                    atl_7d: null,
                    ctl_42d: null,
                    form: null,
                    form_status: null,
                    avg_decoupling_v2: null,
                    monotony: null,
                    strain: null,
                })}
                notes={{}}
                moods={{}}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'variety' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'drift' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'short-term load' }),
        ).not.toBeInTheDocument();
    });

    // The alert-tone thresholds themselves belong to WeeklyStatLine; this
    // only pins that the section keeps handing it the week's numbers.
    it('renders the stat line metrics past the alarm thresholds', () => {
        render(
            <WeekSection
                bucket={bucket()}
                snapshot={snapshot({ monotony: 2.1, avg_decoupling_v2: 9.4 })}
                notes={{}}
                moods={{}}
            />,
        );

        expect(screen.getByText('2.10')).toBeInTheDocument();
        expect(screen.getByText('9.4%')).toBeInTheDocument();
    });

    describe('weekly recap fallback', () => {
        it('keeps the rule-based fallback visible while the narration is pending so the block is not empty', () => {
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
                />,
            );

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
                />,
            );

            expect(
                screen.getByText(/No data for this week yet, hang tight/),
            ).toBeInTheDocument();
        });
    });

    it('shows an unknown TRIMP, not a zero, for a summary-only week', () => {
        render(
            <WeekSection
                bucket={{ ...bucket(), totalTrimp: null }}
                snapshot={snapshot({
                    weekly_trimp: null,
                    monotony: null,
                    strain: null,
                    is_current_week: false,
                })}
                notes={{}}
                moods={{}}
            />,
        );
        expect(screen.getByText(/— TRIMP/)).toBeInTheDocument();
        expect(screen.queryByText(/\b0 TRIMP\b/)).not.toBeInTheDocument();
    });

    it('keeps the week label and the stats line from wrapping', () => {
        render(
            <WeekSection
                bucket={bucket([run(101, 'Morning')])}
                snapshot={null}
                notes={{}}
                moods={{}}
            />,
        );
        expect(screen.getByText('may 18–24').className).toContain(
            'whitespace-nowrap',
        );
        expect(screen.getByText(/TRIMP/).className).toContain(
            'whitespace-nowrap',
        );
    });
});
