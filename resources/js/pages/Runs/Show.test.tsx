import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type {
    ActivityDetail,
    AnalysisPayload,
    StoryLine,
} from '@/types/inertia';

import { setMockPage, stubSyncAnimationFrame } from '@/test/setup';

import RunsShow from './Show';

// RouteMap is lazy()-loaded and wraps real leaflet/react-leaflet/@mapbox/polyline
// (see its own dedicated test file for those stubs). Stub it here too so the
// dynamic import resolving after a test's assertions doesn't try to mount the
// real map against jsdom without those stubs.
vi.mock('@/components/run/RouteMap', () => ({
    default: () => <div data-testid="route-map" />,
}));

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

const detail: ActivityDetail = {
    id: 11,
    activity_id: 99,
    name: 'Morning Run',
    start_date_local: '2026-05-10T07:00:00',
    distance: 10000,
    total_elevation_gain: 120,
    elapsed_time: 3600,
    average_heartrate: 150,
    trimp_edwards: 70,
    stream_summary: {
        per_km: [
            { km: 1, pace: '6:00', avg_hr: 150, avg_cadence_spm: 170 },
            { km: 2, pace: '5:45', avg_hr: 155, avg_cadence_spm: 173 },
        ],
        decoupling_pct: 4.5,
        stopped_time_sec: 30,
        stop_count: 2,
    },
    max_heartrate: 175,
    average_cadence: 85,
    weather_temp_c: 32,
    weather_humidity_pct: 80,
    weather_rain_detected: true,
    location_name: 'Senayan, Jakarta Pusat',
};

const runCard: NonNullable<Parameters<typeof RunsShow>[0]['card']> = {
    id: 1,
    activity_id: 99,
    rarity: 'epic',
    special_move: 'Iron Lungs',
    badges: ['negative_split'],
    edition: { index: 3, total: 5 },
    flavor_analysis: {
        id: 2,
        status: 'done',
        content: 'Strong breathing all the way to the end.',
        type: 'card_flavor',
        subject_type: String.raw`App\Models\RunCard`,
        subject_id: 1,
        discriminator: null,
    },
    public_share_url: '/activities/255',
};

const storyLine: StoryLine = {
    id: 1,
    user_id: 1,
    activity_id: 99,
    kind: 'post_run',
    mood: 'blazing',
    speech: null,
    sigil_pattern: 'ssss',
    for_date: null,
};

function speechAnalysis(
    overrides: Partial<AnalysisPayload> = {},
): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'Solid run',
        type: 'post_run_speech',
        subject_type: String.raw`App\Models\Activity`,
        subject_id: 99,
        discriminator: null,
        ...overrides,
    };
}

function runInsight(
    status: AnalysisPayload['status'] = 'pending',
    content: string | null = null,
): AnalysisPayload {
    return {
        id: status === 'pending' ? null : 1,
        status,
        content,
        type: 'run_insight',
        subject_type: String.raw`App\Models\Activity`,
        subject_id: 99,
        discriminator: null,
    };
}

function renderShow(overrides: Partial<Parameters<typeof RunsShow>[0]> = {}) {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
    return render(
        <RunsShow
            activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail }}
            detail={detail}
            card={runCard}
            storyLine={storyLine}
            speechAnalysis={speechAnalysis()}
            runInsight={runInsight(
                'done',
                JSON.stringify([
                    {
                        anchor: 'metric:decoupling',
                        text: 'Decoupling stayed tight all the way through.',
                        value: '+3.2%',
                        delta: null,
                    },
                ]),
            )}
            moodFallback="chill"
            isChainHead
            pastYou={null}
            {...overrides}
        />,
    );
}

describe('Runs/Show', () => {
    it('coach-marks the share action on a first visit', () => {
        window.localStorage.clear();
        stubSyncAnimationFrame();
        renderShow();
        expect(
            screen.getByRole('dialog', { name: 'Share the card' }),
        ).toBeInTheDocument();
    });

    it('says nothing about hydration when the run is already detailed', () => {
        renderShow();
        expect(screen.queryByText(/still filling this run in/i)).toBeNull();
    });

    it('explains the thin page while the deeper fetch is queued', () => {
        renderShow({ awaitingDetail: true });
        expect(
            screen.getByText(/still filling this run in/i),
        ).toBeInTheDocument();
    });

    it('renders run name in the sky hero', () => {
        renderShow();
        expect(screen.getAllByText('Morning Run').length).toBeGreaterThan(0);
    });

    it('uses the backend moodFallback when there is no post-run story line', () => {
        renderShow({ storyLine: null, moodFallback: 'wobbly' });
        expect(screen.getAllByText('Wobbly').length).toBeGreaterThan(0);
    });

    it('feeds the detail tiles from the stream summary', () => {
        renderShow({
            detail: {
                ...detail,
                stream_summary: {
                    ...detail.stream_summary,
                    max_grade_pct: 11,
                    gap_pace: '5:20',
                },
            },
        });
        expect(screen.getByText('CLIMB')).toBeInTheDocument();
        expect(screen.getByText('GAP')).toBeInTheDocument();
    });

    it('renders the DURATION hero tile with the HMS-formatted elapsed_time', () => {
        renderShow();
        // elapsed_time 3600s → 1:00:00 in the digital H:MM:SS form (hero tile + the
        // kartu section below it both show it).
        expect(screen.getByText('DURATION')).toBeInTheDocument();
        expect(screen.getAllByText('1:00:00').length).toBeGreaterThan(0);
    });

    it('renders the as-recorded date and start time in the hero', () => {
        renderShow();
        // start_date_local '2026-05-10T07:00:00' → wall-clock date + time, no zone shift.
        expect(screen.getByText('10 May 2026 · 07:00')).toBeInTheDocument();
    });

    it('renders the literal hero time even when serialized with a UTC Z', () => {
        renderShow({
            detail: {
                ...detail,
                start_date_local: '2026-06-09T06:52:54.000000Z',
            },
        });
        expect(screen.getByText('9 Jun 2026 · 06:52')).toBeInTheDocument();
    });

    it('renders the run lenses with the What Temari Says header', () => {
        renderShow();
        expect(screen.getByText('What Temari says')).toBeInTheDocument();
        expect(screen.getByText("This run's story")).toBeInTheDocument();
        expect(screen.getByText('What stood out')).toBeInTheDocument();
    });

    it('renders the speech analysis text inside the story panel', () => {
        renderShow();
        expect(screen.getByText(/Solid run/)).toBeInTheDocument();
    });

    it('renders the run-insight claim inside the adaptive panel', () => {
        renderShow();
        expect(
            screen.getByText('Decoupling stayed tight all the way through.'),
        ).toBeInTheDocument();
        expect(screen.getByText('+3.2%')).toBeInTheDocument();
    });

    it('renders the kartu section with its own view (no link elsewhere) when a card exists', () => {
        renderShow();
        expect(screen.getAllByText('Iron Lungs').length).toBeGreaterThan(0);
        expect(screen.getByText('Share')).toBeInTheDocument();
    });

    it('omits the kartu section when card is null', () => {
        renderShow({ card: null });
        expect(screen.queryByText('Iron Lungs')).not.toBeInTheDocument();
        expect(screen.queryByText('Share')).not.toBeInTheDocument();
    });

    it('mounts the map+weather panel with the run detail', () => {
        renderShow();
        expect(screen.getByText(/32°/)).toBeInTheDocument();
        expect(screen.getByText('Senayan')).toBeInTheDocument();
    });

    it('renders the splits per-km section from the stream summary', () => {
        renderShow();
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.getByText(/Fastest at km 2/)).toBeInTheDocument();
    });

    it('stacks the laps section under the splits section, both always rendered', () => {
        const withLaps = {
            ...detail,
            stream_summary: {
                ...detail.stream_summary,
                laps: [
                    {
                        lap: 1,
                        distance_m: 1000,
                        elapsed_sec: 360,
                        pace: '6:00',
                    },
                    { lap: 2, distance_m: 647, elapsed_sec: 233, pace: '6:00' },
                ],
            },
        };
        renderShow({ detail: withLaps });
        const splits = screen.getByText('Splits per km');
        const lapsHeading = screen.getByText('Laps');
        expect(splits).toBeInTheDocument();
        expect(lapsHeading).toBeInTheDocument();
        expect(screen.getByText('647m')).toBeInTheDocument();
        expect(
            splits.compareDocumentPosition(lapsHeading) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('omits the laps section when the run carries no laps', () => {
        renderShow();
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.queryByText('Laps')).not.toBeInTheDocument();
    });

    it('omits the splits section when the run has neither full kms nor a partial', () => {
        const noSplits = { ...detail, stream_summary: { decoupling_pct: 4.5 } };
        renderShow({
            activity: {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-10',
                detail: noSplits,
            },
            detail: noSplits,
        });
        expect(screen.queryByText('Splits per km')).not.toBeInTheDocument();
    });

    it('still renders the splits table for a sub-1km run that has only a partial', () => {
        renderShow({
            detail: {
                ...detail,
                stream_summary: {
                    partial_split: { distance_m: 800, pace: '5:00' },
                },
            },
        });
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.getByText('0.8 KM')).toBeInTheDocument();
    });

    it('promotes the past-you comparison into the hero when a match exists', () => {
        renderShow({
            pastYou: {
                past: { start_date_local: '2026-04-01T07:00' },
                pace_diff_sec: 10,
                hr_diff_bpm: -3,
                days_ago: 30,
            },
        });
        expect(screen.getByText(/30 days ago/)).toBeInTheDocument();
        expect(screen.getByText('You vs past you')).toBeInTheDocument();
        expect(screen.getByText(/sec\/km faster/)).toBeInTheDocument();
    });

    it('omits the past-you band entirely when there is no match', () => {
        renderShow({ pastYou: null });
        expect(screen.queryByText('You vs past you')).not.toBeInTheDocument();
    });

    it('offers the per-run ask panel', () => {
        renderShow({});
        expect(screen.getByText('Ask about this run')).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText('Ask anything about this run'),
        ).toBeInTheDocument();
    });

    it('tells the ask panel the toolbox is thin on a summary-only run', () => {
        renderShow({
            activity: {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-10',
                ingest_state: 'summary',
                detail,
            },
        });
        expect(
            screen.getByText(/no splits,\s+zones or terrain yet/),
        ).toBeInTheDocument();
    });

    it('falls back to "run" when detail.name is null', () => {
        const noName = { ...detail, name: null };
        renderShow({
            activity: {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-10',
                detail: noName,
            },
            detail: noName,
        });
        expect(screen.getAllByText(/^run$/).length).toBeGreaterThan(0);
    });

    it('handles null distance/elapsed_time gracefully (dash in hero stats)', () => {
        const noDist = { ...detail, distance: null, elapsed_time: null };
        renderShow({
            activity: {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-10',
                detail: noDist,
            },
            detail: noDist,
        });
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it('shows elevation gain as the ELEVATION hero tile, not a secondary ASCENT tile', async () => {
        renderShow();
        expect(screen.getByText('ELEVATION')).toBeInTheDocument();
        // The tile value count-ups from 0 on mount, so its final text lands async.
        await waitFor(() =>
            expect(screen.getByText('120')).toBeInTheDocument(),
        );
        expect(screen.queryByText('ASCENT')).not.toBeInTheDocument();
    });

    it('falls back to an empty stream summary when the run has none', () => {
        const bare = {
            ...detail,
            stream_summary: null,
            average_heartrate: null,
            max_heartrate: null,
            average_cadence: null,
            trimp_edwards: null,
            weather_temp_c: null,
        };
        renderShow({
            activity: {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-10',
                detail: bare,
            },
            detail: bare,
        });
        expect(
            screen.getByText(/Technical detail hasn't been read yet/),
        ).toBeInTheDocument();
    });
});
