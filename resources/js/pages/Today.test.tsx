import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import type {
    ActivityDetail,
    BriefingResult,
    GoalsSummary,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import Today from './Today';

const briefing: BriefingResult = {
    vibeState: 'pumped',
    vibeLabel: 'Pumped',
    vibeEmoji: '💥',
    mascotVoice: {
        id: 4,
        status: 'done',
        content:
            'Easy pace, 35–45 minutes.\n\nYour last two runs were negative-split, so today you can push a bit harder.',
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
    },
    featuredKartuVoice: {
        id: 5,
        status: 'done',
        content: 'This card proves you can go further than you think.',
        type: 'briefing_featured_kartu_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '7',
    },
    featuredCardId: 7,
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
    week_ending: '2026-05-11',
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

const detailWithCard: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Morning negative-split',
    start_date_local: '2026-05-20T07:00',
    distance: 5280,
    elapsed_time: 2400,
    average_heartrate: 145,
    trimp_edwards: 87,
    activity: {
        id: 99,
        user_id: 1,
        analyzed_at: '2026-05-20T08:00',
        run_card: {
            id: 7,
            activity_id: 99,
            rarity: 'epic',
            special_move: 'Game Changer',
            badges: ['negative_split', 'heat_tamer'],
        },
    },
};

// A run with the full set of optional fields populated (weather, location,
// pace, trimp) so LastLariCard renders every conditional row.
const richRun: ActivityDetail = {
    ...detailWithCard,
    location_name: 'Gelora Bung Karno, Jakarta Pusat',
    weather_temp_c: 28,
    weather_humidity_pct: 70,
    weather_rain_detected: false,
};

// A bare run with no optional fields: no location, no weather, no pace
// (zero distance/time), no trimp, no name. Exercises every "—"/empty branch.
const bareRun: ActivityDetail = {
    id: 2,
    activity_id: 100,
    name: null,
    start_date_local: '2026-05-21T07:00',
    distance: 0,
    elapsed_time: 0,
    average_heartrate: null,
    trimp_edwards: null,
    location_name: null,
    weather_temp_c: null,
    weather_humidity_pct: null,
    weather_rain_detected: null,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Today', () => {
    it('renders the editorial greeting with first name + vibe subtitle', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
            />,
        );
        expect(screen.getByText(/Hey, Ada/)).toBeInTheDocument();
        // "pumped" now appears both in the italic headline accent and as the
        // Vibe chip sub-label, so allow multiple matches.
        expect(screen.getAllByText(/pumped/i).length).toBeGreaterThan(0);
    });

    it('renders the three vital chips (Vibe / Readiness / Break)', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(screen.getByText('Vibe')).toBeInTheDocument();
        expect(screen.getByText('Readiness')).toBeInTheDocument();
        expect(screen.getByText('Break')).toBeInTheDocument();
    });

    it('shows the Temari read quote when mascotVoice is done', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
            />,
        );
        expect(screen.getAllByText(/negative-split/).length).toBeGreaterThan(0);
    });

    it('renders the featured hero kartu when a recentRun has an attached runCard', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(screen.getAllByText('Game Changer').length).toBeGreaterThan(0);
    });

    it('omits the hero panel when no recent run has a card', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
            />,
        );
        expect(screen.queryByText(/Temari's top pick/)).not.toBeInTheDocument();
    });

    it('shows the session title of the merged Temari voice', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(
            screen.getByText(/Easy pace, 35–45 minutes\./),
        ).toBeInTheDocument();
    });

    it('renders the Kondisi card with CTL / ATL / Strain / Monotony rows', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        ['Fitness', 'Fatigue', 'Strain', 'Monotony'].forEach((row) => {
            expect(screen.getByText(row)).toBeInTheDocument();
        });
    });

    it('no longer renders a "Kartu terakhir" strip; the featured hero replaces it', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        // The kartu strip was removed from the dashboard. Cards now surface only
        // through the featured hero panel (eyebrow "Kartu andalan dari Temari").
        expect(screen.queryByText(/Kartu terakhir/i)).not.toBeInTheDocument();
        expect(screen.getByText(/Temari's top pick/)).toBeInTheDocument();
    });

    it('renders the featuredKartuVoice quote inside the hero panel', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(
            screen.getAllByText(/proves you can go further/).length,
        ).toBeGreaterThan(0);
    });

    it('renders without crashing when the Temari voice content is empty', () => {
        const emptyBriefing: BriefingResult = {
            ...briefing,
            mascotVoice: { ...briefing.mascotVoice, content: '' },
        };
        render(
            <Today
                briefing={emptyBriefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
            />,
        );
        expect(screen.getByText(/Hey, Ada/)).toBeInTheDocument();
    });

    it('renders the Temari voice block but emits no content when the text is whitespace-only', () => {
        // Whitespace-only content trims to zero parts -> null.
        const blank: BriefingResult = {
            ...briefing,
            mascotVoice: { ...briefing.mascotVoice, content: '\n\n   \n\n' },
        };
        render(
            <Today
                briefing={blank}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        // The section heading still renders; the body resolves to nothing.
        expect(screen.getByText('Today from Temari')).toBeInTheDocument();
    });

    it('renders the Temari voice title-only when there is no body paragraph', () => {
        const titleOnly: BriefingResult = {
            ...briefing,
            mascotVoice: {
                ...briefing.mascotVoice,
                content: '“Just an easy run today.”',
            },
        };
        render(
            <Today
                briefing={titleOnly}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(
            screen.getByText(/Just an easy run today\./),
        ).toBeInTheDocument();
    });

    it('renders the Temari voice unclamped, with no expand toggle', () => {
        const longText = 'a'.repeat(200);
        const longQuoteBriefing: BriefingResult = {
            ...briefing,
            mascotVoice: { ...briefing.mascotVoice, content: longText },
        };
        render(
            <Today
                briefing={longQuoteBriefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
            />,
        );
        expect(screen.getByText(longText)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Baca selengkapnya' }),
        ).not.toBeInTheDocument();
    });

    it('renders the "Closest targets" goals when goalsSummary has closest goals', () => {
        const goalsSummary: GoalsSummary = {
            total: 3,
            completed: 1,
            closest: [
                // whole numbers -> integer display, full progress capped at 100%
                {
                    id: 'g1',
                    title: 'Run 100 KM this month',
                    current: 100,
                    target: 100,
                    unit: 'km',
                },
                // decimals -> toFixed(1) on both current and target
                {
                    id: 'g2',
                    title: 'Half marathon',
                    current: 12.5,
                    target: 21.1,
                    unit: 'km',
                },
                // target 0 -> pct branch returns 0 (no divide-by-zero)
                {
                    id: 'g3',
                    title: 'Empty goal',
                    current: 0,
                    target: 0,
                    unit: 'sessions',
                },
            ],
        };
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            goalsSummary,
        });
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(screen.getByText('Closest targets')).toBeInTheDocument();
        expect(screen.getByText('Run 100 KM this month')).toBeInTheDocument();
        expect(screen.getByText('Half marathon')).toBeInTheDocument();
        expect(screen.getByText('Empty goal')).toBeInTheDocument();
        // current/target sit in one span split by a "/" node; match the combined
        // text to confirm both the integer (100/100) and decimal (12.5/21.1) paths.
        expect(
            screen.getByText((_, el) => el?.textContent === '100/100'),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, el) => el?.textContent === '12.5/21.1'),
        ).toBeInTheDocument();
        // target 0 -> "0/0", no NaN/Infinity from the divide-by-zero guard.
        expect(
            screen.getByText((_, el) => el?.textContent === '0/0'),
        ).toBeInTheDocument();
    });

    it('omits the goals section when goalsSummary has no closest goals', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            goalsSummary: { total: 0, completed: 0, closest: [] },
        });
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(screen.queryByText('Closest targets')).not.toBeInTheDocument();
    });

    it('renders the last-run card with location, weather, pace, trimp, and a note', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[richRun]}
                lastRunNote={{ oneline: 'Solid session.', mood: 'blazing' }}
            />,
        );
        expect(screen.getByText('Morning negative-split')).toBeInTheDocument();
        expect(screen.getByText(/Gelora Bung Karno/)).toBeInTheDocument();
        expect(screen.getByText('Solid session.')).toBeInTheDocument();
        // pace renders as a value (not the "—" fallback).
        expect(screen.getAllByText(/\/km$/).length).toBeGreaterThan(0);
    });

    it('renders the last-run card with em-dash fallbacks when pace/trimp/weather/location absent', () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[bareRun]}
            />,
        );
        // name falls back to "Run"; pace + trimp both show the "—" placeholder.
        expect(screen.getByText('Run')).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(2);
        // no location/weather row.
        expect(screen.queryByText(/Gelora/)).not.toBeInTheDocument();
    });

    it('falls back to an empty first name and the default pose for an unknown vibe', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        const oddBriefing = {
            ...briefing,
            vibeState: 'mysterious' as BriefingResult['vibeState'],
        };
        render(
            <Today
                briefing={oddBriefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[]}
            />,
        );
        // greeting renders with no name, no crash on the missing pose mapping.
        expect(screen.getByText(/Hey,/)).toBeInTheDocument();
    });

    it('shows em-dash / empty vital chips and "belum cukup data" when load and snapshot are null', () => {
        render(
            <Today
                briefing={briefing}
                load={null}
                snapshot={null}
                recentRuns={[detailWithCard]}
            />,
        );
        // Kesiapan + Kondisi rows all collapse to the "—" placeholder.
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
        expect(screen.getByText(/not enough data yet/)).toBeInTheDocument();
        // Vibe chip falls back to the qualitative label when there's no form score.
        expect(screen.getAllByText(/pumped/i).length).toBeGreaterThan(0);
    });

    it('falls back to streakLabel for the Recovery chip when recoveryHoursLabel is null', () => {
        const noHours: BriefingResult = {
            ...briefing,
            recoveryHoursLabel: null,
        };
        render(
            <Today
                briefing={noHours}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(screen.getByText('Ran today')).toBeInTheDocument();
    });

    it('falls back to recoveryLabel for the Recovery chip when hours + streak are both null', () => {
        const onlyRecovery: BriefingResult = {
            ...briefing,
            recoveryHoursLabel: null,
            streakLabel: null,
        };
        render(
            <Today
                briefing={onlyRecovery}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(screen.getByText('Recovery: 41h')).toBeInTheDocument();
    });

    it('flips the "Another take" button to its pending label when triggered', async () => {
        render(
            <Today
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        const button = screen.getByRole('button', { name: 'Another take' });
        // trigger() flips `pending` synchronously before its fetch awaits, so the
        // re-render swaps the label to the in-flight copy.
        fireEvent.click(button);
        expect(
            screen.getByRole('button', { name: 'Thinking…' }),
        ).toBeInTheDocument();
        // The global default fetch mock (a 404) still resolves for real, so the
        // trigger's catch/finally fires on a later microtask — wait for it to
        // settle back to the idle label instead of leaving it unmonitored.
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Another take' }),
            ).toBeInTheDocument(),
        );
    });

    it('renders the Temari voice as a title + body when the text has two paragraphs', () => {
        const withBody: BriefingResult = {
            ...briefing,
            mascotVoice: {
                ...briefing.mascotVoice,
                content: 'Easy pace today.\n\nHold zone 2 for 40 minutes.',
            },
        };
        render(
            <Today
                briefing={withBody}
                load={load}
                snapshot={snapshot}
                recentRuns={[detailWithCard]}
            />,
        );
        expect(screen.getByText('Easy pace today.')).toBeInTheDocument();
        expect(screen.getByText(/Hold zone 2/)).toBeInTheDocument();
    });
});
