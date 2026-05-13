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
        expect(screen.getByText('Vibe')).toBeInTheDocument();
        expect(screen.getByText('Beban minggu ini')).toBeInTheDocument();
        expect(screen.getByText('Volume minggu ini')).toBeInTheDocument();
        expect(screen.getByText('Rincian coach mode')).toBeInTheDocument();
    });

    it('renders recent run rows when present', () => {
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
        expect(screen.getByText('Aktivitas Terakhir')).toBeInTheDocument();
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
        expect(screen.getByText('Tren 30 hari')).toBeInTheDocument();
        await waitFor(() => expect(screen.getByTestId('line-chart')).toBeInTheDocument());
        expect(screen.getByTestId('bar-chart')).toBeInTheDocument();
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

    it('renders dash for missing volume snapshot', () => {
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
        expect(screen.getByText('Volume minggu ini')).toBeInTheDocument();
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

    it('renders empty first_name when user has no name', () => {
        setMockPage({
            auth: { user: { id: 1, name: '', first_name: '', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
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
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
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
