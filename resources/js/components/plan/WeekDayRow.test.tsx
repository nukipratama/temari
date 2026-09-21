import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanDay } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import WeekDayRow from './WeekDayRow';

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

function day(overrides: Partial<PlanDay> = {}): PlanDay {
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
        clamp: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        activities: [],
        flagged: false,
        ...overrides,
    };
}

/** Thursday's tempo, with Fri/Sat rest days it could move onto. */
const WEEK: PlanDay[] = [
    day({ id: 1, date: '2026-06-18' }),
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

function renderRow(overrides: Partial<Parameters<typeof WeekDayRow>[0]> = {}) {
    const props = {
        day: WEEK[0],
        weekDays: WEEK,
        today: TODAY,
        narration: null,
        onMove: vi.fn(),
        onSkip: vi.fn(),
        ...overrides,
    };
    render(<WeekDayRow {...props} />);
    return props;
}

function expand() {
    fireEvent.click(screen.getByRole('button', { name: /tempo/i }));
}

describe('WeekDayRow', () => {
    it('scrolls itself into view when it is the day that was asked for', () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;

        renderRow({ focused: true });

        expect(scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('stays put when another day was asked for', () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;

        renderRow();

        expect(scrollIntoView).not.toHaveBeenCalled();
    });

    it('opens already expanded when it is the day that was asked for', () => {
        renderRow({ focused: true });

        expect(screen.getByRole('button', { name: /tempo/i })).toHaveAttribute(
            'aria-expanded',
            'true',
        );
    });

    it('leaves a day closed when it is not the one that was asked for', () => {
        renderRow({ focused: false });

        expect(screen.getByRole('button', { name: /tempo/i })).toHaveAttribute(
            'aria-expanded',
            'false',
        );
    });

    it('summarises the day without expanding it', () => {
        renderRow();

        expect(screen.getByText('Thu')).toBeInTheDocument();
        expect(screen.getByText('tempo')).toBeInTheDocument();
        expect(screen.getByText('8 km · 5:00/km')).toBeInTheDocument();
    });

    /**
     * The week-adjustment arrow used to always point down, even when the
     * number went up (#975). It must point the way the ask actually moved.
     */
    it("tags the collapsed row 'week fit' but keeps the old value out of it, then shows the delta pointing down once expanded", () => {
        renderRow({ day: day({ distance_km: 3, asked_km: 8 }) });

        expect(screen.getByText('week fit')).toBeInTheDocument();
        expect(screen.queryByText('8')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /tempo/i }));

        expect(screen.getByText('km')).toBeInTheDocument();
        expect(screen.getByText('8')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('↓')).toHaveClass('text-ember-ink');
    });

    it("shows the week-fit delta pointing up when the week's redistribution raised the ask", () => {
        renderRow({ day: day({ distance_km: 4.1, asked_km: 3.6 }) });
        fireEvent.click(screen.getByRole('button', { name: /tempo/i }));

        expect(screen.getByText('3.6')).toBeInTheDocument();
        expect(screen.getByText('4.1')).toBeInTheDocument();
        expect(screen.getByText('↑')).toHaveClass('text-leaf-ink');
    });

    it('stays quiet when the redistributed figure matches what was asked', () => {
        renderRow({ day: day({ distance_km: 8, asked_km: 8 }) });

        expect(screen.queryByText('week fit')).not.toBeInTheDocument();
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

        expect(screen.queryByText('week fit')).not.toBeInTheDocument();
    });

    it('starts closed, as the prototype does', () => {
        renderRow();

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
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

    /**
     * #939: the day's take is labelled "Temari's read", not "Temari's take" —
     * the backend only ever hands this row a `narration` payload once the
     * day is credited (see PlanNarrationRequesterTest), so the row itself
     * renders whatever it is given.
     */
    it("labels a credited day's narration as Temari's read once expanded", () => {
        renderRow({
            day: day({ status: 'done' }),
            narration: narrationPayload(),
        });
        expand();

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
        expand();

        expect(screen.queryByText("Temari's read")).not.toBeInTheDocument();
    });

    it('offers move and skip on a day still ahead', () => {
        renderRow();
        expand();

        expect(
            screen.getByRole('button', { name: /move this session/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /skip this session/i }),
        ).toBeInTheDocument();
    });

    it('offers neither on a day that has already passed', () => {
        renderRow({ day: day({ date: '2026-06-15', status: 'done' }) });
        expand();

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /skip this session/i }),
        ).not.toBeInTheDocument();
    });

    it('renders a plain rest day flat, with no chevron or focusable trigger', () => {
        renderRow({ day: WEEK[1] });

        expect(
            screen.queryByRole('button', { name: /rest/i }),
        ).not.toBeInTheDocument();
        // The flag control is its own, separate affordance — it stays.
        expect(
            screen.getByRole('button', { name: 'flag this day' }),
        ).toBeInTheDocument();
        expect(screen.getByText('rest')).toBeInTheDocument();
        expect(document.querySelector('[aria-expanded]')).toBeNull();
    });

    it('becomes expandable again once a rest day has a logged run', () => {
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
                activities: [{ id: 7, km: 5, seconds: 1800 }],
            }),
        });

        expect(
            screen.getByRole('button', { name: /rest/i }),
        ).toBeInTheDocument();
    });

    it('offers neither move nor skip once a rest day is expanded by its logged run', () => {
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
                activities: [{ id: 7, km: 5, seconds: 1800 }],
            }),
        });
        fireEvent.click(screen.getByRole('button', { name: /rest/i }));

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /skip this session/i }),
        ).not.toBeInTheDocument();
    });

    it('stays flat on a past rest day with nothing else to show', () => {
        renderRow({
            day: day({
                date: '2026-06-10',
                session_type: 'rest',
                segments: [],
                distance_km: 0,
                status: 'done',
            }),
        });

        expect(
            screen.queryByRole('button', { name: /rest/i }),
        ).not.toBeInTheDocument();
    });

    it('does not offer skip twice on an already-excused day', () => {
        renderRow({ day: day({ skipped: true }) });
        expand();

        expect(
            screen.queryByRole('button', { name: /skip this session/i }),
        ).not.toBeInTheDocument();
    });

    it('skips through to the caller', () => {
        const { onSkip } = renderRow();
        expand();
        fireEvent.click(
            screen.getByRole('button', { name: /skip this session/i }),
        );

        expect(onSkip).toHaveBeenCalledOnce();
    });

    it('offers a weekday picker whose only enabled targets are later rest days', () => {
        renderRow();
        expand();
        fireEvent.click(
            screen.getByRole('button', { name: /move this session/i }),
        );

        expect(screen.getByRole('button', { name: 'Fri' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Sat' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Thu' })).toBeDisabled();
    });

    it('moves onto the picked day and closes the picker', () => {
        const { onMove } = renderRow();
        expand();
        fireEvent.click(
            screen.getByRole('button', { name: /move this session/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Fri' }));

        expect(onMove).toHaveBeenCalledWith('2026-06-19');
        expect(
            screen.getByRole('button', { name: /move this session/i }),
        ).toBeInTheDocument();
    });

    it('hides move when the week has no rest day left to move onto', () => {
        const noTargets = [day({ id: 1, date: '2026-06-18' })];
        renderRow({ day: noTargets[0], weekDays: noTargets });
        expand();

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
    });

    it('links to what was actually run', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'done',
                actual_km: 8.1,
                activities: [{ id: 42, km: 8.1, seconds: 2720 }],
            }),
        });
        expand();

        const link = screen.getByRole('link', { name: /view activity/i });
        expect(link).toHaveAttribute('href', '/activities/42');
        expect(link).toHaveTextContent('8.1 km · 45:20');
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
                    { id: 11, km: 5, seconds: 1380 },
                    { id: 12, km: 7, seconds: 3300 },
                ],
            }),
        });
        expand();

        const links = screen.getAllByRole('link', { name: /view activity/i });
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', '/activities/11');
        expect(links[0]).toHaveTextContent('5 km · 23:00');
        expect(links[1]).toHaveAttribute('href', '/activities/12');
        expect(links[1]).toHaveTextContent('7 km · 55:00');
        // The summed distance must never appear beside one run's duration.
        expect(screen.queryByText(/12 km · 55:00/)).not.toBeInTheDocument();
    });

    it('shows no activity link on a day with nothing logged', () => {
        renderRow({ day: day({ date: '2026-06-15', activities: [] }) });
        expand();

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
                    { id: 11, km: 5, seconds: 1380 },
                    { id: 12, km: 7, seconds: 3300 },
                ],
            }),
        });

        expect(
            screen.getByText('Ran anyway · 12 km · 1:18:00'),
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
                activities: [{ id: 7, km: 5, seconds: 1800 }],
            }),
        });

        expect(
            screen.getByText('Ran anyway · 5 km · 30:00'),
        ).toBeInTheDocument();
    });

    /**
     * The clamp is advisory: it eases today, it does not replace the plan. The
     * card must still lead with what the plan asked for, because that is what
     * the narration above it describes and what compliance grades against.
     */
    it('shows the readiness step-down beside the day, not instead of it', () => {
        renderRow({
            day: day({
                date: TODAY,
                distance_km: 9.1,
                clamp: {
                    session_type: 'easy',
                    distance_km: 5.9,
                    pace_sec_per_km: 450,
                    note: 'Eased off, you slept badly.',
                    label: 'eased today',
                },
            }),
        });
        expand();

        expect(screen.getByText('9.1 km · 5:00/km')).toBeInTheDocument();
        expect(screen.getByText('eased today')).toBeInTheDocument();
        expect(screen.getByText('easy · 5.9 km · 7:30/km')).toBeInTheDocument();
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
        const trigger = screen.getByRole('button', { name: /easy/i });
        fireEvent.click(trigger);

        expect(screen.getByText('6.4 km · 6:43/km')).toBeInTheDocument();
        // The collapsed row already carried the "eased" tag before the
        // click, and the expanded row carries its own copy alongside it.
        expect(screen.getAllByText('eased')).toHaveLength(2);
        // The type change is a labelled row in the expanded panel: the
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
        fireEvent.click(screen.getByRole('button', { name: /easy/i }));

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
        const trigger = screen.getByRole('button', { name: /long run/i });
        fireEvent.click(trigger);

        expect(screen.getByText('20 km · 6:40/km')).toBeInTheDocument();
        expect(screen.getByText('pace')).toBeInTheDocument();
        expect(document.body).toHaveTextContent('6:00');
        expect(document.body).toHaveTextContent('6:40/km');
        // Pace never gets a directional arrow or colour — a bigger number is
        // an easier day, not a "down" one.
        expect(screen.getByText('→')).toHaveClass('text-text-2');
        expect(screen.queryByText('↑')).not.toBeInTheDocument();
        expect(screen.queryByText('↓')).not.toBeInTheDocument();
        // One "eased" tag on the collapsed row, one on the expanded row.
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
        const trigger = screen.getByRole('button', { name: /long run/i });
        fireEvent.click(trigger);

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

        // Collapsed: current numbers plus at most two small tags — never
        // more, and never the old values or the arrows.
        const trigger = screen.getByRole('button', { name: /easy/i });
        expect(trigger).toHaveTextContent('4.1 km · 6:40/km');
        expect(screen.getByText('eased')).toBeInTheDocument();
        expect(screen.getByText('week fit')).toBeInTheDocument();
        expect(trigger).not.toHaveTextContent('5.9');
        expect(trigger).not.toHaveTextContent('3.6');
        expect(trigger).not.toHaveTextContent('tempo');

        fireEvent.click(trigger);

        // Expanded: one labelled row per changed thing, each its own short
        // line rather than a paragraph.
        expect(document.body).toHaveTextContent(/tempo\s*→\s*easy/);
        expect(document.body).toHaveTextContent(/5\.9\s*[↑↓]\s*4\.1/);
        expect(document.body).toHaveTextContent(/6:00\s*→\s*6:40\/km/);
        expect(document.body).toHaveTextContent(/3\.6\s*[↑↓]\s*4\.1/);
        expect(screen.getByText('type')).toBeInTheDocument();
        expect(screen.getAllByText('km')).toHaveLength(2); // the eased-distance row and the week-fit row
        expect(screen.getByText('pace')).toBeInTheDocument();
        // "eased" tags the collapsed row plus the type/km/pace rows (4); "week
        // fit" tags the collapsed row plus its own row (2) — never more than
        // two tags on any one line.
        expect(screen.getAllByText('eased')).toHaveLength(4);
        expect(screen.getAllByText('week fit')).toHaveLength(2);
    });

    it('keeps the collapsed row informative via its tags, and reveals the full detail once expanded', () => {
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

        const trigger = screen.getByRole('button', { name: /easy/i });
        expect(trigger).toHaveAccessibleName(/eased/);
        expect(trigger).not.toHaveAccessibleName(/tempo/);

        fireEvent.click(trigger);

        expect(document.body).toHaveTextContent(/tempo\s*→\s*easy/);
        expect(document.body).toHaveTextContent(/5\.9\s*[↑↓]\s*4\.1/);
    });

    /** The server decides what the step-down is for; the row must not hardcode
     *  a label that contradicts the note beside it. */
    it('renders the server label rather than a fixed one', () => {
        renderRow({
            day: day({
                date: TODAY,
                distance_km: 9.1,
                clamp: {
                    session_type: 'easy',
                    distance_km: 3.6,
                    pace_sec_per_km: 450,
                    note: 'Quality work waits until you are fresher.',
                    label: 'stepped down',
                },
            }),
        });
        expand();

        expect(screen.getByText('stepped down')).toBeInTheDocument();
        expect(screen.queryByText('eased today')).not.toBeInTheDocument();
    });

    /** A finished day states what it came to; the second-menu prompt is gone,
     *  and the server ships no clamp once the day is credited. */
    it('explains a long day whose distance arrived in pieces', () => {
        renderRow({
            day: day({
                date: TODAY,
                status: 'partial',
                clamp: null,
                credit_note:
                    'the distance was there, but not in one run. a long day is time on feet in one go.',
            }),
        });
        expand();

        expect(screen.getByText(/not in one run/)).toBeInTheDocument();
    });

    it('shows no step-down on a day the clamp did not touch', () => {
        renderRow();
        expand();

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

    it('offers one icon-only flag control without expanding the day', () => {
        renderRow();

        const flag = screen.getByRole('button', { name: 'flag this day' });

        expect(flag).toHaveTextContent('');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('keeps the flag out of the trigger it sits beside', () => {
        renderRow();

        const trigger = screen.getByRole('button', { name: /tempo/i });
        const flag = screen.getByRole('button', { name: 'flag this day' });

        expect(trigger).not.toContainElement(flag);
        expect(flag.parentElement).toBe(trigger.parentElement);
    });

    it('draws no flag at all on a day already flagged', () => {
        renderRow({ day: day({ flagged: true }) });

        expect(screen.queryByLabelText('flagged')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'flag this day' }),
        ).toBeNull();
    });
});
