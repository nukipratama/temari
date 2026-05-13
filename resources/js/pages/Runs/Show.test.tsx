import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RunsShow from './Show';
import { setMockPage } from '@/test/setup';
import type { ActivityDetail, RunCard, StoryLine } from '@/types/inertia';

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
        per_km: [{ km: 1, pace: '6:00', avg_hr: 150, avg_cadence_spm: 170 }],
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
};

const card: RunCard = {
    id: 1,
    activity_id: 99,
    rarity: 'epik',
    special_move: 'Paru-paru Baja',
    badges: [],
};

const storyLine: StoryLine = {
    id: 1,
    user_id: 1,
    activity_id: 99,
    kind: 'post_run',
    mood: 'glow',
    speech: 'Run solid banget',
    sigil_pattern: 'ssss',
    for_date: null,
};

describe('Runs/Show', () => {
    it('renders headline + Temari speech', () => {
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail }}
                detail={detail}
                card={card}
                storyLine={storyLine}
                storyVariations={[]}
                pastYou={null}
            />,
        );
        expect(screen.getAllByText('Morning Run').length).toBeGreaterThan(0);
        expect(screen.getByText('Run solid banget')).toBeInTheDocument();
        expect(screen.getByText('Paru-paru Baja')).toBeInTheDocument();
    });

    it('renders weather chip with hot temp + rain', () => {
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail }}
                detail={detail}
                card={null}
                storyLine={null}
                storyVariations={[]}
                pastYou={null}
            />,
        );
        expect(screen.getAllByText(/32°C/).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/80%/).length).toBeGreaterThan(0);
        expect(screen.getByText('Hujan')).toBeInTheDocument();
    });

    it('omits weather chip when no weather data', () => {
        const minDetail = {
            ...detail,
            stream_summary: null,
            weather_temp_c: null,
            weather_humidity_pct: null,
            weather_rain_detected: null,
        };
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: minDetail }}
                detail={minDetail}
                card={null}
                storyLine={null}
                storyVariations={[]}
                pastYou={null}
            />,
        );
        expect(screen.queryByText('Hujan')).not.toBeInTheDocument();
    });

    it('renders past-you strip with pace + hr diff', () => {
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail }}
                detail={detail}
                card={null}
                storyLine={null}
                storyVariations={[]}
                pastYou={{
                    past: { start_date_local: '2026-04-01T07:00' },
                    pace_diff_sec: 10,
                    hr_diff_bpm: -3,
                    days_ago: 30,
                }}
            />,
        );
        expect(screen.getByText(/30 hari lalu/)).toBeInTheDocument();
    });

    it('renders splits table when per_km data is present', () => {
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail }}
                detail={detail}
                card={null}
                storyLine={null}
                storyVariations={[]}
                pastYou={null}
            />,
        );
        expect(screen.getByText('Splits per KM')).toBeInTheDocument();
        expect(screen.getAllByText('6:00').length).toBeGreaterThan(0);
    });

    it('handles null detail.distance for pace', () => {
        const noDist = { ...detail, distance: null, moving_time: null };
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noDist }}
                detail={noDist}
                card={null}
                storyLine={null}
                storyVariations={[]}
                pastYou={null}
            />,
        );
        expect(screen.getAllByText('Pace').length).toBeGreaterThan(0);
    });

    it('falls back to "Run" when detail.name is null', () => {
        const noName = { ...detail, name: null };
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noName }}
                detail={noName}
                card={null}
                storyLine={null}
                storyVariations={[]}
                pastYou={null}
            />,
        );
        expect(screen.getAllByText('Run').length).toBeGreaterThan(0);
    });

    it('handles missing start_date_local', () => {
        const noDate = { ...detail, start_date_local: null };
        render(
            <RunsShow
                activity={{ id: 99, user_id: 1, analyzed_at: '2026-05-10', detail: noDate }}
                detail={noDate}
                card={null}
                storyLine={null}
                storyVariations={[]}
                pastYou={null}
            />,
        );
        expect(screen.getByText('—')).toBeInTheDocument();
    });
});
