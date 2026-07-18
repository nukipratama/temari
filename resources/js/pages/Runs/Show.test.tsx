import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import RunsShow from './Show';
import { setMockPage } from '@/test/setup';
import type { ActivityDetail, AnalysisPayload, StoryLine } from '@/types/inertia';

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

const detail: ActivityDetail & {
    stream_summary?: Record<string, unknown> | null;
    max_heartrate?: number | null;
    average_cadence?: number | null;
    weather_temp_c?: number | null;
    weather_humidity_pct?: number | null;
    weather_rain_detected?: boolean | null;
    weather_wind_speed_kmh?: number | null;
    weather_wind_gust_kmh?: number | null;
    weather_wind_direction_deg?: number | null;
} = {
    id: 11,
    activity_id: 99,
    name: 'Morning Run',
    start_date_local: '2026-05-10T07:00:00',
    distance: 10000,
    total_elevation_gain: 120,
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

const runCard: NonNullable<Parameters<typeof RunsShow>[0]['card']> = {
    id: 1,
    activity_id: 99,
    rarity: 'epic',
    special_move: 'Paru-paru Baja',
    badges: ['negative_split'],
    edition: { index: 3, total: 5 },
    flavor_analysis: {
        id: 2,
        status: 'done',
        content: 'Napas kuat sampai akhir.',
        type: 'card_flavor',
        subject_type: String.raw`App\Models\RunCard`,
        subject_id: 1,
        discriminator: null,
    },
    public_share_url: '/aktivitas/255',
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

function renderShow(
    overrides: Partial<Parameters<typeof RunsShow>[0]> = {},
    { telegramConnected = false }: { telegramConnected?: boolean } = {},
) {
    // telegramConnected is now a shared Inertia prop, read via usePage.
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
        telegramConnected,
    });
    return render(
        <RunsShow
            activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail }}
            detail={detail}
            card={runCard}
            storyLine={storyLine}
            speechAnalysis={speechAnalysis()}
            {...insightDefaults}
            moodFallback="adem"
            isChainHead
            telegramRetryAfterSeconds={null}
            pastYou={null}
            relativeEffort={null}
            {...overrides}
        />,
    );
}

describe('Runs/Show', () => {
    it('renders run name in the sky hero', () => {
        renderShow();
        expect(screen.getAllByText('Morning Run').length).toBeGreaterThan(0);
    });

    it('uses the backend moodFallback when there is no post-run story line', () => {
        renderShow({ storyLine: null, moodFallback: 'oleng' });
        expect(screen.getAllByText('Oleng').length).toBeGreaterThan(0);
    });

    it('shows the relative-effort sub-line under the TRIMP tile when banded', () => {
        renderShow({ relativeEffort: { trimp: 98, baseline: 70, ratio: 1.4, band: 'well_above' } });
        expect(screen.getByText('lebih berat dari biasanya')).toBeInTheDocument();
    });

    it('shows no relative-effort sub-line when the baseline is too thin (null band)', () => {
        renderShow({ relativeEffort: { trimp: 98, baseline: null, ratio: null, band: null } });
        expect(screen.queryByText(/dari biasanya/)).not.toBeInTheDocument();
    });

    it('shows TANJAKAN and GAP detail tiles on a hilly run', () => {
        renderShow({
            detail: { ...detail, stream_summary: { ...detail.stream_summary, max_grade_pct: 11, gap_pace: '5:20' } },
        });
        expect(screen.getByText('TANJAKAN')).toBeInTheDocument();
        expect(screen.getByText('11%')).toBeInTheDocument();
        expect(screen.getByText('GAP')).toBeInTheDocument();
        expect(screen.getByText('5:20')).toBeInTheDocument();
    });

    it('hides the grade tiles on a flat run', () => {
        renderShow({
            detail: { ...detail, stream_summary: { ...detail.stream_summary, max_grade_pct: 1, gap_pace: '5:20' } },
        });
        expect(screen.queryByText('TANJAKAN')).not.toBeInTheDocument();
        expect(screen.queryByText('GAP')).not.toBeInTheDocument();
    });

    it('renders the DURASI hero tile with the HMS-formatted moving_time', () => {
        renderShow();
        // moving_time 3600s → 1:00:00 in the digital H:MM:SS form (hero tile + the
        // kartu section below it both show it).
        expect(screen.getByText('DURASI')).toBeInTheDocument();
        expect(screen.getAllByText('1:00:00').length).toBeGreaterThan(0);
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

    it('renders the kartu section with its own view (no link elsewhere) when a card exists', () => {
        renderShow();
        expect(screen.getAllByText('Paru-paru Baja').length).toBeGreaterThan(0);
        expect(screen.getByText('Bagikan')).toBeInTheDocument();
        expect(screen.getByText('Buka ulang kartu')).toBeInTheDocument();
        expect(screen.getByText(/Kenapa dapet Istimewa/)).toBeInTheDocument();
    });

    it('omits the kartu section when card is null', () => {
        renderShow({ card: null });
        expect(screen.queryByText('Paru-paru Baja')).not.toBeInTheDocument();
        expect(screen.queryByText('Bagikan')).not.toBeInTheDocument();
    });

    it('surfaces an error and does not reveal when the replay POST fails (419/429/500)', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 419 });
        const original = globalThis.fetch;
        globalThis.fetch = fetchMock as unknown as typeof fetch;
        vi.mocked(router.reload).mockReset();
        try {
            renderShow();
            await act(async () => {
                fireEvent.click(screen.getByText('Buka ulang kartu'));
            });
            expect(await screen.findByText(/Gagal buka ulang kartu/)).toBeInTheDocument();
            expect(router.reload).not.toHaveBeenCalledWith({ only: ['pendingReveal'] });
        } finally {
            globalThis.fetch = original;
        }
    });

    it('reloads the pendingReveal prop on a successful replay POST', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true });
        const original = globalThis.fetch;
        globalThis.fetch = fetchMock as unknown as typeof fetch;
        vi.mocked(router.reload).mockReset();
        try {
            renderShow();
            await act(async () => {
                fireEvent.click(screen.getByText('Buka ulang kartu'));
            });
            await waitFor(() => expect(router.reload).toHaveBeenCalledWith({ only: ['pendingReveal'] }));
            expect(screen.queryByText(/Gagal buka ulang kartu/)).not.toBeInTheDocument();
        } finally {
            globalThis.fetch = original;
        }
    });

    it('renders the map+weather panel with temp + location when present', () => {
        renderShow();
        expect(screen.getByText(/32°/)).toBeInTheDocument();
        expect(screen.getByText(/80% lembab/)).toBeInTheDocument();
        // Splits across two lines (place / province): "Senayan" then "Jakarta Pusat".
        expect(screen.getByText('Senayan')).toBeInTheDocument();
        expect(screen.getByText('Jakarta Pusat')).toBeInTheDocument();
    });

    it('splits a 4-segment location into place + province,country lines (no truncation)', () => {
        const withFullLocation = { ...detail, location_name: 'Gelora Bung Karno, Jakarta Pusat, DKI Jakarta, Indonesia' };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: withFullLocation }, detail: withFullLocation });
        expect(screen.getByText('Gelora Bung Karno, Jakarta Pusat')).toBeInTheDocument();
        expect(screen.getByText('DKI Jakarta, Indonesia')).toBeInTheDocument();
    });

    it('hides the wind row when weather_wind_speed_kmh is absent', () => {
        renderShow();
        expect(screen.queryByText(/km\/j/)).not.toBeInTheDocument();
    });

    it('renders the wind row when weather_wind_speed_kmh is present', () => {
        const withWind = { ...detail, weather_wind_speed_kmh: 18 };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: withWind }, detail: withWind });
        expect(screen.getByText(/18 km\/j/)).toBeInTheDocument();
        expect(screen.queryByText(/gust/)).not.toBeInTheDocument();
    });

    it('shows the gust reading when it clears the 8 km/j delta threshold', () => {
        const withGust = { ...detail, weather_wind_speed_kmh: 18, weather_wind_gust_kmh: 30 };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: withGust }, detail: withGust });
        expect(screen.getByText(/gust 30/)).toBeInTheDocument();
    });

    it('hides the gust reading when it is within the 8 km/j delta threshold', () => {
        const smallGust = { ...detail, weather_wind_speed_kmh: 18, weather_wind_gust_kmh: 24 };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: smallGust }, detail: smallGust });
        expect(screen.getByText(/18 km\/j/)).toBeInTheDocument();
        expect(screen.queryByText(/gust/)).not.toBeInTheDocument();
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
        // Fastest is the 5:45 km (km 2): caption leads with the km, value is the highlight
        // (the kartu section's own "fastest km" stat can repeat the same value).
        expect(screen.getByText(/Paling kenceng di km 2/)).toBeInTheDocument();
        expect(screen.getAllByText('5:45/km').length).toBeGreaterThan(0);
    });

    it('renders a marked "sisa" partial row without crowning it fastest', () => {
        renderShow({
            detail: {
                ...detail,
                stream_summary: {
                    ...detail.stream_summary,
                    // A fast sisa (4:00) must not steal the "fastest km" crown from km 2 (5:45).
                    partial_split: { distance_m: 700, pace: '4:00', avg_hr: 158, avg_cadence_spm: 168 },
                },
            },
        });
        expect(screen.getByText('0.7 KM')).toBeInTheDocument();
        expect(screen.getByText(/putus-putus = sisa/)).toBeInTheDocument();
        // Crown stays on the full km, not the faster partial.
        expect(screen.getByText(/Paling kenceng di km 2/)).toBeInTheDocument();
    });

    it('still renders the splits table for a sub-1km run that has only a partial', () => {
        renderShow({
            detail: {
                ...detail,
                stream_summary: { partial_split: { distance_m: 800, pace: '5:00' } },
            },
        });
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.getByText('0.8 KM')).toBeInTheDocument();
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

    it('exposes the decoupling tile as a warning when |decoupling| > 8% on a cool run', () => {
        const cool = {
            ...detail,
            weather_temp_c: 20,
            stream_summary: { ...(detail.stream_summary ?? {}), decoupling_pct: 12.5 },
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: cool }, detail: cool });
        const value = screen.getByText('+12.5%');
        expect(value).toHaveClass('text-ember');
        expect(screen.getByText('napas melar di paruh kedua')).toBeInTheDocument();
    });

    it('softens the decoupling tile with a heat explanation when the run was hot', () => {
        const hot = {
            ...detail,
            weather_temp_c: 32,
            stream_summary: { ...(detail.stream_summary ?? {}), decoupling_pct: 12.5 },
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: hot }, detail: hot });
        const value = screen.getByText('+12.5%');
        expect(value).not.toHaveClass('text-ember');
        expect(screen.getByText('wajar, tadi panas 32°C')).toBeInTheDocument();
    });

    it('still flags a high decoupling on a run without weather data', () => {
        const noWeather = {
            ...detail,
            weather_temp_c: null,
            stream_summary: { ...(detail.stream_summary ?? {}), decoupling_pct: 12.5 },
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noWeather }, detail: noWeather });
        const value = screen.getByText('+12.5%');
        expect(value).toHaveClass('text-ember');
    });

    it('does not apply the heat explanation to a large negative decoupling on a hot run', () => {
        // Negative decoupling means HR:pace improved in the second half — heat only
        // ever explains a positive drift, so a strongly negative value on a hot run
        // must still read as a plain warning, not "wajar, tadi panas".
        const hotNegative = {
            ...detail,
            weather_temp_c: 32,
            stream_summary: { ...(detail.stream_summary ?? {}), decoupling_pct: -12.5 },
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: hotNegative }, detail: hotNegative });
        const value = screen.getByText('-12.5%');
        expect(value).toHaveClass('text-ember');
        expect(screen.getByText('napas melar di paruh kedua')).toBeInTheDocument();
        expect(screen.queryByText(/wajar, tadi panas/)).not.toBeInTheDocument();
    });

    it('skips the decoupling tile when its value is non-numeric (no "NaN%")', () => {
        const garbled = {
            ...detail,
            stream_summary: {
                ...(detail.stream_summary ?? {}),
                decoupling_pct: 'oops',
            },
        };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: garbled }, detail: garbled });
        expect(screen.queryByText(/NaN/)).not.toBeInTheDocument();
        expect(screen.queryByText('DECOUPLING')).not.toBeInTheDocument();
    });

    it('shows elevation gain as the ELEVASI hero tile, not a secondary ASCENT tile', () => {
        renderShow();
        expect(screen.getByText('ELEVASI')).toBeInTheDocument();
        expect(screen.getByText('120')).toBeInTheDocument();
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

    it('resyncs the activity from Strava when the Resync button is clicked', () => {
        vi.mocked(router.post).mockReset();
        renderShow();
        fireEvent.click(screen.getByText('Resync dari Strava'));
        expect(router.post).toHaveBeenCalledWith(
            '/aktivitas/99/resync',
            {},
            expect.objectContaining({ preserveScroll: true, onStart: expect.any(Function), onFinish: expect.any(Function) }),
        );
    });

    it('disables the Resync button and shows a pending label while the request is in flight', () => {
        vi.mocked(router.post).mockReset();
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
        });
        renderShow();
        const button = screen.getByText('Resync dari Strava').closest('button')!;
        fireEvent.click(button);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Lagi narik…');
    });

    it('shows a muted Telegram button that nudges (no send) when not connected', () => {
        vi.mocked(router.post).mockReset();
        renderShow();
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(router.post).not.toHaveBeenCalled();
    });

    it('pushes the run to Telegram when connected and the button is clicked', () => {
        vi.mocked(router.post).mockReset();
        renderShow({}, { telegramConnected: true });
        fireEvent.click(screen.getByText('Kirim ke Telegram'));
        expect(router.post).toHaveBeenCalledWith(
            '/aktivitas/99/telegram',
            {},
            expect.objectContaining({ preserveScroll: true, onStart: expect.any(Function), onFinish: expect.any(Function) }),
        );
    });

    it('disables the Telegram button and shows a pending label while the request is in flight', () => {
        vi.mocked(router.post).mockReset();
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
        });
        renderShow({}, { telegramConnected: true });
        const button = screen.getByText('Kirim ke Telegram').closest('button')!;
        fireEvent.click(button);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Lagi ngirim…');
    });
});
