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

const detail: ActivityDetail = {
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
            notificationRetryAfterSeconds={null}
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

    it('feeds the detail tiles from the stream summary', () => {
        renderShow({
            detail: { ...detail, stream_summary: { ...detail.stream_summary, max_grade_pct: 11, gap_pace: '5:20' } },
        });
        expect(screen.getByText('TANJAKAN')).toBeInTheDocument();
        expect(screen.getByText('GAP')).toBeInTheDocument();
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

    it('mounts the map+weather panel with the run detail', () => {
        renderShow();
        expect(screen.getByText(/32°/)).toBeInTheDocument();
        expect(screen.getByText('Senayan')).toBeInTheDocument();
    });

    it('renders the splits per-km section from the stream summary', () => {
        renderShow();
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.getByText(/Paling kenceng di km 2/)).toBeInTheDocument();
    });

    it('omits the splits section when the run has neither full kms nor a partial', () => {
        const noSplits = { ...detail, stream_summary: { decoupling_pct: 4.5 } };
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noSplits }, detail: noSplits });
        expect(screen.queryByText('Splits per km')).not.toBeInTheDocument();
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

    it('shows elevation gain as the ELEVASI hero tile, not a secondary ASCENT tile', () => {
        renderShow();
        expect(screen.getByText('ELEVASI')).toBeInTheDocument();
        expect(screen.getByText('120')).toBeInTheDocument();
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
        renderShow({ activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: bare }, detail: bare });
        expect(screen.getByText(/Detail teknis-nya belum kebaca/)).toBeInTheDocument();
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

    it('shows a muted send button that nudges (no send) when no channel is wired', () => {
        vi.mocked(router.post).mockReset();
        renderShow();
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(router.post).not.toHaveBeenCalled();
    });

    it('pushes the run to Telegram when connected and the button is clicked', () => {
        vi.mocked(router.post).mockReset();
        renderShow({}, { telegramConnected: true });
        fireEvent.click(screen.getByText('Kirim notifikasi'));
        expect(router.post).toHaveBeenCalledWith(
            '/aktivitas/99/kirim',
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
        const button = screen.getByText('Kirim notifikasi').closest('button')!;
        fireEvent.click(button);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Lagi ngirim…');
    });
});
