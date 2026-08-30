import type { FormDataConvertible } from '@inertiajs/core';

import { Head, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { type FormEvent, type ReactNode, useState } from 'react';

import type { ExperienceLevel, GoalType } from '@/types/generated';
import type { SharedProps } from '@/types/inertia';

import { DayCell, DayRow } from '@/components/onboarding/DayPicker';
import IconChoiceCard from '@/components/onboarding/IconChoiceCard';
import SessionsDial from '@/components/onboarding/SessionsDial';
import StepProgress, {
    type OnboardingStep,
} from '@/components/onboarding/StepProgress';
import Temari from '@/components/temari/Temari';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formatPace } from '@/lib/pace';
import { earliestRaceDate, goalTimeError } from '@/lib/raceGoal';

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'Half', km: 21.1 },
    { label: 'Marathon', km: 42.2 },
] as const;

const WHAT_LANDS: ReadonlyArray<{ icon: string; text: string }> = [
    {
        icon: 'mdi:history',
        text: 'Every run Strava already has for you is landing now, with its distance, time and pace.',
    },
    {
        icon: 'mdi:progress-download',
        text: "The deeper read (splits, HR zones, effort, and the run's card) is fetched per run, the first time you open it.",
    },
    {
        icon: 'mdi:scale-balance',
        text: 'That history is the point. It is what every run you do from here gets measured against.',
    },
];

const EXPERIENCE_OPTIONS: ReadonlyArray<{
    value: ExperienceLevel;
    label: string;
    description: string;
    icon: string;
}> = [
    {
        value: 'new_to_running',
        label: 'New to running',
        description: 'First few months, learning the ropes.',
        icon: 'mdi:sprout',
    },
    {
        value: 'returning',
        label: 'Getting back into it',
        description: 'Coming back after time off.',
        icon: 'mdi:restore',
    },
    {
        value: 'experienced',
        label: 'Experienced',
        description: 'Know your paces, chasing more.',
        icon: 'mdi:trophy',
    },
];

const SESSIONS_OPTIONS = [2, 3, 4, 5, 6] as const;

const GOAL_OPTIONS: ReadonlyArray<{
    value: GoalType;
    label: string;
    description: string;
    icon: string;
}> = [
    {
        value: 'consistent',
        label: 'Stay consistent',
        description: 'Show up steady, week after week.',
        icon: 'mdi:target',
    },
    {
        value: 'race',
        label: 'Chase a race time',
        description: 'Training toward a real finish time.',
        icon: 'mdi:flag-checkered',
    },
    {
        value: 'base',
        label: 'Build a base',
        description: 'Stack easy miles, no pressure yet.',
        icon: 'mdi:layers-outline',
    },
    {
        value: 'return',
        label: 'Ease back in',
        description: 'Rebuilding gently after a break.',
        icon: 'mdi:undo-variant',
    },
];

const DAY_OPTIONS = [
    { offset: 0, label: 'Mon' },
    { offset: 1, label: 'Tue' },
    { offset: 2, label: 'Wed' },
    { offset: 3, label: 'Thu' },
    { offset: 4, label: 'Fri' },
    { offset: 5, label: 'Sat' },
    { offset: 6, label: 'Sun' },
] as const;

// Decorative only — clamps the pace between a relaxed easy pace and a fast
// pace to fill the ring, not a fitness assessment of the number.
const EASY_PACE_SEC_PER_KM = 7.5 * 60;
const FAST_PACE_SEC_PER_KM = 3.5 * 60;
const RING_RADIUS = 32;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;

type Step = OnboardingStep;

export default function OnboardingIndex() {
    const page = usePage<SharedProps>().props;
    const firstName = page.auth.user?.first_name ?? '';
    const errors = page.errors ?? {};
    const [step, setStep] = useState<Step>('connected');
    const [subIndex, setSubIndex] = useState(0);
    const [raceDate, setRaceDate] = useState('');
    const [distanceKm, setDistanceKm] = useState<number>(10);
    const [hours, setHours] = useState(0);
    const [minutes, setMinutes] = useState(50);
    const [name, setName] = useState('');
    const [processing, setProcessing] = useState(false);

    const [experienceLevel, setExperienceLevel] =
        useState<ExperienceLevel | null>(null);
    const [sessionsPerWeek, setSessionsPerWeek] = useState<number | null>(null);
    const [goalType, setGoalType] = useState<GoalType | null>(null);
    const [runDays, setRunDays] = useState<number[]>([]);
    const [longRunDay, setLongRunDay] = useState<number | null>(null);

    const goalTimeSec = hours * 3_600 + minutes * 60;
    const goalTimeIssue = goalTimeError(goalTimeSec);
    const canSubmitGoal = raceDate !== '' && goalTimeIssue === null;

    const paceSecPerKm = distanceKm > 0 ? goalTimeSec / distanceKm : 0;
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

    const toggleRunDay = (offset: number) => {
        setLongRunDay(null);
        setRunDays((prev) =>
            prev.includes(offset)
                ? prev.filter((d) => d !== offset)
                : [...prev, offset],
        );
    };

    const skipPreferences = () => {
        setExperienceLevel(null);
        setSessionsPerWeek(null);
        setGoalType(null);
        setRunDays([]);
        setLongRunDay(null);
        setStep('goal');
    };

    const goBackSubStep = () => setSubIndex((i) => Math.max(0, i - 1));

    const chooseExperience = (value: ExperienceLevel) => {
        setExperienceLevel(value);
        setSubIndex(1);
    };

    const chooseSessions = (n: number) => {
        setSessionsPerWeek(n);
        setRunDays([]);
        setLongRunDay(null);
        setSubIndex(2);
    };

    /** Days only has anything to configure once a sessions target exists. */
    const advancePastGoalQuestion = (targetSessions: number | null) => {
        if (targetSessions !== null) {
            setSubIndex(3);
        } else {
            setStep('goal');
        }
    };

    const chooseGoalType = (value: GoalType) => {
        setGoalType(value);
        advancePastGoalQuestion(sessionsPerWeek);
    };

    const chooseLongRunDay = (offset: number) => {
        setLongRunDay(offset);
        setStep('goal');
    };

    const skipDaysQuestion = () => {
        setRunDays([]);
        setLongRunDay(null);
        setStep('goal');
    };

    const preferencesPayload = (): Record<string, FormDataConvertible> => {
        const payload: Record<string, FormDataConvertible> = {};
        if (experienceLevel !== null)
            payload.experience_level = experienceLevel;
        if (sessionsPerWeek !== null)
            payload.sessions_per_week = sessionsPerWeek;
        if (goalType !== null) payload.goal_type = goalType;
        if (runDays.length > 0) payload.run_days = runDays;
        if (longRunDay !== null) payload.long_run_day = longRunDay;
        return payload;
    };

    const finish = (payload: Record<string, FormDataConvertible>) => {
        router.post(
            '/onboarding',
            { ...preferencesPayload(), ...payload },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const submitGoal = (event: FormEvent) => {
        event.preventDefault();
        finish({
            race_date: raceDate,
            distance_m: Math.round(distanceKm * 1000),
            goal_time_sec: goalTimeSec,
            name: name.trim() === '' ? null : name.trim(),
        });
    };

    const skip = () => finish({});

    return (
        <>
            <Head title="Welcome" />
            <PageContainer className="max-w-2xl">
                <StepProgress step={step} subIndex={subIndex} />

                {step === 'connected' ? (
                    <motion.div
                        key="connected"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                        className="flex flex-col items-center gap-5 py-2 text-center sm:py-10"
                    >
                        <Temari pose="glow" size={112} animate />
                        <PageHero size="lg" className="text-center">
                            You&rsquo;re connected, {firstName}.
                        </PageHero>

                        <Card className="w-full px-6 py-6 text-left">
                            <ul className="flex flex-col gap-4">
                                {WHAT_LANDS.map((item) => (
                                    <li
                                        key={item.icon}
                                        className="flex items-start gap-3"
                                    >
                                        <Icon
                                            icon={item.icon}
                                            width={18}
                                            height={18}
                                            aria-hidden
                                            className="mt-0.5 shrink-0 text-text-3"
                                        />
                                        <span className="font-sans text-sm leading-relaxed text-text-2">
                                            {item.text}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        <Button onClick={() => setStep('preferences')}>
                            Continue
                        </Button>
                    </motion.div>
                ) : step === 'preferences' ? (
                    <motion.div
                        key={`preferences-${subIndex}`}
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                    >
                        <div className="mb-5 flex h-8 items-center justify-between">
                            {subIndex > 0 ? (
                                <button
                                    type="button"
                                    onClick={goBackSubStep}
                                    aria-label="Back"
                                    className="focus-ring flex size-8 flex-none items-center justify-center rounded-full bg-muted text-foreground shadow-e1"
                                >
                                    <Icon
                                        icon="mdi:chevron-left"
                                        width={18}
                                        height={18}
                                        aria-hidden
                                    />
                                </button>
                            ) : (
                                <span />
                            )}
                            <PillButton tone="ghost" onClick={skipPreferences}>
                                Skip for now
                            </PillButton>
                        </div>

                        {subIndex === 0 && (
                            <PreferenceQuestion
                                heading={
                                    <>
                                        How would you describe where
                                        you&rsquo;re at?
                                    </>
                                }
                            >
                                <div className="flex flex-col gap-2">
                                    {EXPERIENCE_OPTIONS.map((option) => (
                                        <IconChoiceCard
                                            key={option.value}
                                            icon={option.icon}
                                            label={option.label}
                                            description={option.description}
                                            active={
                                                experienceLevel === option.value
                                            }
                                            onClick={() =>
                                                chooseExperience(option.value)
                                            }
                                        />
                                    ))}
                                </div>
                                <SkipQuestionLink
                                    onClick={() => setSubIndex(1)}
                                />
                            </PreferenceQuestion>
                        )}

                        {subIndex === 1 && (
                            <PreferenceQuestion heading="How many days a week can you realistically show up?">
                                <SessionsDial
                                    options={SESSIONS_OPTIONS}
                                    value={sessionsPerWeek}
                                    onChange={chooseSessions}
                                />
                                <SkipQuestionLink
                                    onClick={() => setSubIndex(2)}
                                />
                            </PreferenceQuestion>
                        )}

                        {subIndex === 2 && (
                            <PreferenceQuestion heading="What are you chasing right now?">
                                <div className="flex flex-col gap-2">
                                    {GOAL_OPTIONS.map((option) => (
                                        <IconChoiceCard
                                            key={option.value}
                                            icon={option.icon}
                                            label={option.label}
                                            description={option.description}
                                            active={goalType === option.value}
                                            onClick={() =>
                                                chooseGoalType(option.value)
                                            }
                                        />
                                    ))}
                                </div>
                                <SkipQuestionLink
                                    onClick={() =>
                                        advancePastGoalQuestion(sessionsPerWeek)
                                    }
                                />
                            </PreferenceQuestion>
                        )}

                        {subIndex === 3 && sessionsPerWeek !== null && (
                            <div>
                                <h2 className="font-serif text-headline-xs text-foreground italic">
                                    Which days do you usually run?
                                </h2>
                                <p className="mt-2 mb-5 text-sm leading-relaxed text-text-2">
                                    Pick {sessionsPerWeek} &middot;{' '}
                                    {runDays.length} of {sessionsPerWeek}{' '}
                                    selected.
                                </p>

                                <DayRow
                                    items={DAY_OPTIONS.map((day) => {
                                        const active = runDays.includes(
                                            day.offset,
                                        );
                                        const disabled =
                                            !active &&
                                            runDays.length >= sessionsPerWeek;
                                        return (
                                            <DayCell
                                                key={day.offset}
                                                label={day.label}
                                                active={active}
                                                disabled={disabled}
                                                onClick={() =>
                                                    toggleRunDay(day.offset)
                                                }
                                            />
                                        );
                                    })}
                                />

                                {runDays.length === sessionsPerWeek && (
                                    <div className="mt-6 rounded-xl bg-muted p-2.5">
                                        <p className="m-0 mb-3 font-serif text-sm text-foreground italic">
                                            Which one&rsquo;s your long run?
                                        </p>
                                        <DayRow
                                            items={DAY_OPTIONS.filter((day) =>
                                                runDays.includes(day.offset),
                                            ).map((day) => (
                                                <DayCell
                                                    key={day.offset}
                                                    label={day.label}
                                                    active
                                                    flagCandidate
                                                    onClick={() =>
                                                        chooseLongRunDay(
                                                            day.offset,
                                                        )
                                                    }
                                                />
                                            ))}
                                        />
                                    </div>
                                )}

                                <SkipQuestionLink onClick={skipDaysQuestion} />
                            </div>
                        )}
                    </motion.div>
                ) : (
                    <motion.div
                        key="goal"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                    >
                        <PageHero size="lg" eyebrow="Optional">
                            Got a race in mind?
                        </PageHero>
                        <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                            Give Temari something to build toward. Skip it if
                            you&rsquo;re not sure yet, you can always set one
                            later from Plan.
                        </p>

                        <div className="relative mt-6 mb-4 flex items-center gap-4 overflow-hidden rounded-xl border border-border-strong bg-card p-4 shadow-e1">
                            <div className="relative flex-none">
                                <svg
                                    width={76}
                                    height={76}
                                    viewBox="0 0 76 76"
                                    aria-hidden
                                >
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
                                    <Temari pose="glow" size={26} />
                                </div>
                            </div>
                            <div className="min-w-0 flex-1">
                                <span className="text-label-micro text-text-3">
                                    Required pace
                                </span>
                                <div className="mt-1 font-serif text-headline-xs font-bold text-icon-accent">
                                    {pace}
                                </div>
                            </div>
                        </div>

                        <Card className="px-6 py-6">
                            <form
                                onSubmit={submitGoal}
                                className="grid grid-cols-1 gap-5 sm:grid-cols-2"
                            >
                                <div>
                                    <label
                                        htmlFor="onboarding_race_name"
                                        className="text-label-micro text-text-3"
                                    >
                                        Name (optional)
                                    </label>
                                    <input
                                        id="onboarding_race_name"
                                        type="text"
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        maxLength={120}
                                        placeholder="Jakarta Half 2026"
                                        className="mt-1.5 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-ring"
                                    />
                                    <FieldError message={errors.name} />
                                </div>
                                <div>
                                    <label
                                        htmlFor="onboarding_race_date"
                                        className="text-label-micro text-text-3"
                                    >
                                        Race day
                                    </label>
                                    <input
                                        id="onboarding_race_date"
                                        type="date"
                                        value={raceDate}
                                        min={earliestRaceDate()}
                                        onChange={(e) =>
                                            setRaceDate(e.target.value)
                                        }
                                        className="mt-1.5 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-ring"
                                    />
                                    <FieldError message={errors.race_date} />
                                </div>

                                <div className="sm:col-span-2">
                                    <span className="text-label-micro text-text-3">
                                        Distance
                                    </span>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                        {DISTANCE_PRESETS.map((preset) => (
                                            <button
                                                key={preset.label}
                                                type="button"
                                                onClick={() =>
                                                    setDistanceKm(preset.km)
                                                }
                                                className={cn(
                                                    'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                                    distanceKm === preset.km
                                                        ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                        : 'border-border text-text-3 hover:border-horizon/60 hover:text-foreground',
                                                )}
                                            >
                                                {preset.label}
                                            </button>
                                        ))}
                                    </div>
                                    <FieldError message={errors.distance_m} />
                                </div>

                                <div className="sm:col-span-2">
                                    <span className="text-label-micro text-text-3">
                                        Goal time
                                    </span>
                                    <div className="mt-1.5 flex items-center gap-1.5">
                                        <input
                                            type="number"
                                            min={0}
                                            max={71}
                                            value={hours}
                                            onChange={(e) =>
                                                setHours(Number(e.target.value))
                                            }
                                            aria-label="Hours"
                                            className="w-16 rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-foreground focus-ring"
                                        />
                                        <span className="text-sm text-text-3">
                                            hr
                                        </span>
                                        <input
                                            type="number"
                                            min={0}
                                            max={59}
                                            value={minutes}
                                            onChange={(e) =>
                                                setMinutes(
                                                    Number(e.target.value),
                                                )
                                            }
                                            aria-label="Minutes"
                                            className="w-16 rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-foreground focus-ring"
                                        />
                                        <span className="text-sm text-text-3">
                                            min
                                        </span>
                                    </div>
                                    <FieldError
                                        message={
                                            goalTimeIssue ??
                                            errors.goal_time_sec
                                        }
                                    />
                                </div>

                                <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                                    <Button
                                        type="submit"
                                        disabled={processing || !canSubmitGoal}
                                    >
                                        {processing
                                            ? 'Saving…'
                                            : 'Set my goal & finish'}
                                    </Button>
                                    <PillButton
                                        type="button"
                                        tone="ghost"
                                        disabled={processing}
                                        onClick={skip}
                                    >
                                        Skip for now
                                    </PillButton>
                                </div>
                            </form>
                        </Card>
                    </motion.div>
                )}
            </PageContainer>
        </>
    );
}

/** One preferences sub-question: heading, options, then the skip link. */
function PreferenceQuestion({
    heading,
    children,
}: Readonly<{ heading: ReactNode; children: ReactNode }>) {
    return (
        <div>
            <h2 className="font-serif text-headline-xs text-foreground italic">
                {heading}
            </h2>
            <p className="mt-2 mb-5 text-sm leading-relaxed text-text-2">
                You can change this anytime in settings.
            </p>
            {children}
        </div>
    );
}

function SkipQuestionLink({ onClick }: Readonly<{ onClick: () => void }>) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="focus-ring mt-4 font-sans text-xs text-text-3 underline-offset-2 hover:text-foreground hover:underline"
        >
            Skip this
        </button>
    );
}

function FieldError({ message }: Readonly<{ message?: string | null }>) {
    if (!message) {
        return null;
    }

    return (
        <p role="alert" className="mt-1.5 font-sans text-xs text-ember-ink">
            {message}
        </p>
    );
}

OnboardingIndex.layout = appLayout;
