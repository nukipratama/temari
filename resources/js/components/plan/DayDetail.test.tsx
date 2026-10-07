import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { EditablePlanDay } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import DayDetail, {
    DayHeadline,
    hasDayDetail,
    showsNarration,
} from './DayDetail';

function narrationPayload(
    overrides: Partial<AnalysisPayload> = {},
): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'easy all the way, tempo block never happened.',
        type: 'plan_day_voice',
        is_zone_dependent: false,
        subject_type: 'plan_day_voice_user_day',
        subject_id: 1,
        discriminator: '2026-06-18',
        ...overrides,
    } as AnalysisPayload;
}

const TODAY = '2026-06-17';

function day(overrides: Partial<EditablePlanDay> = {}): EditablePlanDay {
    return {
        id: 1,
        date: '2026-06-18',
        phase: 'base',
        session_type: 'tempo',
        segments: [
            {
                key: 'main',
                minutes: 30,
                zone: 'Z4',
                pace_label: 'threshold',
                km: 5.2,
                pace_sec_per_km: 300,
            },
        ],
        distance_km: 8,
        asked_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        prescription_reason: null,
        fall_off_tilt: null,
        goal_pace: null,
        stepping_stone: false,
        time_trial: null,
        hr_cap_bpm: null,
        advice_note: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        ran_hot: false,
        result_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        credited_km: null,
        activities: [],
        flagged: false,
        actions: { move: false, skip: false, restore: false },
        move_targets: [],
        made_up_on: null,
        ...overrides,
    };
}

/** Thursday's tempo, with Fri/Sat rest days it could move onto. */
const WEEK: EditablePlanDay[] = [
    day({
        id: 1,
        date: '2026-06-18',
        actions: { move: true, skip: true, restore: false },
        move_targets: ['2026-06-19', '2026-06-20'],
    }),
    day({
        id: 2,
        date: '2026-06-19',
        session_type: 'rest',
        segments: [],
        distance_km: 0,
    }),
    day({
        id: 3,
        date: '2026-06-20',
        session_type: 'rest',
        segments: [],
        distance_km: 0,
    }),
];

/** The headline over its detail, as the day panel draws them. */
function renderRow(overrides: Partial<Parameters<typeof DayDetail>[0]> = {}) {
    const props = {
        day: WEEK[0],
        weekDays: WEEK,
        narration: null,
        onMove: vi.fn(),
        onSkip: vi.fn(),
        onUnskip: vi.fn(),
        ...overrides,
    };
    render(
        <>
            <div data-testid="headline">
                <DayHeadline day={props.day} />
            </div>
            {hasDayDetail(props.day, props.narration) && (
                <DayDetail {...props} />
            )}
        </>,
    );
    return props;
}

const headline = () => screen.getByTestId('headline');

describe('DayHeadline and DayDetail', () => {
    it('brings a skipped session back through the caller', () => {
        const { onUnskip } = renderRow({
            day: day({
                skipped: true,
                actions: { move: false, skip: false, restore: true },
            }),
        });

        fireEvent.click(screen.getByRole('button', { name: 'restore' }));

        expect(onUnskip).toHaveBeenCalledOnce();
        expect(
            screen.queryByRole('button', { name: /^skip$/i }),
        ).not.toBeInTheDocument();
    });

    it('does not offer restoration when the edit rules withhold it', () => {
        renderRow({ day: day({ skipped: true }) });

        expect(
            screen.queryByRole('button', { name: 'restore' }),
        ).not.toBeInTheDocument();
    });

    it('keeps restoration accessible without segments or move targets', () => {
        const session = day({
            session_type: 'easy',
            skipped: true,
            segments: [],
            actions: { move: false, skip: false, restore: true },
        });
        renderRow({ day: session, weekDays: [session] });

        expect(
            screen.getByRole('button', { name: 'restore' }),
        ).toBeInTheDocument();
    });

    it('summarises the day in its headline', () => {
        renderRow();

        expect(screen.getByText('tempo')).toBeInTheDocument();
        expect(screen.getByText('8 km · 5:00/km')).toBeInTheDocument();
    });

    it('heads a capped long run with its heart rate and says the pace may slow late', () => {
        const long = day({
            session_type: 'long',
            hr_cap_bpm: 152,
            segments: [
                {
                    key: 'main',
                    minutes: 110,
                    zone: 'Z2',
                    pace_label: 'easy',
                    km: 18,
                    pace_sec_per_km: 366,
                },
            ],
            distance_km: 18,
            asked_km: 18,
        });
        renderRow({ day: long, weekDays: [long] });

        expect(screen.getByText('18 km · under 152 bpm')).toBeInTheDocument();
        expect(
            screen.getByText(
                "about 6:06/km early on. the pace may slow late as you tire, and that's fine.",
            ),
        ).toBeInTheDocument();
    });

    it('names a time trial in its headline and bar graph, with the aim and what it checks', () => {
        renderRow({
            day: day({
                session_type: 'tempo',
                distance_km: 10,
                time_trial: { distance_m: 10_000, aim_time_sec: 3_125 },
                segments: [
                    {
                        key: 'main',
                        minutes: 52,
                        zone: 'Z4',
                        pace_label: 'threshold',
                        km: 10,
                        pace_sec_per_km: 313,
                    },
                ],
            }),
        });

        expect(screen.getByText('time trial')).toBeInTheDocument();
        expect(screen.queryByText('tempo')).not.toBeInTheDocument();
        expect(screen.getByText('5:13/km · time trial')).toBeInTheDocument();
        expect(
            screen.getByText(
                'aim around 52:05. checks your fitness so your paces stay honest.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'warm up first like you would before a race, then record the trial as its own run if you can.',
            ),
        ).toBeInTheDocument();
    });

    it('names goal-pace work in its headline, at the goal pace, with the race it rehearses', () => {
        renderRow({
            day: day({
                session_type: 'interval',
                goal_pace: '10k',
                segments: [
                    {
                        key: 'interval',
                        minutes: 4,
                        zone: 'Z4',
                        pace_label: 'threshold',
                        km: 0.8,
                        pace_sec_per_km: 300,
                    },
                ],
            }),
        });

        expect(screen.getByText('goal pace')).toBeInTheDocument();
        expect(screen.queryByText('interval')).not.toBeInTheDocument();
        expect(screen.getByText('5:00/km · goal pace')).toBeInTheDocument();
        expect(screen.getByText('8 km · 5:00/km')).toBeInTheDocument();
        expect(
            screen.getByText('rehearsing your 10K goal pace.'),
        ).toBeInTheDocument();
    });

    it('names stepping-stone work in its headline, at the stepping-stone pace, never as goal pace', () => {
        renderRow({
            day: day({
                session_type: 'interval',
                goal_pace: '10k',
                stepping_stone: true,
                segments: [
                    {
                        key: 'interval',
                        minutes: 4,
                        zone: 'Z4',
                        pace_label: 'threshold',
                        km: 0.8,
                        pace_sec_per_km: 291,
                    },
                ],
            }),
        });

        expect(screen.getByText('stepping-stone pace')).toBeInTheDocument();
        expect(
            screen.getByText('4:51/km · stepping-stone pace'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('rehearsing your 10K stepping-stone pace.'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/goal pace/)).not.toBeInTheDocument();
    });

    /**
     * The week-adjustment arrow used to always point down, even when the
     * number went up (#975). It must point the way the ask actually moved.
     */
    it("tags a trimmed day 'trimmed' but keeps the old value out of the headline, then explains it in the detail", () => {
        renderRow({ day: day({ distance_km: 3, asked_km: 8 }) });

        expect(screen.getByText('trimmed')).toBeInTheDocument();
        expect(headline()).not.toHaveTextContent(/\b8\b/);

        expect(screen.getByText('km')).toBeInTheDocument();
        expect(screen.getByText('8')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('ahead on the week')).toBeInTheDocument();
        expect(screen.getByText('↓')).toHaveClass('text-text-2');
    });

    it("shows the week-fit delta pointing up when the week's redistribution raised the ask", () => {
        renderRow({ day: day({ distance_km: 4.1, asked_km: 3.6 }) });

        expect(screen.getByText('3.6')).toBeInTheDocument();
        expect(screen.getByText('4.1')).toBeInTheDocument();
        expect(screen.getByText('↑')).toBeInTheDocument();
        expect(screen.getAllByText('topped up')).toHaveLength(1);
        expect(screen.getByText('making up the week')).toBeInTheDocument();
    });

    it('stays quiet about a resize too small to matter', () => {
        renderRow({ day: day({ distance_km: 10.3, asked_km: 10.4 }) });

        expect(screen.queryByText('trimmed')).not.toBeInTheDocument();
    });

    it('stays quiet when the redistributed figure matches what was asked', () => {
        renderRow({ day: day({ distance_km: 8, asked_km: 8 }) });

        expect(screen.queryByText('trimmed')).not.toBeInTheDocument();
    });

    it('stays quiet once the day is graded, even if distance and ask differ', () => {
        renderRow({
            day: day({
                distance_km: 3,
                asked_km: 8,
                status: 'done',
                prescribed_km: 8,
                actual_km: 8,
            }),
        });

        expect(screen.queryByText('trimmed')).not.toBeInTheDocument();
    });

    it('labels a scored day with its verdict and score', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'partial',
                compliance_score: 60,
            }),
        });

        expect(screen.getByText('partial · 60%')).toBeInTheDocument();
    });

    it("carries the verdict's glyph beside its label, never as the only signal", () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'partial',
                compliance_score: 60,
            }),
        });

        expect(
            screen
                .getByText('partial · 60%')
                .querySelector('[data-icon="CircleDashed"]'),
        ).not.toBe(null);
    });

    it('keeps the status word neutral: effort colours mark effort, not verdicts', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'overreached',
                compliance_score: 161,
            }),
        });

        expect(screen.getByText('overreached · 161%')).toHaveClass(
            'text-text-2',
        );
    });

    it('labels an overrun as distance completion without hiding the verdict', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'overreached',
                compliance_score: 161,
                prescribed_km: 6.2,
                actual_km: 10,
                credited_km: 10,
            }),
        });

        expect(
            screen.getByText('overreached · 10.0 of 6.2 km · 161% distance'),
        ).toBeInTheDocument();
    });

    it('labels the credited run distance separately from the day total', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'overreached',
                compliance_score: 113,
                prescribed_km: 8,
                actual_km: 10,
                credited_km: 9,
            }),
        });

        expect(
            screen.getByText(
                'overreached · 10.0 km logged · 9.0 of 8.0 km counted · 113% distance',
            ),
        ).toBeInTheDocument();
    });

    it('says what a verdict means, distance and intent together', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'partial',
                compliance_score: 84,
            }),
        });

        expect(screen.getByText('partial · 84%')).toHaveAttribute(
            'title',
            'short on the distance, or the run missed what the session was for',
        );
    });

    it('reads an excused upcoming day as skipped before the scorer has run', () => {
        renderRow({ day: day({ skipped: true }) });

        expect(screen.getByText('skipped')).toBeInTheDocument();
    });

    it('shows nothing but the plan on a day still ahead', () => {
        renderRow();

        expect(screen.queryByText('Done')).not.toBeInTheDocument();
    });

    it("says what a quality session is for, and why today's dose moved", () => {
        renderRow({
            day: day({
                prescription_reason:
                    'progressed after the latest comparable session was hit',
            }),
        });

        expect(screen.getByText('the point')).toBeInTheDocument();
        expect(
            screen.getByText(
                'comfortably hard. teaches you to hold a pace without tipping over.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('a step up. you hit the last one.'),
        ).toBeInTheDocument();
    });

    it('never shows an engine placeholder as a reason', () => {
        renderRow({
            day: day({
                session_type: 'long',
                segments: [
                    {
                        key: 'main',
                        minutes: 78,
                        zone: 'Z2',
                        pace_label: 'easy',
                        km: 10.4,
                        pace_sec_per_km: 450,
                    },
                ],
                prescription_reason: 'easy volume',
            }),
        });

        expect(screen.queryByText('easy volume')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'time on feet. builds the engine the race runs on. chatty effort the whole way.',
            ),
        ).toBeInTheDocument();
    });

    it('ends the point with why the fall-off tilted the session', () => {
        renderRow({
            day: day({
                prescription_reason:
                    'progressed after the latest comparable session was hit',
                fall_off_tilt: 'endurance',
            }),
        });

        const line = screen.getByText(
            'threshold this week: your pace fades over longer distances.',
        );
        expect(line).toHaveClass('mt-1', 'text-xs', 'italic', 'text-text-2');
        expect(line.previousElementSibling).toHaveTextContent(
            'a step up. you hit the last one.',
        );
        expect(line.nextElementSibling).toBeNull();
    });

    it('shows no tilt line on an untilted session', () => {
        renderRow({ day: day() });

        expect(screen.queryByText(/this week:/)).not.toBeInTheDocument();
    });

    it('says why a quality day was kept easy', () => {
        renderRow({
            day: day({
                session_type: 'easy',
                segments: [
                    {
                        key: 'main',
                        minutes: 40,
                        zone: 'Z2',
                        pace_label: 'easy',
                        km: 6,
                        pace_sec_per_km: 400,
                    },
                ],
                prescription_reason:
                    'easy to preserve recovery between hard days',
            }),
        });

        expect(
            screen.getByText('kept easy. too close to another hard day.'),
        ).toBeInTheDocument();
    });

    /**
     * #939: the day's take is labelled "Temari's read", not "Temari's take" —
     * the backend only ever hands this row a `narration` payload once the
     * day is credited (see PlanNarrationRequesterTest), so the row itself
     * renders whatever it is given.
     */
    it("labels a credited day's narration as Temari's read", () => {
        renderRow({
            day: day({ status: 'done' }),
            narration: narrationPayload(),
        });

        expect(screen.getByText("Temari's read")).toBeInTheDocument();
        expect(
            screen.getByText('easy all the way, tempo block never happened.'),
        ).toBeInTheDocument();
    });

    it('renders no read wrapper while the day narration is pending', () => {
        renderRow({
            day: day({ status: 'done' }),
            narration: narrationPayload({ status: 'pending', content: null }),
        });

        expect(screen.queryByText("Temari's read")).not.toBeInTheDocument();
    });

    it('offers move and skip on a day still ahead', () => {
        renderRow();

        expect(
            screen.getByRole('button', { name: /^move$/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /^skip$/i }),
        ).toBeInTheDocument();
    });

    it('offers move and skip on today when the edit rules allow them', () => {
        renderRow({
            day: day({
                date: TODAY,
                actions: { move: true, skip: true, restore: false },
                move_targets: ['2026-06-19'],
            }),
        });

        expect(
            screen.getByRole('button', { name: /^move$/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /^skip$/i }),
        ).toBeInTheDocument();
    });

    it('offers move alone on an unrun past day of this week', () => {
        renderRow({
            day: day({
                date: '2026-06-16',
                status: 'missed',
                actions: { move: true, skip: false, restore: false },
                move_targets: ['2026-06-19'],
            }),
        });

        expect(
            screen.getByRole('button', { name: /^move$/i }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^skip$/i }),
        ).not.toBeInTheDocument();
    });

    it('offers neither when the edit rules withhold both', () => {
        renderRow({ day: day({ date: '2026-06-15', status: 'done' }) });

        expect(
            screen.queryByRole('button', { name: /^move$/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^skip$/i }),
        ).not.toBeInTheDocument();
    });

    it('offers neither move nor skip on a rest day with a logged run', () => {
        renderRow({
            day: day({
                id: 2,
                date: '2026-06-19',
                session_type: 'rest',
                segments: [],
                distance_km: 0,
                status: 'done',
                ran_anyway: true,
                actual_km: 5,
                activities: [
                    { id: 7, km: 5, seconds: 1800, started_at: '06:00' },
                ],
            }),
        });

        expect(
            screen.queryByRole('button', { name: /^move$/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^skip$/i }),
        ).not.toBeInTheDocument();
    });

    it('does not offer skip twice on an already-excused day', () => {
        renderRow({
            day: day({
                skipped: true,
                actions: { move: true, skip: false, restore: true },
                move_targets: ['2026-06-19'],
            }),
        });

        expect(
            screen.queryByRole('button', { name: /^skip$/i }),
        ).not.toBeInTheDocument();
    });

    it('skips through to the caller', () => {
        const { onSkip } = renderRow();
        fireEvent.click(screen.getByRole('button', { name: /^skip$/i }));

        expect(onSkip).toHaveBeenCalledOnce();
    });

    it("offers a weekday picker whose only enabled targets are the day's move targets", () => {
        renderRow();
        fireEvent.click(screen.getByRole('button', { name: /^move$/i }));

        expect(screen.getByRole('button', { name: 'Fri' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Sat' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Thu' })).toBeDisabled();
    });

    it('moves onto the picked day and closes the picker', () => {
        const { onMove } = renderRow();
        fireEvent.click(screen.getByRole('button', { name: /^move$/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Fri' }));

        expect(onMove).toHaveBeenCalledWith('2026-06-19');
        expect(
            screen.getByRole('button', { name: /^move$/i }),
        ).toBeInTheDocument();
    });

    it('enables a past rest day the edit rules offer as a target', () => {
        const pastRest = day({
            id: 4,
            date: '2026-06-15',
            session_type: 'rest',
            segments: [],
            distance_km: 0,
        });
        const missed = day({
            date: '2026-06-16',
            status: 'missed',
            actions: { move: true, skip: false, restore: false },
            move_targets: ['2026-06-15'],
        });
        renderRow({ day: missed, weekDays: [pastRest, missed] });
        fireEvent.click(screen.getByRole('button', { name: /^move$/i }));

        expect(screen.getByRole('button', { name: 'Mon' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Tue' })).toBeDisabled();
    });

    it('hides move when the edit rules offer no target', () => {
        const noTargets = [day({ id: 1, date: '2026-06-18' })];
        renderRow({ day: noTargets[0], weekDays: noTargets });

        expect(
            screen.queryByRole('button', { name: /^move$/i }),
        ).not.toBeInTheDocument();
    });

    it('says when a day emptied by a make-up was made up', () => {
        const emptied = day({
            date: '2026-06-16',
            session_type: 'rest',
            segments: [],
            distance_km: 0,
            status: 'done',
            made_up_on: '2026-06-17',
        });
        renderRow({ day: emptied, weekDays: [emptied] });

        expect(screen.getByText('made up on Wed')).toBeInTheDocument();
    });

    it('links to what was actually run', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'done',
                actual_km: 8.1,
                activities: [
                    { id: 42, km: 8.1, seconds: 2720, started_at: '06:00' },
                ],
            }),
        });

        const link = screen.getByRole('link', { name: /view activity/i });
        expect(link).toHaveAttribute('href', '/activities/42');
        expect(link).toHaveAccessibleName(
            'view activity · 06:00 · 8.1 km · 45:20',
        );
    });

    /**
     * Reported from prod: a 5 km and a 7 km session on one day rendered as a
     * single "12 km · 55:00" — the day's summed distance beside the longer
     * run's clock, an impossible 4:35/km that the athlete never ran.
     */
    it('gives a two-session day one line per run, each with its own time', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'done',
                actual_km: 12,
                activities: [
                    { id: 11, km: 5, seconds: 1380, started_at: '06:00' },
                    { id: 12, km: 7, seconds: 3300, started_at: '06:00' },
                ],
            }),
        });

        const links = screen.getAllByRole('link', { name: /view activity/i });
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', '/activities/11');
        expect(links[0]).toHaveAccessibleName(
            'view activity · 06:00 · 5 km · 23:00',
        );
        expect(links[1]).toHaveAttribute('href', '/activities/12');
        expect(links[1]).toHaveAccessibleName(
            'view activity · 06:00 · 7 km · 55:00',
        );
        // The summed distance must never appear beside one run's duration.
        expect(screen.queryByText(/12 km · 55:00/)).not.toBeInTheDocument();
    });

    it('lists every run, each led by its start time', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                actual_km: 15,
                activities: [
                    { id: 21, km: 5, seconds: 1500, started_at: '05:40' },
                    { id: 22, km: 5, seconds: 1500, started_at: '12:10' },
                    { id: 23, km: 5, seconds: 1500, started_at: '18:30' },
                ],
            }),
        });

        const links = screen.getAllByRole('link', { name: /view activity/i });
        expect(links).toHaveLength(3);
        expect(links[0]).toHaveTextContent(/^05:40/);
        expect(links[2]).toHaveTextContent(/^18:30/);
    });

    it('shows no activity link on a day with nothing logged', () => {
        renderRow({ day: day({ date: '2026-06-15', activities: [] }) });

        expect(
            screen.queryByRole('link', { name: /view activity/i }),
        ).not.toBeInTheDocument();
    });

    it('sums every run when a rest day was run more than once', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                session_type: 'rest',
                segments: [],
                distance_km: 0,
                status: 'done',
                ran_anyway: true,
                prescribed_km: null,
                actual_km: 12,
                activities: [
                    { id: 11, km: 5, seconds: 1380, started_at: '06:00' },
                    { id: 12, km: 7, seconds: 3300, started_at: '06:00' },
                ],
            }),
        });

        expect(
            screen.getByText('ran anyway · 12 km · 1:18:00'),
        ).toBeInTheDocument();
    });

    it('calls out a rest day that was run anyway', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                session_type: 'rest',
                segments: [],
                distance_km: 0,
                status: 'done',
                ran_anyway: true,
                prescribed_km: null,
                actual_km: 5,
                activities: [
                    { id: 7, km: 5, seconds: 1800, started_at: '06:00' },
                ],
            }),
        });

        expect(
            screen.getByText('ran anyway · 5 km · 30:00'),
        ).toBeInTheDocument();
    });

    it('keeps a pinned day leading with the safety advice as one line, not a second session', () => {
        renderRow({
            day: day({
                date: TODAY,
                distance_km: 9.1,
                pinned: true,
                advice_note: 'Eased off, you slept badly.',
            }),
        });

        expect(screen.getByText('9.1 km · 5:00/km')).toBeInTheDocument();
        expect(screen.queryByText('eased today')).not.toBeInTheDocument();
        expect(
            screen.getByText('Eased off, you slept badly.'),
        ).toBeInTheDocument();
    });

    /**
     * The real case: a tempo eased to easy with its distance held. The row
     * headlines the easy run, tempo is only context, and an unrun eased day
     * has a reason for the ease but no read yet — there is no run to read.
     */
    it('headlines an eased day as the eased session, with only the reason showing before it is run', () => {
        renderRow({
            day: day({
                date: TODAY,
                session_type: 'easy',
                distance_km: 6.4,
                asked_km: 6.4,
                segments: [
                    {
                        key: 'main',
                        minutes: 43,
                        zone: 'Z2',
                        pace_label: 'easy',
                        km: 6.4,
                        pace_sec_per_km: 403,
                    },
                ],
                eased_from: {
                    session_type: 'tempo',
                    distance_km: null,
                    voice: 'legs are still carrying the weekend, so today runs easy.',
                },
            }),
            narration: null,
        });

        expect(screen.getByText('6.4 km · 6:43/km')).toBeInTheDocument();
        // The headline carries the "eased" tag, and the detail's change row
        // carries its own copy alongside it.
        expect(screen.getAllByText('eased')).toHaveLength(2);
        // The type change is a labelled row in the detail: the
        // replaced type struck through, the eased-into type at normal
        // weight — no distance row since it held.
        expect(screen.getByText('type')).toBeInTheDocument();
        expect(screen.queryByText('km')).not.toBeInTheDocument();
        expect(document.body).toHaveTextContent(/tempo\s*→\s*easy/);
        expect(
            screen.getByText(
                'legs are still carrying the weekend, so today runs easy.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText("Temari's read")).not.toBeInTheDocument();
    });

    /**
     * #939 decision 6: the clamp line is the reason for the eased session,
     * never the day's read — it renders beside the eased session, and
     * Temari's read renders independently whenever the day has one. A day
     * that has both shows both, each in its own place.
     */
    it("shows both the eased-session reason and Temari's read on a credited day that has both", () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'done',
                session_type: 'easy',
                eased_from: {
                    session_type: 'tempo',
                    distance_km: null,
                    voice: 'legs are still carrying the weekend, so today runs easy.',
                },
            }),
            narration: narrationPayload(),
        });

        const reason = screen.getByText(
            'legs are still carrying the weekend, so today runs easy.',
        );
        const readLabel = screen.getByText("Temari's read");
        const readContent = screen.getByText(
            'easy all the way, tempo block never happened.',
        );

        expect(reason).toBeInTheDocument();
        expect(readLabel).toBeInTheDocument();
        expect(readContent).toBeInTheDocument();

        // Neither the label nor the read's own content contains the reason —
        // they render in separate places, not stacked inside one slot.
        const readBlock = readLabel.parentElement!.parentElement!;
        expect(within(readBlock).queryByText(reason.textContent!)).toBeNull();
        expect(readBlock).not.toContainElement(reason);
    });

    /**
     * A pace-only ease keeps type and distance — no "eased from" line, since
     * neither moved — and shows the step-down as an arrow, since the day's
     * own segments already carry the slower (eased) pace.
     */
    it('shows a pace-only ease as a pace arrow, with type and distance unchanged', () => {
        renderRow({
            day: day({
                date: TODAY,
                session_type: 'long',
                distance_km: 20,
                asked_km: 20,
                segments: [
                    {
                        key: 'main',
                        minutes: 133,
                        zone: 'Z2',
                        pace_label: 'easy',
                        km: 20,
                        pace_sec_per_km: 400,
                    },
                ],
                pace_eased_from: {
                    pace_sec_per_km: 360,
                    voice: "your form's a little flat, so run this one at the easy end of your range.",
                },
            }),
            narration: null,
        });

        expect(screen.getByText('20 km · 6:40/km')).toBeInTheDocument();
        expect(screen.getByText('pace')).toBeInTheDocument();
        expect(document.body).toHaveTextContent('6:00');
        expect(document.body).toHaveTextContent('6:40/km');
        // Pace never gets a directional arrow or colour — a bigger number is
        // an easier day, not a "down" one.
        expect(screen.getByText('→')).toHaveClass('text-text-2');
        expect(screen.queryByText('↑')).not.toBeInTheDocument();
        expect(screen.queryByText('↓')).not.toBeInTheDocument();
        // One "eased" tag on the headline, one on the detail's change row.
        expect(screen.getAllByText('eased')).toHaveLength(2);
        expect(
            screen.queryByText('eased from long run'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                "your form's a little flat, so run this one at the easy end of your range.",
            ),
        ).toBeInTheDocument();
    });

    it('drops the pace-ease voice, but keeps the pace delta, once the day is credited', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'done',
                session_type: 'long',
                segments: [
                    {
                        key: 'main',
                        minutes: 133,
                        zone: 'Z2',
                        pace_label: 'easy',
                        km: 20,
                        pace_sec_per_km: 400,
                    },
                ],
                pace_eased_from: { pace_sec_per_km: 360, voice: null },
            }),
            narration: null,
        });

        expect(document.body).toHaveTextContent('6:00');
        expect(document.body).toHaveTextContent('6:40/km');
    });

    /**
     * #975: three independent change lines used to stack as full sentences.
     * Even combined on one (synthetic) day, each stays a short delta pair —
     * this proves the row copes with all three at once rather than turning
     * into a paragraph.
     */
    it('renders all three change kinds together, each as its own short delta line', () => {
        renderRow({
            day: day({
                date: TODAY,
                session_type: 'easy',
                distance_km: 4.1,
                asked_km: 3.6,
                segments: [
                    {
                        key: 'main',
                        minutes: 27,
                        zone: 'Z2',
                        pace_label: 'easy',
                        km: 4.1,
                        pace_sec_per_km: 400,
                    },
                ],
                eased_from: {
                    session_type: 'tempo',
                    distance_km: 5.9,
                    voice: null,
                },
                pace_eased_from: { pace_sec_per_km: 360, voice: null },
            }),
        });

        // Headline: current numbers plus at most two small tags — never
        // more, and never the old values or the arrows.
        const trigger = headline();
        expect(trigger).toHaveTextContent('4.1 km · 6:40/km');
        expect(within(trigger).getByText('eased')).toBeInTheDocument();
        expect(within(trigger).getByText('topped up')).toBeInTheDocument();
        expect(trigger).not.toHaveTextContent('5.9');
        expect(trigger).not.toHaveTextContent('3.6');
        expect(trigger).not.toHaveTextContent('tempo');

        // Detail: one labelled row per changed thing, each its own short
        // line rather than a paragraph.
        expect(document.body).toHaveTextContent(/tempo\s*→\s*easy/);
        expect(document.body).toHaveTextContent(/5\.9\s*[↑↓]\s*4\.1/);
        expect(document.body).toHaveTextContent(/6:00\s*→\s*6:40\/km/);
        expect(document.body).toHaveTextContent(/3\.6\s*[↑↓]\s*4\.1/);
        expect(screen.getByText('type')).toBeInTheDocument();
        expect(screen.getAllByText('km')).toHaveLength(2); // the eased-distance row and the week-fit row
        expect(screen.getByText('pace')).toBeInTheDocument();
        // "eased" tags the headline plus the type/km/pace rows (4); the week
        // resize tags the headline and explains itself on its own row.
        expect(screen.getAllByText('eased')).toHaveLength(4);
        expect(screen.getByText('making up the week')).toBeInTheDocument();
    });

    it('keeps the headline informative via its tags, and leaves the full change to the detail', () => {
        renderRow({
            day: day({
                date: TODAY,
                session_type: 'easy',
                distance_km: 4.1,
                eased_from: {
                    session_type: 'tempo',
                    distance_km: 5.9,
                    voice: null,
                },
            }),
        });

        const trigger = headline();
        expect(trigger).toHaveTextContent(/eased/);
        expect(trigger).not.toHaveTextContent(/tempo/);

        expect(document.body).toHaveTextContent(/tempo\s*→\s*easy/);
        expect(document.body).toHaveTextContent(/5\.9\s*[↑↓]\s*4\.1/);
    });

    /** A finished day states what it came to; the second-menu prompt is gone,
     *  and the server ships no advice once the day is credited. */
    it('explains a long day whose distance arrived in pieces', () => {
        renderRow({
            day: day({
                date: TODAY,
                status: 'partial',
                advice_note: null,
                credit_note:
                    'the distance was there, but not in one run. a long day is time on feet in one go.',
            }),
        });

        expect(screen.getByText(/not in one run/)).toBeInTheDocument();
    });

    it('shows no advice line on a day nothing eased', () => {
        renderRow();

        expect(screen.queryByText('eased today')).not.toBeInTheDocument();
    });

    it('states both recorded facts on a day the plan has judged, one side per line: what it asked for, and what was run', () => {
        renderRow({
            day: day({ prescribed_km: 6, actual_km: 5, distance_km: 8 }),
        });

        expect(screen.getByText('asked 6 km · 5:00/km')).toBeInTheDocument();
        expect(screen.getByText('ran 5 km')).toBeInTheDocument();
        expect(screen.queryByText(/8 km/)).not.toBeInTheDocument();
    });

    it('keeps the two numbers straight on a day that went long', () => {
        renderRow({
            day: day({ prescribed_km: 9, actual_km: 12, distance_km: 8 }),
        });

        expect(screen.getByText('asked 9 km · 5:00/km')).toBeInTheDocument();
        expect(screen.getByText('ran 12 km')).toBeInTheDocument();
    });

    it('shows the ask alone on a day that has not been judged yet', () => {
        renderRow({ day: day({ prescribed_km: null, distance_km: 8 }) });

        expect(screen.getByText(/8 km/)).toBeInTheDocument();
    });

    /**
     * #940: a graded day's prescribed pace used to sit right after "km run",
     * reading as the run's own pace — the actual run averaged something
     * else entirely. Once the day is credited, both figures show, each
     * labelled with its own word.
     */
    it('labels both paces once the day has a credited run', () => {
        renderRow({
            day: day({
                status: 'done',
                prescribed_km: 6.4,
                actual_km: 5.3,
                ran_pace_sec_per_km: 403,
                segments: [
                    {
                        key: 'main',
                        minutes: 30,
                        zone: 'Z4',
                        pace_label: 'threshold',
                        km: 5.2,
                        pace_sec_per_km: 332,
                    },
                ],
            }),
        });

        expect(screen.getByText('asked 6.4 km · 5:32/km')).toBeInTheDocument();
        expect(screen.getByText('ran 5.3 km · 6:43/km')).toBeInTheDocument();
    });

    it('shows the pace as before on a day still unrun', () => {
        renderRow({
            day: day({
                status: 'planned',
                prescribed_km: null,
                ran_pace_sec_per_km: null,
            }),
        });

        expect(screen.getByText('8 km · 5:00/km')).toBeInTheDocument();
        expect(screen.queryByText(/target/)).not.toBeInTheDocument();
        expect(screen.queryByText(/ran \d+:\d+\/km/)).not.toBeInTheDocument();
    });

    it('never mixes both sides on one line once the day is graded', () => {
        renderRow({
            day: day({
                status: 'done',
                prescribed_km: 6.4,
                actual_km: 5.3,
                ran_pace_sec_per_km: 403,
                segments: [
                    {
                        key: 'main',
                        minutes: 30,
                        zone: 'Z4',
                        pace_label: 'threshold',
                        km: 5.2,
                        pace_sec_per_km: 332,
                    },
                ],
            }),
        });

        // The old sentence mixed distance from both sides on one line and the
        // pace on another, unlabelled. Each side now owns one line with its
        // own distance and pace together.
        expect(screen.queryByText(/km asked · .* km run/)).toBeNull();
        expect(screen.queryByText(/target .* · ran /)).toBeNull();
        expect(screen.getByText('ran 5.3 km · 6:43/km')).toBeInTheDocument();
    });

    it('reads an easy day run too hard as ran hot, with the evidence in the detail', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                session_type: 'easy',
                status: 'overreached',
                compliance_score: 103,
                prescribed_km: 6.8,
                actual_km: 7,
                credited_km: 7,
                ran_hot: true,
                result_note:
                    'it ran harder than the easy effort the day asked for.',
            }),
        });

        expect(screen.getByText(/^ran hot/)).toBeInTheDocument();
        expect(screen.queryByText(/^overreached/)).not.toBeInTheDocument();

        expect(
            screen.getByText(
                'it ran harder than the easy effort the day asked for.',
            ),
        ).toBeInTheDocument();
    });

    it('keeps overreached for a day that simply ran well past its distance', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                session_type: 'easy',
                status: 'overreached',
                compliance_score: 140,
                prescribed_km: 5,
                actual_km: 7,
                credited_km: 7,
            }),
        });

        expect(screen.getByText(/^overreached/)).toBeInTheDocument();
    });
});

const REST = day({
    id: 2,
    date: '2026-06-19',
    session_type: 'rest',
    segments: [],
    distance_km: 0,
});

describe('showsNarration', () => {
    it('shows a finished read and a failed one, never a pending or empty one', () => {
        expect(showsNarration(narrationPayload())).toBe(true);
        expect(showsNarration(narrationPayload({ status: 'failed' }))).toBe(
            true,
        );
        expect(showsNarration(narrationPayload({ status: 'pending' }))).toBe(
            false,
        );
        expect(showsNarration(narrationPayload({ content: null }))).toBe(false);
        expect(showsNarration(null)).toBe(false);
    });
});

describe('hasDayDetail', () => {
    it('has detail for a sized session still ahead', () => {
        expect(hasDayDetail(day(), null)).toBe(true);
    });

    it('has none for a plain rest day', () => {
        expect(hasDayDetail(REST, null)).toBe(false);
    });

    it('has detail for a rest day once a read is in', () => {
        expect(hasDayDetail(REST, narrationPayload())).toBe(true);
    });

    it('has detail for a day carrying safety advice even with no segments', () => {
        expect(
            hasDayDetail(
                day({
                    date: TODAY,
                    segments: [],
                    advice_note: 'legs need it.',
                }),
                null,
            ),
        ).toBe(true);
    });
});
