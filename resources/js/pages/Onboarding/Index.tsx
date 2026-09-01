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
import FaceIcon from '@/components/temari/FaceIcon';
import Chip from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';
import LegacyCard from '@/components/ui/LegacyCard';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import { useCountUp } from '@/hooks/useCountUp';
import { bareLayout } from '@/layouts/BareShell';
import { cn } from '@/lib/cn';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatPace } from '@/lib/pace';
import { earliestRaceDate, goalTimeError } from '@/lib/raceGoal';
import { inputVariants, outlineChipVariants } from '@/lib/variants';

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

const FACE_GLOW =
    'radial-gradient(circle, color-mix(in oklab, var(--color-horizon) 55%, transparent) 0%, color-mix(in oklab, var(--color-horizon) 24%, transparent) 42%, transparent 70%)';
const PACE_GLOW =
    'radial-gradient(circle, color-mix(in oklab, var(--color-horizon) 45%, transparent) 0%, color-mix(in oklab, var(--color-horizon) 18%, transparent) 45%, transparent 70%)';

const FIELD_LABEL = 'text-label-micro text-text-2';

type Step = OnboardingStep;

/** The three answered preferences, joined the way the prototype's recap line
 *  joins them. Empty when every question was skipped. */
function preferencesSummary(
    experienceLevel: ExperienceLevel | null,
    sessionsPerWeek: number | null,
    goalType: GoalType | null,
): string {
    const parts: string[] = [];
    const experience = EXPERIENCE_OPTIONS.find(
        (option) => option.value === experienceLevel,
    );
    if (experience) {
        parts.push(experience.label);
    }
    if (sessionsPerWeek !== null) {
        parts.push(`${sessionsPerWeek}x a week`);
    }
    const goal = GOAL_OPTIONS.find((option) => option.value === goalType);
    if (goal) {
        parts.push(goal.label);
    }

    return parts.join(' · ');
}

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

    const prefsSummary = preferencesSummary(
        experienceLevel,
        sessionsPerWeek,
        goalType,
    );
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
            <PageContainer className="pt-16 pb-10 min-[900px]:max-w-[520px] min-[900px]:pb-16">
                <StepProgress step={step} subIndex={subIndex} />

                {step === 'connected' ? (
                    <motion.div
                        key="connected"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                        className="flex flex-col items-center gap-5 py-2 text-center"
                    >
                        <div className="relative flex items-center justify-center">
                            <div
                                aria-hidden
                                className="pointer-events-none absolute size-60 rounded-full blur-[34px]"
                                style={{ background: FACE_GLOW }}
                            />
                            <FaceIcon size={72} />
                        </div>
                        <PageHero
                            size="quote-lg"
                            italic
                            className="text-center"
                        >
                            you&rsquo;re connected, <br />
                            <em className="text-icon-accent">{firstName}.</em>
                        </PageHero>

                        <LegacyCard className="flex w-full flex-col gap-4 text-left">
                            {WHAT_LANDS.map((item) => (
                                <div
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
                                    <span className="font-sans text-xs leading-relaxed text-text-2">
                                        {item.text}
                                    </span>
                                </div>
                            ))}
                        </LegacyCard>

                        <PillButton
                            tone="horizon"
                            className="w-full justify-center"
                            onClick={() => setStep('preferences')}
                        >
                            Continue
                        </PillButton>
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
                                <ChoiceList>
                                    {EXPERIENCE_OPTIONS.map((option) => (
                                        <motion.div
                                            key={option.value}
                                            variants={fadeInUp}
                                        >
                                            <IconChoiceCard
                                                icon={option.icon}
                                                label={option.label}
                                                description={option.description}
                                                active={
                                                    experienceLevel ===
                                                    option.value
                                                }
                                                onClick={() =>
                                                    chooseExperience(
                                                        option.value,
                                                    )
                                                }
                                            />
                                        </motion.div>
                                    ))}
                                </ChoiceList>
                                <SkipQuestionLink
                                    onClick={() => setSubIndex(1)}
                                />
                            </PreferenceQuestion>
                        )}

                        {subIndex === 1 && (
                            <PreferenceQuestion heading="How many days a week can you realistically show up?">
                                <motion.div
                                    variants={fadeInUp}
                                    initial="hidden"
                                    animate="visible"
                                >
                                    <SessionsDial
                                        options={SESSIONS_OPTIONS}
                                        value={sessionsPerWeek}
                                        onChange={chooseSessions}
                                    />
                                </motion.div>
                                <SkipQuestionLink
                                    onClick={() => setSubIndex(2)}
                                />
                            </PreferenceQuestion>
                        )}

                        {subIndex === 2 && (
                            <PreferenceQuestion heading="What are you chasing right now?">
                                <ChoiceList>
                                    {GOAL_OPTIONS.map((option) => (
                                        <motion.div
                                            key={option.value}
                                            variants={fadeInUp}
                                        >
                                            <IconChoiceCard
                                                icon={option.icon}
                                                label={option.label}
                                                description={option.description}
                                                active={
                                                    goalType === option.value
                                                }
                                                onClick={() =>
                                                    chooseGoalType(option.value)
                                                }
                                            />
                                        </motion.div>
                                    ))}
                                </ChoiceList>
                                <SkipQuestionLink
                                    onClick={() =>
                                        advancePastGoalQuestion(sessionsPerWeek)
                                    }
                                />
                            </PreferenceQuestion>
                        )}

                        {subIndex === 3 && sessionsPerWeek !== null && (
                            <div>
                                <h2 className="font-serif text-quote-lg text-foreground italic">
                                    Which days do you usually run?
                                </h2>
                                <p className="mt-2 mb-5 text-xs leading-relaxed text-text-2">
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
                                    <div className="mt-6 rounded-md bg-muted p-2.5">
                                        <p className="mb-3 font-serif text-quote-sm text-foreground italic">
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
                        {prefsSummary !== '' && (
                            <p className="mb-3 font-serif text-sm leading-relaxed text-text-2 italic">
                                Got it: {prefsSummary}.
                            </p>
                        )}
                        <div className="flex items-center gap-2">
                            <PageHero size="quote-lg" italic>
                                got a race <br />
                                <em className="text-icon-accent">in mind?</em>
                            </PageHero>
                            <Chip className="mt-1 self-start">Optional</Chip>
                        </div>
                        <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                            Give Temari something to build toward. Skip it if
                            you&rsquo;re not sure yet, you can always set one
                            later from Plan.
                        </p>

                        <div className="relative mt-6 mb-4 flex items-center gap-4 overflow-hidden rounded-md border border-border-strong bg-card p-4 shadow-e1">
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -top-8 -left-8 size-35 rounded-full blur-[28px]"
                                style={{ background: PACE_GLOW }}
                            />
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
                                    <FaceIcon size={26} />
                                </div>
                            </div>
                            <div className="relative min-w-0 flex-1">
                                <span className="text-label-micro text-text-3">
                                    Required pace
                                </span>
                                <div className="mt-1 text-stat text-icon-accent">
                                    {pace}
                                </div>
                            </div>
                        </div>

                        <form onSubmit={submitGoal}>
                            <LegacyCard className="flex flex-col gap-4">
                                <div>
                                    <label
                                        htmlFor="onboarding_race_name"
                                        className={FIELD_LABEL}
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
                                        className={cn(
                                            inputVariants(),
                                            'mt-1.5',
                                        )}
                                    />
                                    <FieldError message={errors.name} />
                                </div>
                                <div>
                                    <label
                                        htmlFor="onboarding_race_date"
                                        className={FIELD_LABEL}
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
                                        className={cn(
                                            inputVariants(),
                                            'mt-1.5',
                                        )}
                                    />
                                    <FieldError message={errors.race_date} />
                                </div>

                                <div>
                                    <span className={FIELD_LABEL}>
                                        Distance
                                    </span>
                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                                        {DISTANCE_PRESETS.map((preset) => (
                                            <button
                                                key={preset.label}
                                                type="button"
                                                onClick={() =>
                                                    setDistanceKm(preset.km)
                                                }
                                                className={outlineChipVariants({
                                                    selected:
                                                        distanceKm ===
                                                        preset.km,
                                                })}
                                            >
                                                {preset.label}
                                            </button>
                                        ))}
                                    </div>
                                    <FieldError message={errors.distance_m} />
                                </div>

                                <div>
                                    <span className={FIELD_LABEL}>
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
                                                setMinutes(
                                                    Number(e.target.value),
                                                )
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
                                        message={
                                            goalTimeIssue ??
                                            errors.goal_time_sec
                                        }
                                    />
                                </div>
                            </LegacyCard>

                            <div className="mt-4 flex flex-wrap gap-2">
                                <PillButton
                                    type="submit"
                                    tone="horizon"
                                    disabled={processing || !canSubmitGoal}
                                    className="flex-1 justify-center"
                                >
                                    {processing
                                        ? 'Saving…'
                                        : 'Set my goal & finish'}
                                </PillButton>
                                <PillButton
                                    type="button"
                                    tone="ghost"
                                    disabled={processing}
                                    onClick={skip}
                                    className="flex-1 justify-center"
                                >
                                    Skip for now
                                </PillButton>
                            </div>
                        </form>
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
            <h2 className="font-serif text-quote-lg text-foreground italic">
                {heading}
            </h2>
            <p className="mt-2 mb-5 text-xs leading-relaxed text-text-2">
                You can change this anytime in settings.
            </p>
            {children}
        </div>
    );
}

/** The prototype's motion-staggered option list: children land in sequence. */
function ChoiceList({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <motion.div
            variants={staggerContainer}
            initial="hidden"
            animate="visible"
            className="flex flex-col gap-2"
        >
            {children}
        </motion.div>
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

OnboardingIndex.layout = bareLayout;
