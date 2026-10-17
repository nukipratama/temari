import { type Dispatch, type FormEvent, Suspense } from 'react';

import TemariMascot from '@/components/temari/TemariMascot';
import Chip from '@/components/ui/Chip';
import DateField from '@/components/ui/DateField';
import FieldError from '@/components/ui/FieldError';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import { useCountUp } from '@/hooks/useCountUp';
import {
    goalTimeSec,
    type OnboardingAction,
    type OnboardingDraft,
} from '@/hooks/useOnboardingDraft';
import { cn } from '@/lib/cn';
import { lazyIsland } from '@/lib/lazyIsland';
import { formatPace, useTodayIso } from '@/lib/pace';
import { earliestRaceDate, goalTimeError } from '@/lib/raceGoal';
import { inputVariants } from '@/lib/variants';

import BackButton from './BackButton';
import { preferencesSummary } from './options';

const DistancePresets = lazyIsland(
    () => import('@/components/onboarding/DistancePresets'),
);

// Decorative only — clamps the pace between a relaxed easy pace and a fast
// pace to fill the ring, not a fitness assessment of the number.
const EASY_PACE_SEC_PER_KM = 7.5 * 60;
const FAST_PACE_SEC_PER_KM = 3.5 * 60;
const RING_RADIUS = 32;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;

const FIELD_LABEL = 'text-label-micro text-text-2';

export default function GoalStep({
    draft,
    dispatch,
    errors,
}: Readonly<{
    draft: OnboardingDraft;
    dispatch: Dispatch<OnboardingAction>;
    errors: Record<string, string>;
}>) {
    const today = useTodayIso();
    const { raceDate, distanceKm, hours, minutes, name } = draft;
    const prefsSummary = preferencesSummary(
        draft.experienceLevel,
        draft.sessionsPerWeek,
        draft.goalType,
    );
    const timeSec = goalTimeSec(draft);
    const goalTimeIssue = goalTimeError(timeSec);
    const canSubmitGoal = raceDate !== '' && goalTimeIssue === null;

    const paceSecPerKm = distanceKm > 0 ? timeSec / distanceKm : 0;
    const ringPct =
        paceSecPerKm > 0
            ? Math.min(
                  1,
                  Math.max(
                      0,
                      (EASY_PACE_SEC_PER_KM - paceSecPerKm) /
                          (EASY_PACE_SEC_PER_KM - FAST_PACE_SEC_PER_KM),
                  ),
              )
            : 0;
    const tweenedRingPct = useCountUp(ringPct);
    const pace = paceSecPerKm > 0 ? `${formatPace(paceSecPerKm)}/km` : '—';

    const submitGoal = (event: FormEvent) => {
        event.preventDefault();
        dispatch({ type: 'submitGoal' });
    };

    return (
        <div key="goal" className="reveal">
            <div className="mb-5 flex h-11 items-center">
                <BackButton
                    onClick={() =>
                        dispatch({ type: 'goTo', step: 'preferences' })
                    }
                />
            </div>
            {prefsSummary !== '' && (
                <p className="mb-3 narration">got it: {prefsSummary}.</p>
            )}
            <div className="flex items-center gap-2">
                <PageHero size="quote-lg" italic>
                    got a race <br />
                    <em className="text-icon-accent">in mind?</em>
                </PageHero>
                <Chip className="mt-1 self-start">optional</Chip>
            </div>
            <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                give temari something to build toward. skip it if you&rsquo;re
                not sure yet, you can always set one later from plan.
            </p>

            <div className="mt-6 mb-4 flex items-center gap-4">
                <div className="relative flex-none">
                    <svg width={76} height={76} viewBox="0 0 76 76" aria-hidden>
                        <circle
                            cx={38}
                            cy={38}
                            r={RING_RADIUS}
                            fill="none"
                            strokeWidth={6}
                            className="stroke-border-strong"
                        />
                        <circle
                            cx={38}
                            cy={38}
                            r={RING_RADIUS}
                            fill="none"
                            strokeWidth={6}
                            strokeLinecap="round"
                            strokeDasharray={`${RING_CIRCUMFERENCE * tweenedRingPct} ${RING_CIRCUMFERENCE}`}
                            transform="rotate(-90 38 38)"
                            className="stroke-icon-accent"
                        />
                    </svg>
                    <div className="absolute inset-0 flex items-center justify-center">
                        <TemariMascot size={36} faceOnly />
                    </div>
                </div>
                <div className="min-w-0 flex-1">
                    <span className="text-label-micro text-text-3">
                        required pace
                    </span>
                    <div className="mt-1 text-stat text-icon-accent">
                        {pace}
                    </div>
                </div>
            </div>

            <form onSubmit={submitGoal}>
                <div className="flex flex-col gap-4">
                    <div>
                        <label
                            htmlFor="onboarding_race_name"
                            className={FIELD_LABEL}
                        >
                            name (optional)
                        </label>
                        <input
                            id="onboarding_race_name"
                            type="text"
                            value={name}
                            onChange={(e) =>
                                dispatch({
                                    type: 'setName',
                                    value: e.target.value,
                                })
                            }
                            maxLength={120}
                            placeholder="Jakarta Half 2026"
                            className={cn(inputVariants(), 'mt-1.5')}
                        />
                        <FieldError message={errors.name} />
                    </div>
                    <div>
                        <label
                            htmlFor="onboarding_race_date"
                            className={FIELD_LABEL}
                        >
                            race day
                        </label>
                        <DateField
                            id="onboarding_race_date"
                            value={raceDate}
                            min={earliestRaceDate(today)}
                            onChange={(value) =>
                                dispatch({ type: 'setRaceDate', value })
                            }
                            className="mt-1.5"
                        />
                        <FieldError message={errors.race_date} />
                    </div>

                    <div>
                        <span
                            id="onboarding_distance_label"
                            className={FIELD_LABEL}
                        >
                            distance
                        </span>
                        <Suspense fallback={<div className="mt-1.5 min-h-8" />}>
                            <DistancePresets
                                km={distanceKm}
                                onChange={(value) =>
                                    dispatch({ type: 'setDistanceKm', value })
                                }
                                labelledBy="onboarding_distance_label"
                                className="mt-1.5"
                            />
                        </Suspense>
                        <FieldError message={errors.distance_m} />
                    </div>

                    <div>
                        <span className={FIELD_LABEL}>goal time</span>
                        <div className="mt-1.5 flex items-center gap-1.5">
                            <input
                                type="number"
                                min={0}
                                max={71}
                                value={hours}
                                onChange={(e) =>
                                    dispatch({
                                        type: 'setGoalTime',
                                        hours: Number(e.target.value),
                                        minutes,
                                    })
                                }
                                aria-label="Hours"
                                className={cn(
                                    inputVariants({ size: 'sm' }),
                                    'w-16 text-center',
                                )}
                            />
                            <span className={FIELD_LABEL}>hr</span>
                            <input
                                type="number"
                                min={0}
                                max={59}
                                value={minutes}
                                onChange={(e) =>
                                    dispatch({
                                        type: 'setGoalTime',
                                        hours,
                                        minutes: Number(e.target.value),
                                    })
                                }
                                aria-label="Minutes"
                                className={cn(
                                    inputVariants({ size: 'sm' }),
                                    'w-16 text-center',
                                )}
                            />
                            <span className={FIELD_LABEL}>min</span>
                        </div>
                        <FieldError
                            message={goalTimeIssue ?? errors.goal_time_sec}
                        />
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                    <PillButton
                        type="submit"
                        tone="horizon"
                        disabled={!canSubmitGoal}
                        className="flex-1 justify-center"
                    >
                        set my goal
                    </PillButton>
                    <PillButton
                        type="button"
                        tone="ghost"
                        onClick={() => dispatch({ type: 'skipGoal' })}
                        className="flex-1 justify-center"
                    >
                        skip for now
                    </PillButton>
                </div>
            </form>
        </div>
    );
}
