import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';

import type {
    ActivityDetail,
    BriefingResult,
    PastYouComparison,
    PastYouTrend,
    TrainingLoad,
    WeekPlan,
    WeeklySnapshot,
} from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import Home from './Home';

const briefing: BriefingResult = {
    vibeState: 'pumped',
    vibeLabel: 'Pumped',
    vibeEmoji: '💥',
    mascotVoice: {
        id: 4,
        status: 'done',
        content: 'Easy 6k.\n\nLegs are asking for it, keep it under 6:00.',
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-06-12',
    },
    recoveryLabel: 'Recovery: 41h',
    recoveryTone: 'positive',
    recoveryHoursLabel: '41h',
    recoveryHours: 41,
    streakLabel: 'Ran today',
    sigilPattern: 'orct',
    accessory: null,
    mood: 'blazing',
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
    week_ending: '2026-06-14',
    runs: 4,
    distance_km: 35.5,
    weekly_trimp: 280,
    ctl_42d: 42,
    atl_7d: 44.5,
    form: -2.5,
    form_status: 'optimal',
    avg_decoupling: 3.2,
    monotony: 1.4,
    strain: 392,
};

const lastRun: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Morning negative-split',
    start_date_local: '2026-06-12T07:00',
    distance: 8200,
    elapsed_time: 2400,
    average_heartrate: 152,
    trimp_edwards: 87,
    activity: {
        id: 99,
        user_id: 1,
        analyzed_at: '2026-06-12T08:00',
        run_card: {
            id: 7,
            activity_id: 99,
            rarity: 'epic',
            special_move: 'Game Changer',
            badges: ['negative_split'],
        },
    },
};

const pair: PastYouComparison = {
    direction: 'better',
    days_apart: 90,
    similarity: 0.9,
    pace_delta_sec: 12,
    hr_delta_bpm: -6,
    current: {
        activity_id: 2,
        date: '2026-06-12',
        km: 8.2,
        pace_sec_per_km: 420,
        average_heartrate: 152,
        elevation_gain_m: 40,
        ingest_state: 'summary',
    },
    past: {
        activity_id: 102,
        date: '2026-03-14',
        km: 8.2,
        pace_sec_per_km: 432,
        average_heartrate: 158,
        elevation_gain_m: 40,
        ingest_state: 'summary',
    },
};

function trend(overrides: Partial<PastYouTrend> = {}): PastYouTrend {
    return {
        verdict: 'improving',
        window_days: 42,
        comparison_count: 2,
        comparisons: [
            pair,
            { ...pair, current: { ...pair.current, activity_id: 3 } },
        ],
        mean_pace_delta_sec: 10,
        mean_hr_delta_bpm: -5,
        fitness_delta_ctl: 2.4,
        pace_consistency_now: null,
        pace_consistency_then: null,
        relative_effort_band: null,
        ...overrides,
    };
}

function renderHome(
    pastYouTrend: PastYouTrend | null = trend(),
    weekPlan: WeekPlan | null = null,
) {
    return render(
        <Home
            briefing={briefing}
            load={load}
            snapshot={snapshot}
            recentRuns={[lastRun]}
            pastYouTrend={pastYouTrend}
            weekPlan={weekPlan}
        />,
    );
}

const weekPlan: WeekPlan = {
    sessions_per_week: 5,
    phase: 'build',
    planned_km_this_week: 32,
    credited_this_week: 2,
    days: [
        {
            id: 1,
            date: '2026-06-08',
            phase: 'build',
            session_type: 'easy',
            segments: [
                {
                    key: 'main',
                    minutes: 48,
                    zone: 'Z2',
                    pace_label: 'easy',
                    pace_sec_per_km: 360,
                },
            ],
            distance_km: 8,
            pinned: false,
            skipped: false,
            status: 'done',
            compliance_score: 100,
            ran_anyway: false,
            clamp_note: null,
            actual_km: null,
            activity: null,
        },
    ],
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Home', () => {
    it("follows the prototype's section order: plan, past you, today, week stats", () => {
        const { container } = renderHome(trend(), weekPlan);

        const order = [
            screen.getByText("This week's plan"),
            screen.getByText("you're faster than you were in March."),
            screen.getByText('Easy 6k.'),
            screen.getByText(/This week's stats/),
        ];

        order.forEach((node, i) => {
            expect(container).toContainElement(node);
            const next = order[i + 1];
            if (next) {
                expect(
                    node.compareDocumentPosition(next) &
                        Node.DOCUMENT_POSITION_FOLLOWING,
                ).toBeTruthy();
            }
        });
    });

    it("draws the prototype's no-plan card when the backend shipped no plan", () => {
        renderHome(trend(), null);

        expect(screen.queryByText("This week's plan")).not.toBeInTheDocument();
        expect(screen.getByText('No plan yet.')).toBeInTheDocument();
    });

    it('shows the evidence the verdict was computed from', () => {
        renderHome();

        expect(screen.getAllByText('8.2 km · pace vs mar 14')).toHaveLength(2);
        expect(screen.getAllByText('-12 s/km')).toHaveLength(2);
    });

    it('renders the plateaued verdict', () => {
        renderHome(trend({ verdict: 'plateaued', mean_pace_delta_sec: 0.4 }));

        expect(
            screen.getByText("you're holding where you were in March."),
        ).toBeInTheDocument();
    });

    it('renders the slipped verdict', () => {
        renderHome(
            trend({
                verdict: 'slipped',
                mean_pace_delta_sec: -9,
                comparisons: [
                    { ...pair, direction: 'worse', pace_delta_sec: -10 },
                ],
                comparison_count: 1,
            }),
        );

        expect(
            screen.getByText("you've slipped since March."),
        ).toBeInTheDocument();
        expect(screen.getByText('+10 s/km')).toBeInTheDocument();
    });

    it('renders the not-enough-history state as an empty state, not a verdict', () => {
        renderHome(
            trend({
                verdict: 'not_enough_history',
                comparison_count: 0,
                comparisons: [],
                mean_pace_delta_sec: null,
                mean_hr_delta_bpm: null,
            }),
        );

        expect(
            screen.getByText('nothing to measure this against yet.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/faster than you were/),
        ).not.toBeInTheDocument();
    });

    it("renders Temari's read on today", () => {
        renderHome();

        expect(screen.getByText('Easy 6k.')).toBeInTheDocument();
        expect(
            screen.getByText('Legs are asking for it, keep it under 6:00.'),
        ).toBeInTheDocument();
    });

    // Amendment (d) of 2026-08-31 supersedes V0 fork 4: the prototype passes no
    // `defaultOpen`, so the disclosure ships closed.
    it('renders the week-stats disclosure closed, and opens it on click', async () => {
        renderHome();

        const trigger = screen.getByRole('button', { expanded: false });
        expect(screen.queryByText('Pumped')).not.toBeInTheDocument();

        await userEvent.click(trigger);

        expect(screen.getByText('Pumped')).toBeInTheDocument();
        expect(screen.getByText(/^Last run · /)).toBeInTheDocument();
    });

    it('omits the verdict block entirely when the backend shipped no trend', () => {
        renderHome(null);

        expect(screen.queryByText(/You vs Past You/)).not.toBeInTheDocument();
        expect(screen.getByText(/This week's stats/)).toBeInTheDocument();
    });

    it('shows the no-runs empty state instead of a verdict on a brand new account', () => {
        render(
            <Home
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
                pastYouTrend={trend()}
            />,
        );

        expect(screen.queryByText(/You vs Past You/)).not.toBeInTheDocument();
        expect(screen.queryByText(/This week's stats/)).not.toBeInTheDocument();
    });
});
