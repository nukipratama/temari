import { fireEvent, render, screen } from '@testing-library/react';
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

// The share popup carries the ~1200-line canvas engine behind a lazy import;
// this file only asserts that the button reaches it.
vi.mock('@/components/card/ShareCardModal', () => ({
    default: ({ onClose }: { onClose: () => void }) => (
        <div data-testid="share-card-modal">
            <button type="button" onClick={onClose}>
                Close share
            </button>
        </div>
    ),
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
            activity={{
                id: 99,
                user_id: 1,
                strava_external_id: 4821,
                analyzed_at: '2026-05-10',
                detail,
            }}
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

/** Document order of two section headings on the rendered page. */
function precedes(first: string, second: string): boolean {
    const a = screen.getByText(first);
    const b = screen.getByText(second);
    return Boolean(
        a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING,
    );
}

describe('Runs/Show', () => {
    it('renders the prototype section list in order', () => {
        renderShow();
        expect(screen.getByText('Activity')).toBeInTheDocument();
        expect(screen.getByText('What Temari says')).toBeInTheDocument();
        expect(screen.getByText('Ask about this run')).toBeInTheDocument();
        expect(screen.getByText('The breakdown')).toBeInTheDocument();
        expect(screen.getByText('Vitals')).toBeInTheDocument();
        expect(screen.getByText('Splits per km')).toBeInTheDocument();

        expect(precedes('What Temari says', 'Ask about this run')).toBe(true);
        expect(precedes('Ask about this run', 'The breakdown')).toBe(true);
        expect(precedes('The breakdown', 'Vitals')).toBe(true);
        expect(precedes('Vitals', 'Splits per km')).toBe(true);
    });

    it('renders the run in the hero, with its as-recorded date and time', () => {
        renderShow();
        expect(
            screen.getByRole('heading', { name: 'Morning Run' }),
        ).toBeInTheDocument();
        expect(screen.getByText('10 may 2026 · 07:00')).toBeInTheDocument();
    });

    it('uses the backend moodFallback when there is no post-run story line', () => {
        renderShow({ storyLine: null, moodFallback: 'wobbly' });
        expect(screen.getByText('wobbly')).toBeInTheDocument();
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

    it('withholds everything below the hero until the deeper fetch lands', () => {
        renderShow({
            awaitingDetail: true,
            pastYou: {
                past: { start_date_local: '2026-04-01T07:00' },
                pace_diff_sec: 10,
                hr_diff_bpm: -3,
                days_ago: 30,
            },
        });
        // The hero and the provenance footer still render; the rest would only
        // be empty panels until the splits/zones/effort arrive.
        expect(
            screen.getByRole('heading', { name: 'Morning Run' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('You vs past you')).not.toBeInTheDocument();
        expect(screen.queryByText('What Temari says')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Ask about this run'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('The breakdown')).not.toBeInTheDocument();
        expect(screen.queryByText('Splits per km')).not.toBeInTheDocument();
    });

    it('slots the past-you card between the hero and the narration', () => {
        renderShow({
            pastYou: {
                past: { start_date_local: '2026-04-01T07:00' },
                pace_diff_sec: 10,
                hr_diff_bpm: -3,
                days_ago: 30,
            },
        });
        expect(screen.getByText('You vs past you')).toBeInTheDocument();
        expect(precedes('You vs past you', 'What Temari says')).toBe(true);
    });

    it('omits the past-you card entirely when there is no match', () => {
        renderShow({ pastYou: null });
        expect(screen.queryByText('You vs past you')).not.toBeInTheDocument();
    });

    it('feeds the narration card both analysis rows', () => {
        renderShow();
        expect(screen.getByText(/Solid run/)).toBeInTheDocument();
        expect(
            screen.getByText('Decoupling stayed tight all the way through.'),
        ).toBeInTheDocument();
        expect(screen.getByText('+3.2%')).toBeInTheDocument();
    });

    it('offers the per-run ask panel', () => {
        renderShow();
        expect(
            screen.getByPlaceholderText('ask anything about this run'),
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

    it('stacks the laps carousel under the splits chart', () => {
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
        expect(precedes('Splits per km', 'Laps')).toBe(true);
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

    it('still renders the splits chart for a sub-1km run that has only a partial', () => {
        renderShow({
            detail: {
                ...detail,
                stream_summary: {
                    partial_split: { distance_m: 800, pace: '5:00' },
                },
            },
        });
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
    });

    it('falls back to the vitals empty panel when the run recorded nothing', () => {
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

    it('mounts the map + conditions slab with the run detail', () => {
        renderShow();
        expect(screen.getByText(/32°/)).toBeInTheDocument();
        expect(screen.getByText('Senayan')).toBeInTheDocument();
    });

    it('opens the share popup from the hero when the run has a card', async () => {
        renderShow();
        fireEvent.click(screen.getByRole('button', { name: /Share/ }));
        // The popup carries the canvas engine behind a lazy import.
        expect(
            await screen.findByTestId('share-card-modal'),
        ).toBeInTheDocument();
    });

    it('closes the share popup again from inside it', async () => {
        renderShow();
        fireEvent.click(screen.getByRole('button', { name: /^Share$/ }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Close share' }),
        );
        expect(
            screen.queryByTestId('share-card-modal'),
        ).not.toBeInTheDocument();
    });

    it('hides the share button when the run has no card to share', () => {
        renderShow({ card: null });
        expect(
            screen.queryByRole('button', { name: /Share/ }),
        ).not.toBeInTheDocument();
    });

    it('draws no collectible card block — only the share button survives it', () => {
        renderShow();
        expect(screen.queryByText('Iron Lungs')).not.toBeInTheDocument();
        expect(screen.queryByText(/Epic/)).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Strong breathing all the way to the end/),
        ).not.toBeInTheDocument();
    });

    it('coach-marks the share action on a first visit', () => {
        window.localStorage.clear();
        stubSyncAnimationFrame();
        renderShow();
        expect(
            screen.getByRole('dialog', { name: 'share the card' }),
        ).toBeInTheDocument();
    });

    it('closes with a Strava provenance footer carrying the run’s own id', () => {
        const { container } = renderShow();
        expect(container.querySelector('footer')).toHaveTextContent(
            'Synced from Strava · may 10 · 00:00 · #4821',
        );
    });

    it('drops the id from the footer when the run has no Strava id', () => {
        const { container } = renderShow({
            activity: {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-10',
                detail,
            },
        });
        const footer = container.querySelector('footer');
        expect(footer).toHaveTextContent('Synced from Strava');
        expect(footer?.textContent).not.toContain('#');
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
        expect(
            screen.getByRole('heading', { name: 'run' }),
        ).toBeInTheDocument();
    });
});
