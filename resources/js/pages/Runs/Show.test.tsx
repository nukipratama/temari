import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RunsShow from './Show';
import { setMockPage } from '@/test/setup';
import type { ActivityDetail, AnalysisPayload, RunCard, StoryLine } from '@/types/inertia';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

const detail: ActivityDetail & {
    stream_summary?: Record<string, unknown> | null;
    max_heartrate?: number | null;
    average_cadence?: number | null;
    weather_temp_c?: number | null;
    weather_humidity_pct?: number | null;
    weather_rain_detected?: boolean | null;
} = {
    id: 11,
    activity_id: 99,
    name: 'Morning Run',
    start_date_local: '2026-05-10T07:00:00',
    distance: 10000,
    moving_time: 3600,
    average_heartrate: 150,
    trimp_edwards: 70,
    stream_summary: {
        zone_pct: { Z1: 10, Z2: 60, Z3: 20, Z4: 8, Z5: 2 },
        per_km: [
            { km: 1, pace: '6:00', avg_hr: 150, avg_cadence_spm: 170 },
            { km: 2, pace: '5:45', avg_hr: 155, avg_cadence_spm: 173 },
        ],
        decoupling_pct: 4.5,
        ascent_m: 50,
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

const runCard: RunCard = {
    id: 1,
    activity_id: 99,
    rarity: 'epic',
    special_move: 'Paru-paru Baja',
    badges: ['negative_split'],
};

const storyLine: StoryLine = {
    id: 1,
    user_id: 1,
    activity_id: 99,
    kind: 'post_run',
    mood: 'nyala',
    speech: null,
    sigil_pattern: 'ssss',
    for_date: null,
};

function speechAnalysis(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'Run solid banget',
        type: 'post_run_speech',
        subject_type: String.raw`App\Models\Activity`,
        subject_id: 99,
        discriminator: null,
        ...overrides,
    };
}

function insight(type: AnalysisPayload['type'], status: AnalysisPayload['status'] = 'pending'): AnalysisPayload {
    return {
        id: null,
        status,
        content: null,
        type,
        subject_type: String.raw`App\Models\Activity`,
        subject_id: 99,
        discriminator: null,
    };
}

const insightDefaults = {
    insightTechnical: insight('run_insight_technical'),
    insightSplits: insight('run_insight_splits'),
    insightZones: insight('run_insight_zones'),
} as const;

function renderShow(overrides: Partial<Parameters<typeof RunsShow>[0]> = {}) {
    return render(
        <RunsShow
            activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail }}
            detail={detail}
            card={runCard}
            storyLine={storyLine}
            speechAnalysis={speechAnalysis()}
            {...insightDefaults}
            isChainHead
            pastYou={null}
            {...overrides}
        />,
    );
}

describe('Runs/Show', () => {
    it('renders run name in the sky hero', () => {
        renderShow();
        expect(screen.getAllByText('Morning Run').length).toBeGreaterThan(0);
    });

    it('renders the DURASI hero tile with the HMS-formatted moving_time', () => {
        renderShow();
        // moving_time 3600s → 1:00:00 in the digital H:MM:SS form.
        expect(screen.getByText('DURASI')).toBeInTheDocument();
        expect(screen.getByText('1:00:00')).toBeInTheDocument();
    });

    it('renders the as-recorded date and start time in the hero', () => {
        renderShow();
        // start_date_local '2026-05-10T07:00:00' → wall-clock date + time, no zone shift.
        expect(screen.getByText('10 Mei 2026 · 07.00')).toBeInTheDocument();
    });

    it('renders the literal hero time even when serialized with a UTC Z', () => {
        renderShow({
            detail: { ...detail, start_date_local: '2026-06-09T06:52:54.000000Z' },
        });
        expect(screen.getByText('9 Jun 2026 · 06.52')).toBeInTheDocument();
    });

    it('renders the four-lens grid with the Kata Temari header', () => {
        renderShow();
        expect(screen.getByText('Kata Temari')).toBeInTheDocument();
        expect(screen.getByText('Cerita lari ini')).toBeInTheDocument();
        expect(screen.getByText('Terjemahan teknis')).toBeInTheDocument();
        expect(screen.getByText('Split paling seru')).toBeInTheDocument();
        expect(screen.getByText('Zona HR')).toBeInTheDocument();
    });

    it('renders the speech analysis text inside the Cerita panel', () => {
        renderShow();
        expect(screen.getByText(/Run solid banget/)).toBeInTheDocument();
    });

    it('embeds the kartu in the side panel when one exists', () => {
        renderShow();
        expect(screen.getByText('Paru-paru Baja')).toBeInTheDocument();
        const cardLink = screen.getAllByRole('link').find((el) => el.getAttribute('href') === '/kartu/1');
        expect(cardLink).toBeDefined();
    });

    it('omits the kartu side panel when card is null', () => {
        renderShow({ card: null });
        expect(screen.queryByText('Paru-paru Baja')).not.toBeInTheDocument();
        const cardLink = screen.queryAllByRole('link').find((el) => el.getAttribute('href') === '/kartu/1');
        expect(cardLink).toBeUndefined();
    });

    it('renders the map+weather panel with temp + location when present', () => {
        renderShow();
        expect(screen.getByText(/32°/)).toBeInTheDocument();
        expect(screen.getByText(/80% lembab/)).toBeInTheDocument();
        expect(screen.getByText('Senayan, Jakarta Pusat')).toBeInTheDocument();
    });

    it('hides the map area when no polyline is present', () => {
        const noPolyline = { ...detail, summary_polyline: null };
        const { container } = renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noPolyline }, detail: noPolyline });
        expect(container.querySelector('.animate-pulse')).toBeNull();
    });

    it('shows the map suspense fallback when a polyline IS present', () => {
        const withPolyline = { ...detail, summary_polyline: 'abc123' };
        const { container } = renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: withPolyline }, detail: withPolyline });
        expect(container.querySelector('.animate-pulse')).not.toBeNull();
    });

    it('renders the splits per-km section + highlights the fastest km', () => {
        renderShow();
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        // Fastest is the 5:45 km (km 2): caption leads with the km, value is the highlight.
        expect(screen.getByText(/Paling kenceng di km 2/)).toBeInTheDocument();
        expect(screen.getByText('5:45/km')).toBeInTheDocument();
    });

    it('renders the past-you strip when journeyMatch is present', () => {
        renderShow({
            pastYou: {
                past: { start_date_local: '2026-04-01T07:00' },
                pace_diff_sec: 10,
                hr_diff_bpm: -3,
                days_ago: 30,
            },
        });
        expect(screen.getByText(/30 hari lalu/)).toBeInTheDocument();
    });

    it('falls back to "Lari" when detail.name is null', () => {
        const noName = { ...detail, name: null };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noName }, detail: noName });
        expect(screen.getAllByText(/Lari/).length).toBeGreaterThan(0);
    });

    it('handles null distance/moving_time gracefully (dash in hero stats)', () => {
        const noDist = { ...detail, distance: null, moving_time: null };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noDist }, detail: noDist });
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it('exposes the decoupling tile as a warning when |decoupling| > 8%', () => {
        const hot = { ...detail, stream_summary: { ...(detail.stream_summary ?? {}), decoupling_pct: 12.5 } };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: hot }, detail: hot });
        expect(screen.getByText('+12.5%')).toBeInTheDocument();
    });

    it('skips the decoupling + ascent tiles when their values are non-numeric (no "NaN%")', () => {
        const garbled = {
            ...detail,
            stream_summary: {
                ...(detail.stream_summary ?? {}),
                decoupling_pct: 'oops',
                ascent_m: 'n/a',
            },
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: garbled }, detail: garbled });
        expect(screen.queryByText(/NaN/)).not.toBeInTheDocument();
        expect(screen.queryByText('DECOUPLING')).not.toBeInTheDocument();
        expect(screen.queryByText('ASCENT')).not.toBeInTheDocument();
    });

    it('renders the empty-tiles fallback when detail has no technical numbers', () => {
        const bare = {
            ...detail,
            stream_summary: null,
            average_heartrate: null,
            max_heartrate: null,
            average_cadence: null,
            trimp_edwards: null,
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: bare }, detail: bare });
        expect(screen.getByText(/Detail teknis-nya belum kebaca/)).toBeInTheDocument();
    });

    it('parses a string-form pace_sec from splits when pace_sec is missing', () => {
        const withStringPace = {
            ...detail,
            stream_summary: {
                ...(detail.stream_summary ?? {}),
                per_km: [{ km: 1, pace: '5:30' }, { km: 2, pace: '5:20' }],
            },
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: withStringPace }, detail: withStringPace });
        // The splits table renders with parsed paces; we only assert the structure rendered.
        expect(screen.getAllByText(/5:/).length).toBeGreaterThan(0);
    });
});
