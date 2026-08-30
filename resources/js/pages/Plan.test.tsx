import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { stubSyncAnimationFrame } from '@/test/setup';

import Plan from './Plan';

const DISCLAIMER_HEADLINE = 'Training guidance, not medical advice';
const DISCLAIMER =
    'Temari prescribes from your own data, not from a medical assessment. These numbers are training guidance, not medical advice. Pain, illness or injury is a conversation for a doctor, not a plan engine.';

// framer-motion's prefers-reduced-motion check is a module-level singleton
// that lazily initializes once and never re-checks: it must be forced before
// this file's first render, or a later per-test override has no effect. This
// file's fake system time (below) also makes the real animation-frame loop
// count-ups otherwise depend on unreliable within a single worker, so render
// every count-up here as an instant snap to its target instead of a tween.
window.matchMedia = ((query: string) => ({
    matches: query === '(prefers-reduced-motion)',
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
})) as unknown as typeof window.matchMedia;

const TODAY = '2026-08-10';

const DAY = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    date: TODAY,
    phase: 'build',
    session_type: 'easy',
    segments: [
        {
            key: 'main' as const,
            minutes: 44.0,
            zone: 'Z2',
            pace_label: 'easy' as const,
            pace_sec_per_km: 330,
        },
    ],
    distance_km: 8.0,
    pinned: false,
    skipped: false,
    status: 'planned',
    compliance_score: null,
    ran_anyway: false,
    clamp_note: null,
    ...overrides,
});

const WEEK = (overrides: Record<string, unknown> = {}) => ({
    week_start: TODAY,
    phase: 'build',
    type: 'current' as const,
    days: [DAY()],
    ...overrides,
});

const SEASON = {
    starts_at: TODAY,
    ends_at: '2026-11-02',
    week_index: 1,
    total_weeks: 12,
    is_race_oriented: false,
    tiers_kept_from_past_seasons: 0,
    goals: [],
};

const ANALYSIS = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    status: 'done' as const,
    content: 'a plan narration line.',
    type: 'plan_day_voice' as const,
    subject_type: 'plan_day_voice_user_day',
    subject_id: 1,
    discriminator: TODAY,
    attempts: 1,
    generated_at: '2026-08-10T00:00:00Z',
    retry_after_seconds: null,
    ...overrides,
});

function lastPatchCall() {
    return vi.mocked(router.patch).mock.calls.at(-1);
}

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

function lastDeleteCall() {
    return vi.mocked(router.delete).mock.calls.at(-1);
}

describe('Plan', () => {
    it('coach-marks the week schedule on a first visit', () => {
        window.localStorage.clear();
        stubSyncAnimationFrame();
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );
        expect(
            screen.getByRole('dialog', { name: "The week's yours" }),
        ).toBeInTheDocument();
    });

    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(`${TODAY}T08:00:00`));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows an empty state with no weeks generated yet', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={3}
                adaptation={null}
                season={SEASON}
                weeks={[]}
            />,
        );

        expect(screen.getByText('No plan yet.')).toBeInTheDocument();
    });

    it('pays the season track one tier per completed goal, and holds it back with no goals at all', () => {
        const goal = (id: number, is_completed: boolean) => ({
            id,
            title: `Goal ${id}`,
            current: is_completed ? 10 : 2,
            target: 10,
            unit: 'sessions',
            is_completed,
        });

        const { rerender } = render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={{
                    ...SEASON,
                    goals: [
                        goal(1, true),
                        goal(2, true),
                        goal(3, false),
                        goal(4, false),
                        goal(5, false),
                    ],
                }}
                weeks={[WEEK()]}
            />,
        );

        expect(
            screen.getByRole('img', {
                name: 'Season track: 2 of 5 tiers earned',
            }),
        ).toBeInTheDocument();

        rerender(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        expect(screen.queryByText('Season Track')).not.toBeInTheDocument();
    });

    it("renders a session's type, distance, and pace", () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        expect(screen.getByText(/Easy/)).toBeInTheDocument();
        expect(screen.getByText(/8 km/)).toBeInTheDocument();
        expect(screen.getByText(/5:30\/km/)).toBeInTheDocument();
    });

    it('shows the readiness-clamp explanation when present', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[
                    WEEK({
                        days: [
                            DAY({
                                clamp_note:
                                    "Your form dipped, so today's the easy version instead.",
                            }),
                        ],
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/Your form dipped/)).toBeInTheDocument();
    });

    it('links to /race, mentioning a set race by name', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={{ race_date: '2026-12-06', name: 'Jakarta 10K' }}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[]}
            />,
        );

        expect(screen.getByText(/Jakarta 10K/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Change your race' }),
        ).toHaveAttribute('href', '/race');
    });

    it('offers to set a race when there is none', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={3}
                adaptation={null}
                season={SEASON}
                weeks={[]}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Set a race' }),
        ).toHaveAttribute('href', '/race');
    });

    it('posts to /plan/regenerate on Regenerate', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Regenerate' }));

        expect(lastPostCall()?.[0]).toBe('/plan/regenerate');
    });

    it('pins a day', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pin' }));

        expect(lastPatchCall()?.[0]).toBe('/plan/sessions/1');
        expect(lastPatchCall()?.[1]).toEqual({ pinned: true });
    });

    it('unpins an already-pinned day', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK({ days: [DAY({ pinned: true })] })]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Unpin' }));

        expect(lastPatchCall()?.[1]).toEqual({ pinned: false });
    });

    it('skips a day', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Skip' }));

        expect(lastPatchCall()?.[0]).toBe('/plan/sessions/1');
        expect(lastPatchCall()?.[1]).toEqual({ skipped: true });
    });

    it('unskips an already-skipped day', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK({ days: [DAY({ skipped: true })] })]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Unskip' }));

        expect(lastPatchCall()?.[1]).toEqual({ skipped: false });
    });

    it('blocks a training day to rest', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Block' }));

        expect(lastPatchCall()?.[1]).toEqual({ session_type: 'rest' });
    });

    it('restores a rest day back to easy', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[
                    WEEK({
                        days: [DAY({ session_type: 'rest', segments: [] })],
                    }),
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Restore' }));

        expect(lastPatchCall()?.[1]).toEqual({ session_type: 'easy' });
    });

    it('deletes a day', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Delete/ }));

        expect(lastDeleteCall()?.[0]).toBe('/plan/sessions/1');
    });

    it('moves a day to a new date', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.change(screen.getByLabelText(`Move ${TODAY}`), {
            target: { value: '2026-08-15' },
        });

        expect(lastPatchCall()?.[1]).toEqual({ date: '2026-08-15' });
    });

    it('renders the season arc progress and a link to the badge board', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={{
                    ...SEASON,
                    week_index: 3,
                    total_weeks: 12,
                }}
                weeks={[]}
            />,
        );

        expect(screen.getByText(/Week 3 of 12/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Badge board' }),
        ).toHaveAttribute('href', '/trends');
    });

    it("renders each season goal's title and progress", () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={{
                    ...SEASON,
                    goals: [
                        {
                            id: 1,
                            title: 'Complete your planned sessions',
                            current: 3,
                            target: 10,
                            unit: 'sessions',
                            is_completed: false,
                        },
                    ],
                }}
                weeks={[]}
            />,
        );

        expect(
            screen.getByText('Complete your planned sessions'),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, el) => el?.textContent === '3/10'),
        ).toBeInTheDocument();
    });

    it('hides edit controls for history weeks', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK({ type: 'history', week_start: '2026-07-27' })]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Pin' }),
        ).not.toBeInTheDocument();
    });

    it('shows the current week’s phase as the season visual caption', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK({ phase: 'peak' })]}
            />,
        );

        // "Peak" also labels the per-week chip in the schedule below, so
        // assert on the season caption text instead, which is unique.
        expect(
            screen.getByText(/most intricate the pattern gets/),
        ).toBeInTheDocument();
    });

    it('pauses season-visual accretion on a deload week instead of resetting it', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[
                    WEEK({
                        week_start: '2026-07-27',
                        phase: 'build',
                        type: 'history',
                    }),
                    WEEK({ phase: 'deload' }),
                ]}
            />,
        );

        // The deload week borrows the last non-deload phase (build) rather
        // than falling back to base — asserted via the build caption, since
        // "Build" also labels the history week's own chip below.
        expect(
            screen.getByText(/Coverage building, bands starting to lock in/),
        ).toBeInTheDocument();
    });

    it('falls back to the base season phase when no current week exists', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[]}
            />,
        );

        expect(screen.getByText('Base')).toBeInTheDocument();
    });

    it('always shows the not-medical-advice disclaimer, adaptation or not', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[]}
            />,
        );

        expect(screen.getByText(DISCLAIMER)).toBeInTheDocument();
    });

    it('heads the disclaimer and links out to the full scope', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[]}
            />,
        );

        expect(screen.getByText(DISCLAIMER_HEADLINE)).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'What the plan can and cannot see',
            }),
        ).toHaveAttribute('href', '/training-disclaimer');
    });

    // Mirrors App\Enums\AdaptationReason::headline() — every reason the
    // periodizer can record has to read as a decision, not a failure.
    it.each([
        ['steady', 'on plan', false],
        ['low_readiness', 'deload week', true],
        ['high_monotony', 'deload week', true],
        ['high_strain', 'deload week', true],
        ['missed_week', 'deload week', true],
        ['behind_race_pace', 'one more quality session', false],
        ['ahead_of_race_pace', 'one less quality session', false],
    ] as const)(
        'renders the %s adaptation headline in Temari’s own voice',
        (reason, headline, deload) => {
            render(
                <Plan
                    disclaimerHeadline={DISCLAIMER_HEADLINE}
                    disclaimer={DISCLAIMER}
                    race={null}
                    sessionsPerWeek={4}
                    adaptation={{
                        reason,
                        headline,
                        detail: 'the detail line.',
                        deload,
                    }}
                    season={SEASON}
                    weeks={[WEEK()]}
                />,
            );

            const rendered = screen.getByText(headline);
            expect(rendered).toBeInTheDocument();
            // Narrated voice stays lowercase: the headline must not be
            // rendered through an uppercasing chrome utility.
            expect(rendered.className).not.toContain('text-label');
        },
    );

    it("explains this week's adaptation when the periodizer recorded one", () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={{
                    reason: 'missed_week',
                    headline: 'deload week',
                    detail: "you finished 20% of last week's sessions. this week comes back smaller, not doubled.",
                    deload: true,
                }}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        expect(screen.getByText('deload week')).toBeInTheDocument();
        expect(
            screen.getByText(/comes back smaller, not doubled/),
        ).toBeInTheDocument();
    });

    it('labels a partially completed history session', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[
                    WEEK({
                        week_start: '2026-07-27',
                        type: 'history',
                        days: [DAY({ status: 'partial' })],
                    }),
                ]}
            />,
        );

        expect(screen.getByText('Partial')).toBeInTheDocument();
    });

    it("renders a day's narration only for the current week", () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[
                    WEEK({ type: 'current' }),
                    WEEK({
                        week_start: '2026-08-17',
                        type: 'lookahead',
                        days: [DAY({ id: 2, date: '2026-08-17' })],
                    }),
                ]}
                planNarration={{
                    days: {
                        [TODAY]: ANALYSIS({ content: 'today, narrated.' }),
                        '2026-08-17': ANALYSIS({
                            content: 'a lookahead day, narrated.',
                        }),
                    },
                    week: null,
                    season: null,
                }}
            />,
        );

        expect(screen.getByText('today, narrated.')).toBeInTheDocument();
        expect(
            screen.queryByText('a lookahead day, narrated.'),
        ).not.toBeInTheDocument();
    });

    it('renders week and season narration when present', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={{
                    reason: 'steady',
                    headline: 'On plan',
                    detail: 'Nothing to adjust this week.',
                    deload: false,
                }}
                season={SEASON}
                weeks={[WEEK()]}
                planNarration={{
                    days: {},
                    week: ANALYSIS({ content: 'steady week, narrated.' }),
                    season: ANALYSIS({ content: 'a self-scaled block.' }),
                }}
            />,
        );

        expect(screen.getByText('steady week, narrated.')).toBeInTheDocument();
        expect(screen.getByText('a self-scaled block.')).toBeInTheDocument();
    });

    it('disables Regenerate and shows a countdown while its cooldown is active', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
                regenerateCooldownSeconds={125}
            />,
        );

        const button = screen.getByRole('button', { name: '2:05' });
        expect(button).toBeDisabled();
    });

    it('leaves Regenerate enabled with no active cooldown', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
                regenerateCooldownSeconds={null}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Regenerate' }),
        ).toBeEnabled();
    });

    it('no longer renders a weekly-streak panel (moved to Trends)', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        expect(screen.queryByText('Weekly Streak')).not.toBeInTheDocument();
        expect(screen.queryByText('Rest Weeks')).not.toBeInTheDocument();
    });

    it("keeps a session's segment breakdown collapsed until expanded", () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        expect(screen.queryByText('44 min')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Segments'));

        expect(screen.getByText('44 min')).toBeInTheDocument();
    });

    it('shows no segment toggle on a rest day', () => {
        render(
            <Plan
                disclaimerHeadline={DISCLAIMER_HEADLINE}
                disclaimer={DISCLAIMER}
                race={null}
                sessionsPerWeek={4}
                adaptation={null}
                season={SEASON}
                weeks={[
                    WEEK({
                        days: [DAY({ session_type: 'rest', segments: [] })],
                    }),
                ]}
            />,
        );

        expect(screen.queryByText('Segments')).not.toBeInTheDocument();
    });
});
