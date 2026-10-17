import type { FormDataConvertible } from '@inertiajs/core';

import { useReducer } from 'react';

import type { OnboardingStep } from '@/components/onboarding/StepProgress';
import type { ExperienceLevel, GoalType } from '@/types/generated';

export type GoalPayload = Record<string, FormDataConvertible>;

export interface OnboardingDraft {
    step: OnboardingStep;
    subIndex: number;
    experienceLevel: ExperienceLevel | null;
    sessionsPerWeek: number | null;
    goalType: GoalType | null;
    runDays: number[];
    longRunDay: number | null;
    raceDate: string;
    distanceKm: number;
    hours: number;
    minutes: number;
    name: string;
    goalPayload: GoalPayload | null;
}

export type OnboardingAction =
    | { type: 'goTo'; step: OnboardingStep }
    | { type: 'goBackFromPreferences' }
    | { type: 'skipQuestion'; subIndex: number }
    | { type: 'skipGoalQuestion' }
    | { type: 'skipPreferences' }
    | { type: 'skipDays' }
    | { type: 'chooseExperience'; value: ExperienceLevel }
    | { type: 'chooseSessions'; value: number }
    | { type: 'chooseGoalType'; value: GoalType }
    | { type: 'toggleRunDay'; offset: number }
    | { type: 'setLongRunDay'; offset: number }
    | { type: 'setRaceDate'; value: string }
    | { type: 'setDistanceKm'; value: number }
    | { type: 'setGoalTime'; hours: number; minutes: number }
    | { type: 'setName'; value: string }
    | { type: 'submitGoal' }
    | { type: 'skipGoal' };

export const initialOnboardingDraft: OnboardingDraft = {
    step: 'connected',
    subIndex: 0,
    experienceLevel: null,
    sessionsPerWeek: null,
    goalType: null,
    runDays: [],
    longRunDay: null,
    raceDate: '',
    distanceKm: 10,
    hours: 0,
    minutes: 50,
    name: '',
    goalPayload: null,
};

export function goalTimeSec(draft: OnboardingDraft): number {
    return draft.hours * 3_600 + draft.minutes * 60;
}

/** Days only has anything to configure once a sessions target exists. */
function pastGoalQuestion(draft: OnboardingDraft): OnboardingDraft {
    return draft.sessionsPerWeek !== null
        ? { ...draft, subIndex: 3 }
        : { ...draft, step: 'goal' };
}

export function onboardingReducer(
    draft: OnboardingDraft,
    action: OnboardingAction,
): OnboardingDraft {
    switch (action.type) {
        case 'goTo':
            return { ...draft, step: action.step };
        case 'goBackFromPreferences':
            return draft.subIndex > 0
                ? { ...draft, subIndex: draft.subIndex - 1 }
                : { ...draft, step: 'connected' };
        case 'skipQuestion':
            return { ...draft, subIndex: action.subIndex };
        case 'skipGoalQuestion':
            return pastGoalQuestion(draft);
        case 'skipPreferences':
            return {
                ...draft,
                experienceLevel: null,
                sessionsPerWeek: null,
                goalType: null,
                runDays: [],
                longRunDay: null,
                step: 'goal',
            };
        case 'skipDays':
            return { ...draft, runDays: [], longRunDay: null, step: 'goal' };
        case 'chooseExperience':
            return { ...draft, experienceLevel: action.value, subIndex: 1 };
        case 'chooseSessions':
            return {
                ...draft,
                sessionsPerWeek: action.value,
                runDays: [],
                longRunDay: null,
                subIndex: 2,
            };
        case 'chooseGoalType':
            return pastGoalQuestion({ ...draft, goalType: action.value });
        case 'toggleRunDay':
            return {
                ...draft,
                longRunDay: null,
                runDays: draft.runDays.includes(action.offset)
                    ? draft.runDays.filter((d) => d !== action.offset)
                    : [...draft.runDays, action.offset],
            };
        case 'setLongRunDay':
            return { ...draft, longRunDay: action.offset };
        case 'setRaceDate':
            return { ...draft, raceDate: action.value };
        case 'setDistanceKm':
            return { ...draft, distanceKm: action.value };
        case 'setGoalTime':
            return { ...draft, hours: action.hours, minutes: action.minutes };
        case 'setName':
            return { ...draft, name: action.value };
        // Nothing is written until the last step, so the goal answer waits
        // here rather than posting: the nudge step behind it submits.
        case 'submitGoal':
            return {
                ...draft,
                step: 'nudge',
                goalPayload: {
                    race_date: draft.raceDate,
                    distance_m: Math.round(draft.distanceKm * 1000),
                    goal_time_sec: goalTimeSec(draft),
                    name: draft.name.trim() === '' ? null : draft.name.trim(),
                },
            };
        case 'skipGoal':
            return { ...draft, step: 'nudge', goalPayload: null };
    }
}

/** The body `POST /onboarding` takes: every answered preference plus the goal. */
export function onboardingPayload(draft: OnboardingDraft): GoalPayload {
    const payload: GoalPayload = {};
    if (draft.experienceLevel !== null)
        payload.experience_level = draft.experienceLevel;
    if (draft.sessionsPerWeek !== null)
        payload.sessions_per_week = draft.sessionsPerWeek;
    if (draft.goalType !== null) payload.goal_type = draft.goalType;
    if (draft.runDays.length > 0) payload.run_days = draft.runDays;
    if (draft.longRunDay !== null) payload.long_run_day = draft.longRunDay;

    return { ...payload, ...(draft.goalPayload ?? {}) };
}

export function useOnboardingDraft() {
    return useReducer(onboardingReducer, initialOnboardingDraft);
}
