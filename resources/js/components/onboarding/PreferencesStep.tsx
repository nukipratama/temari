import type { Dispatch, ReactNode } from 'react';

import type {
    OnboardingAction,
    OnboardingDraft,
} from '@/hooks/useOnboardingDraft';

import { DayCell, DayRow } from '@/components/onboarding/DayPicker';
import IconChoiceCard from '@/components/onboarding/IconChoiceCard';
import SessionsDial from '@/components/onboarding/SessionsDial';
import PillButton from '@/components/ui/PillButton';
import { revealDelay } from '@/lib/styles';

import BackButton from './BackButton';
import { EXPERIENCE_OPTIONS, GOAL_OPTIONS } from './options';

const EXPERIENCE_NOTE =
    'this only sets your first weeks. once your runs sync, they set the plan.';

const SESSIONS_OPTIONS = [2, 3, 4, 5, 6] as const;

const DAY_OPTIONS = [
    { offset: 0, label: 'Mon' },
    { offset: 1, label: 'Tue' },
    { offset: 2, label: 'Wed' },
    { offset: 3, label: 'Thu' },
    { offset: 4, label: 'Fri' },
    { offset: 5, label: 'Sat' },
    { offset: 6, label: 'Sun' },
] as const;

export default function PreferencesStep({
    draft,
    dispatch,
}: Readonly<{
    draft: OnboardingDraft;
    dispatch: Dispatch<OnboardingAction>;
}>) {
    const { subIndex, experienceLevel, sessionsPerWeek, goalType } = draft;
    const { runDays, longRunDay } = draft;

    return (
        <div key={`preferences-${subIndex}`} className="reveal">
            <div className="mb-5 flex h-11 items-center justify-between">
                <BackButton
                    onClick={() => dispatch({ type: 'goBackFromPreferences' })}
                />
                <PillButton
                    tone="ghost"
                    onClick={() => dispatch({ type: 'skipPreferences' })}
                >
                    skip for now
                </PillButton>
            </div>

            {subIndex === 0 && (
                <PreferenceQuestion
                    heading={<>how would you describe where you&rsquo;re at?</>}
                    note={EXPERIENCE_NOTE}
                >
                    <ChoiceList>
                        {EXPERIENCE_OPTIONS.map((option, index) => (
                            <div
                                key={option.value}
                                className="reveal"
                                style={revealDelay(index)}
                            >
                                <IconChoiceCard
                                    icon={option.icon}
                                    label={option.label}
                                    description={option.description}
                                    active={experienceLevel === option.value}
                                    onClick={() =>
                                        dispatch({
                                            type: 'chooseExperience',
                                            value: option.value,
                                        })
                                    }
                                />
                            </div>
                        ))}
                    </ChoiceList>
                    <SkipQuestionLink
                        onClick={() =>
                            dispatch({ type: 'skipQuestion', subIndex: 1 })
                        }
                    />
                </PreferenceQuestion>
            )}

            {subIndex === 1 && (
                <PreferenceQuestion heading="how many days a week can you realistically show up?">
                    <div className="reveal">
                        <SessionsDial
                            options={SESSIONS_OPTIONS}
                            value={sessionsPerWeek}
                            onChange={(value) =>
                                dispatch({ type: 'chooseSessions', value })
                            }
                        />
                    </div>
                    <SkipQuestionLink
                        onClick={() =>
                            dispatch({ type: 'skipQuestion', subIndex: 2 })
                        }
                    />
                </PreferenceQuestion>
            )}

            {subIndex === 2 && (
                <PreferenceQuestion heading="what are you chasing right now?">
                    <ChoiceList>
                        {GOAL_OPTIONS.map((option, index) => (
                            <div
                                key={option.value}
                                className="reveal"
                                style={revealDelay(index)}
                            >
                                <IconChoiceCard
                                    icon={option.icon}
                                    label={option.label}
                                    description={option.description}
                                    active={goalType === option.value}
                                    onClick={() =>
                                        dispatch({
                                            type: 'chooseGoalType',
                                            value: option.value,
                                        })
                                    }
                                />
                            </div>
                        ))}
                    </ChoiceList>
                    <SkipQuestionLink
                        onClick={() => dispatch({ type: 'skipGoalQuestion' })}
                    />
                </PreferenceQuestion>
            )}

            {subIndex === 3 && sessionsPerWeek !== null && (
                <div>
                    <h2 className="font-serif text-quote-lg text-foreground italic">
                        which days do you usually run?
                    </h2>
                    <p className="mt-2 mb-5 text-xs leading-relaxed text-text-2">
                        pick {sessionsPerWeek} &middot; {runDays.length} of{' '}
                        {sessionsPerWeek} selected.
                    </p>

                    <DayRow
                        items={DAY_OPTIONS.map((day) => {
                            const active = runDays.includes(day.offset);
                            const disabled =
                                !active && runDays.length >= sessionsPerWeek;
                            return (
                                <DayCell
                                    key={day.offset}
                                    label={day.label}
                                    active={active}
                                    disabled={disabled}
                                    onClick={() =>
                                        dispatch({
                                            type: 'toggleRunDay',
                                            offset: day.offset,
                                        })
                                    }
                                />
                            );
                        })}
                    />

                    {runDays.length === sessionsPerWeek && (
                        <div className="mt-6 rounded-md bg-muted p-2.5">
                            <p className="mb-3 narration">
                                which one&rsquo;s your long run?
                            </p>
                            <DayRow
                                items={DAY_OPTIONS.filter((day) =>
                                    runDays.includes(day.offset),
                                ).map((day) => (
                                    <DayCell
                                        key={day.offset}
                                        label={day.label}
                                        active
                                        longRun={longRunDay === day.offset}
                                        flagCandidate
                                        onClick={() =>
                                            dispatch({
                                                type: 'setLongRunDay',
                                                offset: day.offset,
                                            })
                                        }
                                    />
                                ))}
                            />
                            <PillButton
                                tone="horizon"
                                disabled={longRunDay === null}
                                onClick={() =>
                                    dispatch({ type: 'goTo', step: 'goal' })
                                }
                                className="mt-3 w-full justify-center"
                            >
                                continue
                            </PillButton>
                        </div>
                    )}

                    <SkipQuestionLink
                        onClick={() => dispatch({ type: 'skipDays' })}
                    />
                </div>
            )}
        </div>
    );
}

/** One preferences sub-question: heading, options, then the skip link. */
function PreferenceQuestion({
    heading,
    note,
    children,
}: Readonly<{ heading: ReactNode; note?: string; children: ReactNode }>) {
    return (
        <div>
            <h2 className="font-serif text-quote-lg text-foreground italic">
                {heading}
            </h2>
            <p className="mt-2 mb-5 text-xs leading-relaxed text-text-2">
                {note && `${note} `}you can change this anytime in settings.
            </p>
            {children}
        </div>
    );
}

/** The prototype's staggered option list: children land in sequence, each
 *  carrying its own `revealDelay`. */
function ChoiceList({ children }: Readonly<{ children: ReactNode }>) {
    return <div className="flex flex-col gap-2">{children}</div>;
}

function SkipQuestionLink({ onClick }: Readonly<{ onClick: () => void }>) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="focus-ring pressable mt-4 font-sans text-xs text-text-3 underline-offset-2 hover:text-foreground hover:underline"
        >
            skip this
        </button>
    );
}
