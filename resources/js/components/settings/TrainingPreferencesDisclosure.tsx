import { router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';

import type { ExperienceLevel, GoalType } from '@/types/generated';

import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

const SAVED_FLASH_MS = 2000;

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

export interface TrainingPreferencesPayload {
    experience_level: ExperienceLevel | null;
    sessions_per_week: number | null;
    goal_type: GoalType | null;
    run_days: number[] | null;
    long_run_day: number | null;
}

function collapsedCopy(prefs: TrainingPreferencesPayload): string {
    if (prefs.sessions_per_week === null && prefs.run_days === null) {
        return "You haven't set any — Temari uses your recent activity instead";
    }
    return `${prefs.sessions_per_week ?? '?'}x a week, your way`;
}

/**
 * Inline training-preference editing, mirroring HrZonesDisclosure's
 * expand/collapse pattern. Every field is independently clearable back to
 * null — an unset field falls back to the backend's own behavior-derived
 * default, not a blank plan.
 */
export default function TrainingPreferencesDisclosure({
    trainingPreferences,
}: Readonly<{ trainingPreferences: TrainingPreferencesPayload }>) {
    const [open, setOpen] = useState(false);

    const [experienceLevel, setExperienceLevel] =
        useState<ExperienceLevel | null>(trainingPreferences.experience_level);
    const [sessionsPerWeek, setSessionsPerWeek] = useState<number | null>(
        trainingPreferences.sessions_per_week,
    );
    const [goalType, setGoalType] = useState<GoalType | null>(
        trainingPreferences.goal_type,
    );
    const [runDays, setRunDays] = useState<number[]>(
        trainingPreferences.run_days ?? [],
    );
    const [longRunDay, setLongRunDay] = useState<number | null>(
        trainingPreferences.long_run_day,
    );

    const isDirty =
        experienceLevel !== trainingPreferences.experience_level ||
        sessionsPerWeek !== trainingPreferences.sessions_per_week ||
        goalType !== trainingPreferences.goal_type ||
        longRunDay !== trainingPreferences.long_run_day ||
        runDays.join(',') !== (trainingPreferences.run_days ?? []).join(',');

    const [processing, setProcessing] = useState(false);
    const [justSaved, setJustSaved] = useState(false);
    const savedFlashTimeoutRef = useRef<number | null>(null);

    useEffect(() => {
        return () => {
            if (savedFlashTimeoutRef.current !== null) {
                window.clearTimeout(savedFlashTimeoutRef.current);
            }
        };
    }, []);

    const toggleRunDay = (offset: number) => {
        setLongRunDay(null);
        setRunDays((prev) =>
            prev.includes(offset)
                ? prev.filter((d) => d !== offset)
                : [...prev, offset],
        );
    };

    const daysIncomplete =
        runDays.length > 0 && runDays.length !== sessionsPerWeek;

    const submit = () => {
        router.patch(
            '/settings/training-preferences',
            {
                experience_level: experienceLevel,
                sessions_per_week: sessionsPerWeek,
                goal_type: goalType,
                run_days: runDays.length > 0 ? runDays : null,
                long_run_day: longRunDay,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setJustSaved(true);
                    if (savedFlashTimeoutRef.current !== null) {
                        window.clearTimeout(savedFlashTimeoutRef.current);
                    }
                    savedFlashTimeoutRef.current = window.setTimeout(
                        () => setJustSaved(false),
                        SAVED_FLASH_MS,
                    );
                },
            },
        );
    };

    return (
        <div className="rounded-4xl border border-border-strong bg-card shadow-e1">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                className="pressable focus-ring flex w-full items-center justify-between gap-3 rounded-4xl p-3.5 text-left transition hover:bg-cream-deep/40"
            >
                <span className="flex items-center gap-3">
                    <Icon
                        icon="mdi:run"
                        width={20}
                        height={20}
                        className="text-text-3"
                        aria-hidden
                    />
                    <span className="flex flex-col">
                        <span className="font-sans text-sm font-semibold text-foreground">
                            Training preferences
                        </span>
                        <span className="font-sans text-[12px] text-text-3">
                            {collapsedCopy(trainingPreferences)}
                        </span>
                    </span>
                </span>
                <Icon
                    icon={open ? 'mdi:chevron-up' : 'mdi:chevron-down'}
                    width={18}
                    height={18}
                    className="shrink-0 text-text-3"
                    aria-hidden
                />
            </button>

            {open && (
                <div className="border-t border-border-strong p-3.5 pt-4">
                    <Card className="px-4 py-3">
                        <SectionLabel size="micro">Experience</SectionLabel>
                        <OptionGroup
                            options={EXPERIENCE_OPTIONS}
                            value={experienceLevel}
                            onChange={setExperienceLevel}
                        />
                    </Card>

                    <Card className="mt-3 px-4 py-3">
                        <SectionLabel size="micro">
                            Sessions per week
                        </SectionLabel>
                        <div className="mt-1.5 flex flex-wrap gap-2">
                            {SESSIONS_OPTIONS.map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => {
                                        setSessionsPerWeek(
                                            sessionsPerWeek === n ? null : n,
                                        );
                                        setRunDays([]);
                                        setLongRunDay(null);
                                    }}
                                    className={cn(
                                        'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                        sessionsPerWeek === n
                                            ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                            : 'border-border-strong text-text-3 hover:border-horizon/60 hover:text-foreground',
                                    )}
                                >
                                    {n}x
                                </button>
                            ))}
                        </div>
                    </Card>

                    <Card className="mt-3 px-4 py-3">
                        <SectionLabel size="micro">Training goal</SectionLabel>
                        <OptionGroup
                            options={GOAL_OPTIONS}
                            value={goalType}
                            onChange={setGoalType}
                        />
                    </Card>

                    <Card className="mt-3 px-4 py-3">
                        <SectionLabel size="micro">Run days</SectionLabel>
                        <p className="mb-2 font-sans text-xs text-text-3">
                            {sessionsPerWeek === null
                                ? 'Pick your sessions per week first.'
                                : `Pick ${sessionsPerWeek} — ${runDays.length} of ${sessionsPerWeek} selected.`}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {DAY_OPTIONS.map((day) => {
                                const active = runDays.includes(day.offset);
                                const disabled =
                                    sessionsPerWeek === null ||
                                    (!active &&
                                        runDays.length >= sessionsPerWeek);
                                return (
                                    <button
                                        key={day.offset}
                                        type="button"
                                        disabled={disabled}
                                        onClick={() => toggleRunDay(day.offset)}
                                        className={cn(
                                            'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition disabled:cursor-not-allowed disabled:opacity-40',
                                            active
                                                ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                : 'border-border-strong text-text-3 hover:border-horizon/60 hover:text-foreground',
                                        )}
                                    >
                                        {day.label}
                                    </button>
                                );
                            })}
                        </div>

                        {sessionsPerWeek !== null &&
                            runDays.length === sessionsPerWeek && (
                                <div className="mt-3">
                                    <SectionLabel size="micro">
                                        Long run day
                                    </SectionLabel>
                                    <div className="mt-1.5 flex flex-wrap gap-2">
                                        {DAY_OPTIONS.filter((day) =>
                                            runDays.includes(day.offset),
                                        ).map((day) => (
                                            <button
                                                key={day.offset}
                                                type="button"
                                                onClick={() =>
                                                    setLongRunDay(day.offset)
                                                }
                                                className={cn(
                                                    'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                                    longRunDay === day.offset
                                                        ? 'border-horizon bg-horizon/10 text-horizon-ink'
                                                        : 'border-border-strong text-text-3 hover:border-horizon/60 hover:text-foreground',
                                                )}
                                            >
                                                {day.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                    </Card>

                    <div className="mt-4 flex items-center gap-3">
                        <PillButton
                            tone="sky"
                            size="sm"
                            onClick={submit}
                            disabled={processing || !isDirty || daysIncomplete}
                        >
                            <Icon
                                icon="mdi:content-save-outline"
                                width={16}
                                height={16}
                                aria-hidden
                            />
                            Save preferences
                        </PillButton>
                        <AnimatePresence>
                            {justSaved && (
                                <motion.span
                                    variants={fadeInUp}
                                    initial="hidden"
                                    animate="visible"
                                    exit="hidden"
                                    role="status"
                                    className="inline-flex items-center gap-1.5 text-sm font-semibold text-leaf-ink"
                                >
                                    <Icon
                                        icon="mdi:check-circle-outline"
                                        width={16}
                                        height={16}
                                        aria-hidden
                                    />
                                    Saved
                                </motion.span>
                            )}
                        </AnimatePresence>
                    </div>
                </div>
            )}
        </div>
    );
}

function OptionGroup<T extends string>({
    options,
    value,
    onChange,
}: Readonly<{
    options: ReadonlyArray<{ value: T; label: string }>;
    value: T | null;
    onChange: (value: T | null) => void;
}>) {
    return (
        <div className="mt-1.5 flex flex-wrap gap-2">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    onClick={() =>
                        onChange(value === option.value ? null : option.value)
                    }
                    className={cn(
                        'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                        value === option.value
                            ? 'border-horizon bg-horizon/10 text-horizon-ink'
                            : 'border-border-strong text-text-3 hover:border-horizon/60 hover:text-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
