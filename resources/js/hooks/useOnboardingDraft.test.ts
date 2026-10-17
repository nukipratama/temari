import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    initialOnboardingDraft,
    onboardingPayload,
    onboardingReducer,
    useOnboardingDraft,
    type OnboardingAction,
    type OnboardingDraft,
} from './useOnboardingDraft';

function run(...actions: OnboardingAction[]): OnboardingDraft {
    return actions.reduce(onboardingReducer, initialOnboardingDraft);
}

describe('onboardingReducer', () => {
    it('starts on the connected step with a 10K in 50 minutes', () => {
        expect(initialOnboardingDraft).toMatchObject({
            step: 'connected',
            distanceKm: 10,
            hours: 0,
            minutes: 50,
        });
    });

    it('answering experience and sessions advances the sub-question and clears days', () => {
        const draft = run(
            { type: 'chooseExperience', value: 'returning' },
            { type: 'chooseSessions', value: 3 },
        );

        expect(draft).toMatchObject({
            experienceLevel: 'returning',
            sessionsPerWeek: 3,
            subIndex: 2,
            runDays: [],
            longRunDay: null,
        });
    });

    it('goes to the days question after a goal type only once sessions are set', () => {
        expect(run({ type: 'chooseGoalType', value: 'base' })).toMatchObject({
            step: 'goal',
            goalType: 'base',
        });
        expect(
            run(
                { type: 'chooseSessions', value: 4 },
                { type: 'chooseGoalType', value: 'base' },
            ),
        ).toMatchObject({ step: 'connected', subIndex: 3 });
        expect(run({ type: 'skipGoalQuestion' }).step).toBe('goal');
    });

    it('toggling a run day resets the long run day', () => {
        const draft = run(
            { type: 'toggleRunDay', offset: 1 },
            { type: 'setLongRunDay', offset: 1 },
            { type: 'toggleRunDay', offset: 3 },
        );

        expect(draft.runDays).toEqual([1, 3]);
        expect(draft.longRunDay).toBeNull();
        expect(
            onboardingReducer(draft, { type: 'toggleRunDay', offset: 1 })
                .runDays,
        ).toEqual([3]);
    });

    it('goes back a sub-question, then to the connected step', () => {
        const mid = run({ type: 'skipQuestion', subIndex: 2 });

        const back = onboardingReducer(mid, { type: 'goBackFromPreferences' });
        expect(back.subIndex).toBe(1);
        expect(run({ type: 'goBackFromPreferences' }).step).toBe('connected');
    });

    it('skipping preferences discards partial picks', () => {
        const draft = run(
            { type: 'chooseExperience', value: 'experienced' },
            { type: 'skipPreferences' },
        );

        expect(draft.experienceLevel).toBeNull();
        expect(draft.step).toBe('goal');
        expect(onboardingPayload(draft)).toEqual({});
    });

    it('skipping days keeps the sessions answer', () => {
        const draft = run(
            { type: 'chooseSessions', value: 3 },
            { type: 'toggleRunDay', offset: 0 },
            { type: 'skipDays' },
        );

        expect(draft).toMatchObject({
            sessionsPerWeek: 3,
            runDays: [],
            step: 'goal',
        });
    });

    it('submitting the goal parks a payload in meters and seconds', () => {
        const draft = run(
            { type: 'setRaceDate', value: '2026-12-25' },
            { type: 'setDistanceKm', value: 21.1 },
            { type: 'setGoalTime', hours: 1, minutes: 45 },
            { type: 'setName', value: '  Half  ' },
            { type: 'submitGoal' },
        );

        expect(draft.step).toBe('nudge');
        expect(onboardingPayload(draft)).toEqual({
            race_date: '2026-12-25',
            distance_m: 21_100,
            goal_time_sec: 6_300,
            name: 'Half',
        });
    });

    it('a blank name posts null and skipping the goal drops the payload', () => {
        const submitted = run(
            { type: 'setRaceDate', value: '2026-12-25' },
            { type: 'submitGoal' },
        );
        expect(onboardingPayload(submitted).name).toBeNull();

        const skipped = onboardingReducer(submitted, { type: 'skipGoal' });
        expect(skipped.goalPayload).toBeNull();
        expect(onboardingPayload(skipped)).toEqual({});
    });

    it('merges answered preferences into the payload', () => {
        const draft = run(
            { type: 'chooseExperience', value: 'returning' },
            { type: 'chooseSessions', value: 2 },
            { type: 'toggleRunDay', offset: 0 },
            { type: 'toggleRunDay', offset: 5 },
            { type: 'setLongRunDay', offset: 5 },
        );

        expect(onboardingPayload(draft)).toEqual({
            experience_level: 'returning',
            sessions_per_week: 2,
            run_days: [0, 5],
            long_run_day: 5,
        });
    });
});

describe('useOnboardingDraft', () => {
    it('exposes the draft and a dispatcher', () => {
        const { result } = renderHook(() => useOnboardingDraft());

        act(() => result.current[1]({ type: 'goTo', step: 'goal' }));

        expect(result.current[0].step).toBe('goal');
    });
});
