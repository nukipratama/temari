import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Dashboard from './Dashboard';
import { setMockPage } from '@/test/setup';
import type { ActivityDetail, BriefingResult, FitnessChartData, TrainingLoad, VerdictTimelineItem, WeeklySnapshot } from '@/types/inertia';

const briefing: BriefingResult = {
    vibeState: 'fresh',
    vibeLabel: 'Fresh',
    vibeEmoji: '✨',
    headlineLine: 'Pagi yang oke',
    suggestionLine: 'Run sebentar',
    recoveryLabel: 'Pemulihan: oke',
    recoveryTone: 'positive',
    streakLabel: 'Lari hari ini',
    sigilPattern: 'orct',
    accessory: 'headband',
    mood: 'glow',
    degraded: false,
};

const chartData: FitnessChartData = {
    labels: [],
    ctl: [],
    atl: [],
    form: [],
    volume: [],
};

const chartDataMany: FitnessChartData = {
    labels: ['2026-05-01', '2026-05-08', '2026-05-15'],
    ctl: [40, 42, 43],
    atl: [30, 35, 38],
    form: [10, 7, 5],
    volume: [25, 28, 30],
};

const load: TrainingLoad = {
    form: -2.5,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    monotony: 1.2,
    strain: 384,
};

const snapshot: WeeklySnapshot = {
    id: 1,
    user_id: 1,
    week_ending: '2026-05-11',
    runs: 4,
    distance_km: 35.5,
    ctl_42d: 42,
    atl_7d: 44.5,
    form: -2.5,
    avg_decoupling: 3.2,
};

const detail: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Run',
    start_date_local: '2026-05-10T07:00',
    distance: 5000,
    moving_time: 1800,
    average_heartrate: 145,
    trimp_edwards: 60,
};

const verdict: VerdictTimelineItem = {
    activityId: 99,
    mood: 'bouncy',
    moodFace: '🦘',
    oneline: 'Solid',
    startedAt: '2026-05-10T07:00',
    distanceKm: 5,
    degraded: false,
};

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada Lovelace', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
        onboarding: { forceShow: false },
    });
});

describe('Dashboard', () => {
    it('renders greeting + empty state when load is null', () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={null}
                snapshot={null}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getByText('Halo, Ada.')).toBeInTheDocument();
        expect(screen.getByText('Belum ada aktivitas tersinkron')).toBeInTheDocument();
    });

    it('renders KPI tiles + coach disclosure when load is set', () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        // AtGlance sidebar lists Vibe / Beban / Decoupling stacked.
        expect(screen.getByText('Vibe')).toBeInTheDocument();
        expect(screen.getByText('Beban minggu ini')).toBeInTheDocument();
        expect(screen.getByText('Decoupling')).toBeInTheDocument();
        // Coach raw metrics now live inline at the top of Tren 30 Hari
        // (no more disclosure). When there's no chart data the strip
        // is also hidden, so this assertion needs chart data to fire.
    });

    it('renders CoachStatStrip raw metrics inside Tren 30 Hari when chart data exists', () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
                chartData={chartDataMany}
            />,
        );
        expect(screen.getByText('Fitness (CTL)')).toBeInTheDocument();
        expect(screen.getByText('Fatigue (ATL)')).toBeInTheDocument();
        expect(screen.getByText('Strain')).toBeInTheDocument();
        expect(screen.getByText('Monotony')).toBeInTheDocument();
    });

    it('does not render a "recent runs" block (VerdictStrip already covers it)', () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={load}
                snapshot={snapshot}
                recentRuns={[detail]}
                chartData={chartData}
            />,
        );
        expect(screen.queryByText('Aktivitas Terakhir')).not.toBeInTheDocument();
    });

    it.each([
        { monotony: 2.5, expected: 'Monotony 2.50 🚨' },
        { monotony: 1.7, expected: 'Monotony 1.70 ⚠️' },
        { monotony: 1.0, expected: 'Monotony 1.00 ok' },
    ])('renders monotony emoji for monotony=$monotony', ({ monotony, expected }) => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={{ ...load, monotony }}
                snapshot={snapshot}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getByText(expected)).toBeInTheDocument();
    });

    it('renders charts disclosure when chartData has >1 points and loads lazily', async () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
                chartData={chartDataMany}
            />,
        );
        expect(screen.getByText('Tren 30 Hari')).toBeInTheDocument();
        // Charts are lazy-loaded; CI under coverage instrumentation can
        // take well past the 1s default to resolve the dynamic import.
        await waitFor(() => expect(screen.getByTestId('line-chart')).toBeInTheDocument(), {
            timeout: 5000,
        });
        await waitFor(() => expect(screen.getByTestId('bar-chart')).toBeInTheDocument(), {
            timeout: 5000,
        });
    });

    it('shows verdict strip when items present', () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[verdict]}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getByText('Kata Temari')).toBeInTheDocument();
    });

    it('omits the hero week-volume KPI when snapshot is null', () => {
        // Volume now lives only in the hero header (no duplicate tile).
        // With snapshot=null the hero's "Minggu ini" block is skipped.
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={load}
                snapshot={null}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.queryByText('Minggu ini')).not.toBeInTheDocument();
    });

    it('renders negative decoupling with no leading +', () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={load}
                snapshot={{ ...snapshot, avg_decoupling: -2.5 }}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getByText('-2.5%')).toBeInTheDocument();
    });

    it('renders dash for missing decoupling', () => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={load}
                snapshot={{ ...snapshot, avg_decoupling: null }}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it.each(['fresh', 'fatigued', 'overreaching', 'optimal'] as const)(
        'renders the AtGlance Vibe hint for form_status %s',
        (status) => {
            render(
                <Dashboard
                    briefing={briefing}
                    verdicts={[]}
                    load={{ ...load, form_status: status }}
                    snapshot={snapshot}
                    recentRuns={[]}
                    chartData={chartData}
                />,
            );
            expect(screen.getByText('Vibe')).toBeInTheDocument();
        },
    );

    it.each([
        [2.5, 'Monotony 2.50 🚨'],
        [1.7, 'Monotony 1.70 ⚠️'],
        [1.2, 'Monotony 1.20 ok'],
    ] as const)('colors AtGlance Beban hint for monotony=%s', (monotony, expected) => {
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={{ ...load, monotony }}
                snapshot={snapshot}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getByText(expected)).toBeInTheDocument();
    });

    it('renders empty first_name when user has no name', () => {
        setMockPage({
            auth: { user: { id: 1, name: '', first_name: '', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
            onboarding: { forceShow: false },
        });
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={null}
                snapshot={null}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getByText('Halo, .')).toBeInTheDocument();
    });

    it('handles anonymous page state', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false, onboarding: { forceShow: false } });
        render(
            <Dashboard
                briefing={briefing}
                verdicts={[]}
                load={null}
                snapshot={null}
                recentRuns={[]}
                chartData={chartData}
            />,
        );
        expect(screen.getByText('Halo, .')).toBeInTheDocument();
    });
});
