import type { FormDataConvertible } from '@inertiajs/core';

import { Head, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { type FormEvent, useState } from 'react';

import type { ExperienceLevel, GoalType } from '@/types/generated';
import type { SharedProps } from '@/types/inertia';

import Temari from '@/components/temari/Temari';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
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
}> = [
    { value: 'new_to_running', label: 'New to running' },
    { value: 'returning', label: 'Getting back into it' },
    { value: 'experienced', label: 'Experienced' },
];

const SESSIONS_OPTIONS = [2, 3, 4, 5, 6] as const;

const GOAL_OPTIONS: ReadonlyArray<{ value: GoalType; label: string }> = [
    { value: 'consistent', label: 'Stay consistent' },
    { value: 'race', label: 'Chase a race time' },
    { value: 'base', label: 'Build a base' },
    { value: 'return', label: 'Ease back in' },
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

type Step = 'connected' | 'preferences' | 'goal';

export default function OnboardingIndex() {
    const page = usePage<SharedProps>().props;
    const firstName = page.auth.user?.first_name ?? '';
    const errors = page.errors ?? {};
    const [step, setStep] = useState<Step>('connected');
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

    const daysIncomplete =
        runDays.length > 0 && runDays.length !== sessionsPerWeek;
    const canContinuePreferences = !daysIncomplete;

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
                {step === 'connected' ? (
                    <motion.div
                        key="connected"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                        className="flex flex-col items-center gap-5 py-2 text-center sm:py-10"
                    >
                        <Temari pose="glow" size={112} animate />
                        <PageHero
                            size="lg"
                            eyebrow="Step 1 of 3 · Welcome"
                            className="text-center"
                        >
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
                        key="preferences"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                    >
                        <PageHero size="lg" eyebrow="Step 2 of 3 · Optional">
                            Tell us how you train.
                        </PageHero>
                        <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                            Every field here is optional. Skip it and
                            Temari&rsquo;ll start from your recent Strava
                            history instead.
                        </p>

                        <Card className="mt-6 flex flex-col gap-6 px-6 py-6">
                            <div>
                                <span className="text-label-micro text-text-3">
                                    Experience
                                </span>
                                <div className="mt-1.5 flex flex-wrap gap-2">
                                    {EXPERIENCE_OPTIONS.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() =>
                                                setExperienceLevel(option.value)
                                            }
                                            className={cn(
                                                'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                                experienceLevel === option.value
                                                    ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                    : 'border-border text-text-3 hover:border-horizon/60 hover:text-foreground',
                                            )}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <span className="text-label-micro text-text-3">
                                    Sessions per week
                                </span>
                                <div className="mt-1.5 flex flex-wrap gap-2">
                                    {SESSIONS_OPTIONS.map((n) => (
                                        <button
                                            key={n}
                                            type="button"
                                            onClick={() => {
                                                setSessionsPerWeek(n);
                                                setRunDays([]);
                                                setLongRunDay(null);
                                            }}
                                            className={cn(
                                                'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                                sessionsPerWeek === n
                                                    ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                    : 'border-border text-text-3 hover:border-horizon/60 hover:text-foreground',
                                            )}
                                        >
                                            {n}x
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <span className="text-label-micro text-text-3">
                                    What are you chasing right now?
                                </span>
                                <div className="mt-1.5 flex flex-wrap gap-2">
                                    {GOAL_OPTIONS.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() =>
                                                setGoalType(option.value)
                                            }
                                            className={cn(
                                                'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                                goalType === option.value
                                                    ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                    : 'border-border text-text-3 hover:border-horizon/60 hover:text-foreground',
                                            )}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <span className="text-label-micro text-text-3">
                                    Which days do you usually run?
                                </span>
                                <p className="mt-1 text-xs text-text-3">
                                    {sessionsPerWeek === null
                                        ? 'Pick your sessions per week first.'
                                        : `Pick ${sessionsPerWeek} — ${runDays.length} of ${sessionsPerWeek} selected.`}
                                </p>
                                <div className="mt-1.5 flex flex-wrap gap-2">
                                    {DAY_OPTIONS.map((day) => {
                                        const active = runDays.includes(
                                            day.offset,
                                        );
                                        const disabled =
                                            sessionsPerWeek === null ||
                                            (!active &&
                                                runDays.length >=
                                                    sessionsPerWeek);
                                        return (
                                            <button
                                                key={day.offset}
                                                type="button"
                                                disabled={disabled}
                                                onClick={() =>
                                                    toggleRunDay(day.offset)
                                                }
                                                className={cn(
                                                    'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition disabled:cursor-not-allowed disabled:opacity-40',
                                                    active
                                                        ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                        : 'border-border text-text-3 hover:border-horizon/60 hover:text-foreground',
                                                )}
                                            >
                                                {day.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {sessionsPerWeek !== null &&
                                runDays.length === sessionsPerWeek && (
                                    <div>
                                        <span className="text-label-micro text-text-3">
                                            Which one&rsquo;s your long run?
                                        </span>
                                        <div className="mt-1.5 flex flex-wrap gap-2">
                                            {DAY_OPTIONS.filter((day) =>
                                                runDays.includes(day.offset),
                                            ).map((day) => (
                                                <button
                                                    key={day.offset}
                                                    type="button"
                                                    onClick={() =>
                                                        setLongRunDay(
                                                            day.offset,
                                                        )
                                                    }
                                                    className={cn(
                                                        'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                                        longRunDay ===
                                                            day.offset
                                                            ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                            : 'border-border text-text-3 hover:border-horizon/60 hover:text-foreground',
                                                    )}
                                                >
                                                    {day.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    onClick={() => setStep('goal')}
                                    disabled={!canContinuePreferences}
                                >
                                    Continue
                                </Button>
                                <PillButton
                                    type="button"
                                    tone="ghost"
                                    onClick={skipPreferences}
                                >
                                    Skip for now
                                </PillButton>
                            </div>
                        </Card>
                    </motion.div>
                ) : (
                    <motion.div
                        key="goal"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                    >
                        <PageHero size="lg" eyebrow="Step 3 of 3 · Optional">
                            Got a race in mind?
                        </PageHero>
                        <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                            Give Temari something to build toward. Skip it if
                            you&rsquo;re not sure yet, you can always set one
                            later from Plan.
                        </p>

                        <Card className="mt-6 px-6 py-6">
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
