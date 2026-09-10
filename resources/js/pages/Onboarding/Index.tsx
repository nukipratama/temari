import type { FormDataConvertible } from '@inertiajs/core';

import { Head, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    Download,
    Flag,
    Layers,
    RotateCcw,
    RotateCcwClock,
    Scale,
    Sprout,
    Target,
    Trophy,
    Undo2,
} from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

import type { ExperienceLevel, GoalType } from '@/types/generated';
import type { SharedProps } from '@/types/inertia';

import { DayCell, DayRow } from '@/components/onboarding/DayPicker';
import IconChoiceCard from '@/components/onboarding/IconChoiceCard';
import SessionsDial from '@/components/onboarding/SessionsDial';
import StepProgress, {
    type OnboardingStep,
} from '@/components/onboarding/StepProgress';
import PushNotificationToggle from '@/components/PushNotificationToggle';
import FaceIcon from '@/components/temari/FaceIcon';
import Chip from '@/components/ui/Chip';
import DateField from '@/components/ui/DateField';
import { Icon, IconComponent, TelegramIcon } from '@/components/ui/Icon';
import LegacyCard from '@/components/ui/LegacyCard';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import SettingsRow from '@/components/ui/SettingsRow';
import { useCountUp } from '@/hooks/useCountUp';
import { bareLayout } from '@/layouts/BareShell';
import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';
import { earliestRaceDate, goalTimeError } from '@/lib/raceGoal';
import { revealDelay } from '@/lib/styles';
import { inputVariants, outlineChipVariants } from '@/lib/variants';

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'half', km: 21.1 },
    { label: 'marathon', km: 42.2 },
] as const;

const WHAT_LANDS: ReadonlyArray<{ icon: IconComponent; text: string }> = [
    {
        icon: RotateCcwClock,
        text: 'every run Strava already has for you is landing now, with its distance, time and pace.',
    },
    {
        icon: Download,
        text: "the deeper read (splits, HR zones, effort, and the run's card) is fetched per run, the first time you open it.",
    },
    {
        icon: Scale,
        text: 'that history is the point. it is what every run you do from here gets measured against.',
    },
];

const EXPERIENCE_OPTIONS: ReadonlyArray<{
    value: ExperienceLevel;
    label: string;
    description: string;
    icon: IconComponent;
}> = [
    {
        value: 'new_to_running',
        label: 'new to running',
        description: 'first few months, learning the ropes.',
        icon: Sprout,
    },
    {
        value: 'returning',
        label: 'getting back into it',
        description: 'coming back after time off.',
        icon: RotateCcw,
    },
    {
        value: 'experienced',
        label: 'experienced',
        description: 'know your paces, chasing more.',
        icon: Trophy,
    },
];

const SESSIONS_OPTIONS = [2, 3, 4, 5, 6] as const;

const GOAL_OPTIONS: ReadonlyArray<{
    value: GoalType;
    label: string;
    description: string;
    icon: IconComponent;
}> = [
    {
        value: 'consistent',
        label: 'stay consistent',
        description: 'show up steady, week after week.',
        icon: Target,
    },
    {
        value: 'race',
        label: 'chase a race time',
        description: 'training toward a real finish time.',
        icon: Flag,
    },
    {
        value: 'base',
        label: 'build a base',
        description: 'stack easy miles, no pressure yet.',
        icon: Layers,
    },
    {
        value: 'return',
        label: 'ease back in',
        description: 'rebuilding gently after a break.',
        icon: Undo2,
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

export default function OnboardingIndex({
    telegramConnectUrl = null,
}: Readonly<{ telegramConnectUrl?: string | null }>) {
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
    const [goalPayload, setGoalPayload] = useState<Record<
        string,
        FormDataConvertible
    > | null>(null);

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

    // Nothing is written until the last step, so the goal answer waits here
    // rather than posting: the nudge step behind it is the one that submits.
    const submitGoal = (event: FormEvent) => {
        event.preventDefault();
        setGoalPayload({
            race_date: raceDate,
            distance_m: Math.round(distanceKm * 1000),
            goal_time_sec: goalTimeSec,
            name: name.trim() === '' ? null : name.trim(),
        });
        setStep('nudge');
    };

    const skipGoal = () => {
        setGoalPayload(null);
        setStep('nudge');
    };

    const finish = () => {
        router.post(
            '/onboarding',
            { ...preferencesPayload(), ...(goalPayload ?? {}) },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                // The only server-validated fields live a step behind, and
                // that step is the only one that renders their errors.
                onError: (submitErrors) => {
                    if (
                        submitErrors.race_date ||
                        submitErrors.distance_m ||
                        submitErrors.goal_time_sec ||
                        submitErrors.name
                    ) {
                        setStep('goal');
                    }
                },
            },
        );
    };

    return (
        <>
            <Head title="Welcome" />
            <PageContainer className="pt-16 pb-10 min-[900px]:max-w-[520px] min-[900px]:pb-16 min-[1280px]:max-w-[520px]">
                <StepProgress step={step} subIndex={subIndex} />

                {step === 'connected' ? (
                    <div
                        key="connected"
                        className="reveal flex flex-col items-center gap-5 py-2 text-center"
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
                                    key={item.text}
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
                            continue
                        </PillButton>
                    </div>
                ) : step === 'preferences' ? (
                    <div key={`preferences-${subIndex}`} className="reveal">
                        <div className="mb-5 flex h-11 items-center justify-between">
                            {subIndex > 0 ? (
                                <button
                                    type="button"
                                    onClick={goBackSubStep}
                                    aria-label="Back"
                                    className="focus-ring flex size-11 flex-none items-center justify-center rounded-full bg-muted text-foreground shadow-e1"
                                >
                                    <Icon
                                        icon={ChevronLeft}
                                        width={18}
                                        height={18}
                                        aria-hidden
                                    />
                                </button>
                            ) : (
                                <span />
                            )}
                            <PillButton tone="ghost" onClick={skipPreferences}>
                                skip for now
                            </PillButton>
                        </div>

                        {subIndex === 0 && (
                            <PreferenceQuestion
                                heading={
                                    <>
                                        how would you describe where
                                        you&rsquo;re at?
                                    </>
                                }
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
                                        </div>
                                    ))}
                                </ChoiceList>
                                <SkipQuestionLink
                                    onClick={() => setSubIndex(1)}
                                />
                            </PreferenceQuestion>
                        )}

                        {subIndex === 1 && (
                            <PreferenceQuestion heading="how many days a week can you realistically show up?">
                                <div className="reveal">
                                    <SessionsDial
                                        options={SESSIONS_OPTIONS}
                                        value={sessionsPerWeek}
                                        onChange={chooseSessions}
                                    />
                                </div>
                                <SkipQuestionLink
                                    onClick={() => setSubIndex(2)}
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
                                                active={
                                                    goalType === option.value
                                                }
                                                onClick={() =>
                                                    chooseGoalType(option.value)
                                                }
                                            />
                                        </div>
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
                                    which days do you usually run?
                                </h2>
                                <p className="mt-2 mb-5 text-xs leading-relaxed text-text-2">
                                    pick {sessionsPerWeek} &middot;{' '}
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
                    </div>
                ) : step === 'goal' ? (
                    <div key="goal" className="reveal">
                        {prefsSummary !== '' && (
                            <p className="mb-3 narration">
                                Got it: {prefsSummary}.
                            </p>
                        )}
                        <div className="flex items-center gap-2">
                            <PageHero size="quote-lg" italic>
                                got a race <br />
                                <em className="text-icon-accent">in mind?</em>
                            </PageHero>
                            <Chip className="mt-1 self-start">optional</Chip>
                        </div>
                        <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                            Give temari something to build toward. Skip it if
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
                                    required pace
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
                                        name (optional)
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
                                        race day
                                    </label>
                                    <DateField
                                        id="onboarding_race_date"
                                        value={raceDate}
                                        min={earliestRaceDate()}
                                        onChange={setRaceDate}
                                        className="mt-1.5"
                                    />
                                    <FieldError message={errors.race_date} />
                                </div>

                                <div>
                                    <span className={FIELD_LABEL}>
                                        distance
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
                                        goal time
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
                                    disabled={!canSubmitGoal}
                                    className="flex-1 justify-center"
                                >
                                    set my goal
                                </PillButton>
                                <PillButton
                                    type="button"
                                    tone="ghost"
                                    onClick={skipGoal}
                                    className="flex-1 justify-center"
                                >
                                    skip for now
                                </PillButton>
                            </div>
                        </form>
                    </div>
                ) : (
                    <div key="nudge" className="reveal">
                        <div className="mb-5 flex h-11 items-center justify-between">
                            <button
                                type="button"
                                onClick={() => setStep('goal')}
                                aria-label="Back"
                                className="focus-ring flex size-11 flex-none items-center justify-center rounded-full bg-muted text-foreground shadow-e1"
                            >
                                <Icon
                                    icon={ChevronLeft}
                                    width={18}
                                    height={18}
                                    aria-hidden
                                />
                            </button>
                            <PillButton
                                tone="ghost"
                                disabled={processing}
                                onClick={finish}
                            >
                                skip for now
                            </PillButton>
                        </div>

                        <div className="flex items-center gap-2">
                            <PageHero size="quote-lg" italic>
                                want temari to <br />
                                <em className="text-icon-accent">nudge you?</em>
                            </PageHero>
                            <Chip className="mt-1 self-start">optional</Chip>
                        </div>
                        <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                            She only pings you when there is something to say:
                            the read on a run once it lands, your week and month
                            wrapped up, a heads-up when a streak is about to
                            slip, and a word if Strava quietly stops syncing.
                        </p>

                        <LegacyCard className="mt-6 flex flex-col">
                            {telegramConnectUrl !== null && (
                                <SettingsRow
                                    icon={TelegramIcon}
                                    label="Telegram"
                                    description="connect it so temari can keep you posted."
                                    externalHref={telegramConnectUrl}
                                    openInNewTab
                                />
                            )}
                            <PushNotificationToggle />
                        </LegacyCard>

                        <p className="mt-3 font-sans text-xs leading-relaxed text-text-3">
                            you can wire either of these up later from settings.
                        </p>

                        <PillButton
                            tone="horizon"
                            disabled={processing}
                            onClick={finish}
                            className="mt-4 w-full justify-center"
                        >
                            {processing ? 'saving…' : 'finish'}
                        </PillButton>
                    </div>
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
                you can change this anytime in settings.
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
            className="focus-ring mt-4 font-sans text-xs text-text-3 underline-offset-2 hover:text-foreground hover:underline"
        >
            skip this
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
