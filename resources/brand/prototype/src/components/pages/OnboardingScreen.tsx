import {
    ChevronLeft,
    Download,
    Flag,
    History,
    Layers,
    RotateCcw,
    Scale,
    Sprout,
    Target,
    Trophy,
    Undo2,
} from 'lucide-react';
import { motion } from 'framer-motion';
import { useState } from 'react';

import { FaceIcon } from '@/components/FaceIcon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useCountUp } from '@/hooks/useCountUp';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { cn } from '@/lib/utils';

import {
    DayCell,
    DayRow,
    IconChoiceCard,
    SessionsDial,
} from './PreferenceControls';

const WHAT_LANDS = [
    {
        icon: History,
        text: 'every run strava already has for you is landing now, with its distance, time and pace.',
    },
    {
        icon: Download,
        text: "the deeper read — splits, hr zones, effort, the run's card — fetches per run, the first time you open it.",
    },
    {
        icon: Scale,
        text: "that history is the point. it's what every run you do from here gets measured against.",
    },
] as const;

const EXPERIENCE_OPTIONS = [
    {
        key: 'new',
        label: 'new to running',
        description: 'first few months, learning the ropes',
        icon: Sprout,
    },
    {
        key: 'returning',
        label: 'getting back into it',
        description: 'coming back after time off',
        icon: RotateCcw,
    },
    {
        key: 'experienced',
        label: 'experienced',
        description: 'know your paces, chasing more',
        icon: Trophy,
    },
] as const;

const SESSIONS_OPTIONS = [2, 3, 4, 5, 6] as const;

const GOAL_OPTIONS = [
    {
        key: 'consistent',
        label: 'stay consistent',
        description: 'show up steady, week after week',
        icon: Target,
    },
    {
        key: 'race',
        label: 'chase a race time',
        description: 'training toward a real finish time',
        icon: Flag,
    },
    {
        key: 'base',
        label: 'build a base',
        description: 'stack easy miles, no pressure yet',
        icon: Layers,
    },
    {
        key: 'return',
        label: 'ease back in',
        description: 'rebuilding gently after a break',
        icon: Undo2,
    },
] as const;

const DAY_OPTIONS = [
    { key: 'mon', label: 'mon' },
    { key: 'tue', label: 'tue' },
    { key: 'wed', label: 'wed' },
    { key: 'thu', label: 'thu' },
    { key: 'fri', label: 'fri' },
    { key: 'sat', label: 'sat' },
    { key: 'sun', label: 'sun' },
] as const;

const TOTAL_PREF_STEPS = 4;

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'half', km: 21.1 },
    { label: 'marathon', km: 42.2 },
] as const;

const STEPS = [
    { key: 'connected', label: 'welcome' },
    { key: 'preferences', label: 'training' },
    { key: 'goal', label: 'race goal' },
] as const;

type Step = (typeof STEPS)[number]['key'];
type PrefKey = 'experience' | 'sessions' | 'goal';

const PREF_QUESTIONS: {
    key: PrefKey;
    question: string;
    options: { key: string; label: string }[];
}[] = [
    {
        key: 'experience',
        question: "how would you describe where you're at?",
        options: EXPERIENCE_OPTIONS.map((o) => ({
            key: o.key,
            label: o.label,
        })),
    },
    {
        key: 'sessions',
        question: 'how many days a week can you realistically show up?',
        options: SESSIONS_OPTIONS.map((n) => ({
            key: String(n),
            label: `${n}x`,
        })),
    },
    {
        key: 'goal',
        question: 'what are you chasing right now?',
        options: GOAL_OPTIONS.map((o) => ({ key: o.key, label: o.label })),
    },
];

function formatPace(totalMinutes: number, km: number): string {
    if (km <= 0 || totalMinutes <= 0) {
        return '—';
    }
    const paceMin = totalMinutes / km;
    const mins = Math.floor(paceMin);
    const secs = Math.round((paceMin - mins) * 60);
    return `${mins}:${String(secs).padStart(2, '0')}/km`;
}

function circleTone(i: number, currentIndex: number): string {
    if (i < currentIndex) {
        return 'border-icon-accent bg-icon-accent text-btn-primary-fg';
    }
    if (i === currentIndex) {
        return 'border-icon-accent text-icon-accent';
    }
    return 'border-border-strong text-foreground';
}

function StepProgress({
    step,
    subIndex,
}: Readonly<{ step: Step; subIndex: number }>) {
    const currentIndex = STEPS.findIndex((s) => s.key === step);

    return (
        <div className="mb-7 flex items-start">
            {STEPS.map((s, i) => (
                <div key={s.key} className="contents">
                    <div className="flex flex-none flex-col items-center gap-1.5">
                        <div
                            className={cn(
                                'flex size-7 flex-none items-center justify-center rounded-full border-2 font-mono text-[11px] leading-none font-extrabold',
                                circleTone(i, currentIndex),
                            )}
                        >
                            {i + 1}
                        </div>
                        <span
                            className={cn(
                                'font-mono text-[8.5px] leading-[1.2] font-extrabold tracking-[.05em] uppercase',
                                i === currentIndex
                                    ? 'text-icon-accent'
                                    : 'text-foreground',
                            )}
                        >
                            {s.label}
                        </span>
                        {s.key === 'preferences' && step === 'preferences' && (
                            <div className="flex gap-1">
                                {Array.from(
                                    { length: TOTAL_PREF_STEPS },
                                    (_, qi) => (
                                        <span
                                            key={qi}
                                            className={cn(
                                                'size-1 rounded-full',
                                                qi <= subIndex
                                                    ? 'bg-icon-accent'
                                                    : 'bg-border-strong',
                                            )}
                                        />
                                    ),
                                )}
                            </div>
                        )}
                    </div>
                    {i < STEPS.length - 1 && (
                        <div
                            className={cn(
                                'mt-3.5 h-0.5 flex-1 rounded-full',
                                i < currentIndex
                                    ? 'bg-icon-accent'
                                    : 'bg-border-strong',
                            )}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function PillOption({
    label,
    active,
    onClick,
}: Readonly<{ label: string; active: boolean; onClick: () => void }>) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'rounded-full border px-3.5 py-2 font-sans text-[12px] leading-[1.2] font-bold',
                active
                    ? 'border-icon-accent bg-horizon/20 text-icon-accent'
                    : 'border-border-strong text-foreground',
            )}
        >
            {label}
        </button>
    );
}

function ConnectedStep({ onContinue }: Readonly<{ onContinue: () => void }>) {
    return (
        <div className="flex flex-col items-center gap-5 py-2 text-center">
            <div className="relative flex items-center justify-center">
                <div
                    aria-hidden
                    className="pointer-events-none absolute size-[240px] rounded-full blur-[34px]"
                    style={{
                        background:
                            'radial-gradient(circle, color-mix(in oklab, var(--horizon) 55%, transparent) 0%, color-mix(in oklab, var(--horizon) 24%, transparent) 42%, transparent 70%)',
                    }}
                />
                <FaceIcon
                    size={72}
                    ring="var(--horizon)"
                    fill="var(--card)"
                    feature="var(--foreground)"
                />
            </div>
            <h1 className="m-0 font-serif text-[24px] leading-[1.15] font-semibold text-foreground italic">
                you&apos;re connected,
                <br />
                <em className="text-icon-accent">nuki.</em>
            </h1>

            <Card className="w-full gap-4 rounded-[14px] border border-border-strong p-4 text-left shadow-e1 ring-0">
                {WHAT_LANDS.map((item) => (
                    <div key={item.text} className="flex items-start gap-3">
                        <item.icon
                            className="mt-0.5 size-[18px] flex-none text-foreground"
                            aria-hidden
                        />
                        <span className="text-[12.5px] leading-[1.5] text-foreground">
                            {item.text}
                        </span>
                    </div>
                ))}
            </Card>

            <Button
                onClick={onContinue}
                className="h-auto w-full gap-1.5 rounded-full bg-btn-primary-bg py-3 text-sm font-bold text-btn-primary-fg hover:bg-btn-primary-bg"
            >
                continue
            </Button>
        </div>
    );
}

function labelFor(key: PrefKey, value: string | undefined): string {
    if (!value) {
        return '';
    }
    return (
        PREF_QUESTIONS.find((q) => q.key === key)?.options.find(
            (o) => o.key === value,
        )?.label ?? ''
    );
}

function DaysSubStep({
    sessionsTarget,
    onFinish,
}: Readonly<{ sessionsTarget: number; onFinish: () => void }>) {
    const [selectedDays, setSelectedDays] = useState<string[]>([]);
    const atTarget = selectedDays.length === sessionsTarget;

    const toggleDay = (key: string) => {
        setSelectedDays((prev) => {
            if (prev.includes(key)) {
                return prev.filter((d) => d !== key);
            }
            if (prev.length >= sessionsTarget) {
                return prev;
            }
            return [...prev, key];
        });
    };

    return (
        <div>
            <p className="m-0 font-serif text-[22px] leading-[1.25] font-semibold text-foreground italic">
                which days do you usually run?
            </p>
            <p className="mt-2 mb-5 text-[12px] leading-[1.5] text-foreground">
                pick {sessionsTarget} — {selectedDays.length} of{' '}
                {sessionsTarget} selected.
            </p>

            <DayRow>
                {DAY_OPTIONS.map((d) => {
                    const active = selectedDays.includes(d.key);
                    const disabled =
                        !active && selectedDays.length >= sessionsTarget;
                    return (
                        <motion.div
                            key={d.key}
                            initial="hidden"
                            animate="visible"
                            variants={fadeInUp}
                        >
                            <DayCell
                                label={d.label}
                                active={active}
                                disabled={disabled}
                                onClick={() => toggleDay(d.key)}
                            />
                        </motion.div>
                    );
                })}
            </DayRow>

            {atTarget && (
                <div className="mt-6 rounded-[12px] bg-muted p-2.5">
                    <p className="m-0 mb-3 font-serif text-[16px] leading-[1.3] font-semibold text-foreground italic">
                        which one&apos;s your long run?
                    </p>
                    <DayRow>
                        {DAY_OPTIONS.map((d) =>
                            selectedDays.includes(d.key) ? (
                                <motion.div
                                    key={d.key}
                                    initial="hidden"
                                    animate="visible"
                                    variants={fadeInUp}
                                >
                                    <DayCell
                                        label={d.label}
                                        active
                                        flagCandidate
                                        onClick={onFinish}
                                    />
                                </motion.div>
                            ) : (
                                <div key={d.key} className="w-8" aria-hidden />
                            ),
                        )}
                    </DayRow>
                </div>
            )}
        </div>
    );
}

function PreferencesStep({
    subIndex,
    onSubIndexChange,
    onContinue,
}: Readonly<{
    subIndex: number;
    onSubIndexChange: (next: number) => void;
    onContinue: (summary: string) => void;
}>) {
    const [answers, setAnswers] = useState<Partial<Record<PrefKey, string>>>(
        {},
    );
    const isDaysStep = subIndex === PREF_QUESTIONS.length;
    const current = isDaysStep ? null : PREF_QUESTIONS[subIndex];

    const choose = (optionKey: string) => {
        setAnswers((prev) => ({ ...prev, [current!.key]: optionKey }));
        onSubIndexChange(subIndex + 1);
    };

    const finishDays = () => {
        const summary = [
            labelFor('experience', answers.experience),
            `${labelFor('sessions', answers.sessions)} a week`,
            labelFor('goal', answers.goal),
        ].join(' · ');
        onContinue(summary);
    };

    return (
        <div>
            <div className="mb-5 h-8">
                {subIndex > 0 && (
                    <button
                        type="button"
                        onClick={() => onSubIndexChange(subIndex - 1)}
                        aria-label="Back"
                        className="flex size-8 flex-none items-center justify-center rounded-full bg-muted text-foreground shadow-e1"
                    >
                        <ChevronLeft className="size-[18px]" aria-hidden />
                    </button>
                )}
            </div>

            {isDaysStep ? (
                <DaysSubStep
                    sessionsTarget={Number(answers.sessions ?? 0)}
                    onFinish={finishDays}
                />
            ) : (
                <>
                    <p className="m-0 font-serif text-[22px] leading-[1.25] font-semibold text-foreground italic">
                        {current!.question}
                    </p>
                    <p className="mt-2 mb-5 text-[12px] leading-[1.5] text-foreground">
                        you can change this anytime in settings.
                    </p>

                    {current!.key === 'sessions' ? (
                        <motion.div
                            initial="hidden"
                            animate="visible"
                            variants={fadeInUp}
                        >
                            <SessionsDial
                                options={SESSIONS_OPTIONS}
                                value={Number(answers.sessions ?? 0)}
                                onChange={(n) => choose(String(n))}
                            />
                        </motion.div>
                    ) : (
                        <motion.div
                            variants={staggerContainer}
                            initial="hidden"
                            animate="visible"
                            className="flex flex-col gap-2"
                        >
                            {(current!.key === 'experience'
                                ? EXPERIENCE_OPTIONS
                                : GOAL_OPTIONS
                            ).map((o) => (
                                <motion.div key={o.key} variants={fadeInUp}>
                                    <IconChoiceCard
                                        icon={o.icon}
                                        label={o.label}
                                        description={o.description}
                                        active={answers[current!.key] === o.key}
                                        onClick={() => choose(o.key)}
                                    />
                                </motion.div>
                            ))}
                        </motion.div>
                    )}
                </>
            )}
        </div>
    );
}

function GoalStep({
    prefsSummary,
    onFinish,
}: Readonly<{ prefsSummary: string; onFinish: () => void }>) {
    const [distanceKm, setDistanceKm] = useState(10);
    const [hours, setHours] = useState(0);
    const [minutes, setMinutes] = useState(50);
    const pace = formatPace(hours * 60 + minutes, distanceKm);

    // Decorative only — clamps the pace between a relaxed easy pace and a
    // fast pace to fill the ring, not a fitness assessment of the number.
    const EASY_PACE_SEC = 7.5 * 60;
    const FAST_PACE_SEC = 3.5 * 60;
    const paceSecPerKm =
        distanceKm > 0 ? (hours * 3600 + minutes * 60) / distanceKm : 0;
    const ringPct =
        paceSecPerKm > 0
            ? Math.min(
                  1,
                  Math.max(
                      0,
                      (EASY_PACE_SEC - paceSecPerKm) /
                          (EASY_PACE_SEC - FAST_PACE_SEC),
                  ),
              )
            : 0;
    const tweenedRingPct = useCountUp(ringPct);
    const ringRadius = 32;
    const ringCircumference = 2 * Math.PI * ringRadius;

    return (
        <div>
            {prefsSummary && (
                <p className="m-0 mb-3 font-serif text-[13px] leading-[1.4] text-foreground italic">
                    got it — {prefsSummary}.
                </p>
            )}
            <div className="flex items-center gap-2">
                <h1 className="m-0 font-serif text-[24px] leading-[1.15] font-semibold text-foreground italic">
                    got a race
                    <br />
                    <em className="text-icon-accent">in mind?</em>
                </h1>
                <span className="mt-1 inline-flex items-center rounded-full bg-muted px-2 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                    optional
                </span>
            </div>
            <p className="mt-2.5 mb-4 text-[12.5px] leading-[1.55] text-foreground">
                give temari something to build toward. skip it if you&apos;re
                not sure yet — you can always set one later from plan.
            </p>

            <div className="relative mb-4 flex items-center gap-4 overflow-hidden rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-8 -left-8 size-[140px] rounded-full blur-[28px]"
                    style={{
                        background:
                            'radial-gradient(circle, color-mix(in oklab, var(--horizon) 45%, transparent) 0%, color-mix(in oklab, var(--horizon) 18%, transparent) 45%, transparent 70%)',
                    }}
                />
                <div className="relative flex-none">
                    <svg width={76} height={76} viewBox="0 0 76 76" aria-hidden>
                        <circle
                            cx={38}
                            cy={38}
                            r={ringRadius}
                            fill="none"
                            strokeWidth={6}
                            className="stroke-border-strong"
                        />
                        <circle
                            cx={38}
                            cy={38}
                            r={ringRadius}
                            fill="none"
                            strokeWidth={6}
                            strokeLinecap="round"
                            strokeDasharray={`${ringCircumference * tweenedRingPct} ${ringCircumference}`}
                            transform="rotate(-90 38 38)"
                            className="stroke-icon-accent"
                        />
                    </svg>
                    <div className="absolute inset-0 flex items-center justify-center">
                        <FaceIcon
                            size={26}
                            ring="var(--horizon)"
                            fill="var(--card)"
                            feature="var(--foreground)"
                        />
                    </div>
                </div>
                <div className="relative min-w-0 flex-1">
                    <div className="font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                        required pace
                    </div>
                    <div className="mt-1 font-serif text-[32px] leading-[1] font-bold text-icon-accent">
                        {pace}
                    </div>
                </div>
            </div>

            <Card className="gap-4 rounded-[14px] border border-border-strong p-4 shadow-e1 ring-0">
                <label className="block font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    name (optional)
                    <input
                        type="text"
                        placeholder="jakarta half 2026"
                        className="mt-1.25 block w-full rounded-[10px] border border-border-strong bg-muted px-2.5 py-2.25 font-sans text-[13px] font-semibold text-foreground normal-case placeholder:text-foreground"
                    />
                </label>
                <label className="block font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    race day
                    <input
                        type="date"
                        className="mt-1.25 block w-full rounded-[10px] border border-border-strong bg-muted px-2.5 py-2.25 font-mono text-[13px] font-bold text-foreground"
                    />
                </label>
                <div>
                    <span className="font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                        distance
                    </span>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {DISTANCE_PRESETS.map((p) => (
                            <PillOption
                                key={p.label}
                                label={p.label}
                                active={distanceKm === p.km}
                                onClick={() => setDistanceKm(p.km)}
                            />
                        ))}
                    </div>
                </div>
                <div>
                    <span className="font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                        goal time
                    </span>
                    <div className="mt-1.5 flex items-center gap-1.5">
                        <input
                            type="number"
                            value={hours}
                            onChange={(e) => setHours(Number(e.target.value))}
                            aria-label="hours"
                            className="w-14 rounded-[10px] border border-border-strong bg-muted px-2 py-2 text-center font-mono text-[13px] font-bold text-foreground"
                        />
                        <span className="text-[11px] text-foreground">hr</span>
                        <input
                            type="number"
                            value={minutes}
                            onChange={(e) => setMinutes(Number(e.target.value))}
                            aria-label="minutes"
                            className="w-14 rounded-[10px] border border-border-strong bg-muted px-2 py-2 text-center font-mono text-[13px] font-bold text-foreground"
                        />
                        <span className="text-[11px] text-foreground">min</span>
                    </div>
                </div>
            </Card>

            <div className="mt-4 flex flex-wrap gap-2">
                <Button
                    onClick={onFinish}
                    className="h-auto flex-1 gap-1.5 rounded-full bg-btn-primary-bg py-3 text-sm font-bold text-btn-primary-fg hover:bg-btn-primary-bg"
                >
                    set my goal &amp; finish
                </Button>
                <Button
                    onClick={onFinish}
                    variant="ghost"
                    className="h-auto flex-1 gap-1.5 rounded-full bg-muted py-3 text-sm font-bold text-foreground hover:bg-muted"
                >
                    skip for now
                </Button>
            </div>
        </div>
    );
}

export function OnboardingScreen({
    onFinish,
}: Readonly<{ onFinish: () => void }>) {
    const [step, setStep] = useState<Step>('connected');
    const [subIndex, setSubIndex] = useState(0);
    const [prefsSummary, setPrefsSummary] = useState('');

    return (
        <div className="px-4 pt-16 pb-10 @min-[900px]:mx-auto @min-[900px]:max-w-[520px] @min-[900px]:px-6 @min-[900px]:pt-16 @min-[900px]:pb-16">
            <StepProgress step={step} subIndex={subIndex} />
            {step === 'connected' && (
                <ConnectedStep onContinue={() => setStep('preferences')} />
            )}
            {step === 'preferences' && (
                <PreferencesStep
                    subIndex={subIndex}
                    onSubIndexChange={setSubIndex}
                    onContinue={(summary) => {
                        setPrefsSummary(summary);
                        setStep('goal');
                    }}
                />
            )}
            {step === 'goal' && (
                <GoalStep prefsSummary={prefsSummary} onFinish={onFinish} />
            )}
        </div>
    );
}
