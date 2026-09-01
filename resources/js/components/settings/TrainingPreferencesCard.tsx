import { router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { type ReactNode, useEffect, useRef, useState } from 'react';

import type { ExperienceLevel, GoalType } from '@/types/generated';

import { DayCell, DayRow } from '@/components/onboarding/DayPicker';
import IconChoiceCard from '@/components/onboarding/IconChoiceCard';
import SessionsDial from '@/components/onboarding/SessionsDial';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import { fadeInUp } from '@/lib/motion';
import { cardVariants } from '@/lib/variants';

const SAVED_FLASH_MS = 2000;

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

export interface TrainingPreferencesPayload {
    experience_level: ExperienceLevel | null;
    sessions_per_week: number | null;
    goal_type: GoalType | null;
    run_days: number[] | null;
    long_run_day: number | null;
}

/**
 * The prototype's `TrainingPreferencesCard`: always open, never a disclosure,
 * and built from the same three preference controls Onboarding uses — which is
 * how the prototype shares them too.
 */
export default function TrainingPreferencesCard({
    trainingPreferences,
}: Readonly<{ trainingPreferences: TrainingPreferencesPayload }>) {
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
        setRunDays((prev) =>
            prev.includes(offset)
                ? prev.filter((d) => d !== offset)
                : [...prev, offset],
        );
        if (longRunDay === offset) {
            setLongRunDay(null);
        }
    };

    const chooseSessions = (n: number) => {
        setSessionsPerWeek(n);
        setRunDays([]);
        setLongRunDay(null);
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
        <div className={cardVariants()}>
            <SectionLabel size="micro">Training preferences</SectionLabel>
            <p className="mb-4 font-sans text-xs leading-relaxed text-text-2">
                Set at onboarding, change them any time.
            </p>

            <FieldGroup label="Experience level">
                <div className="flex flex-col gap-1.5">
                    {EXPERIENCE_OPTIONS.map((option) => (
                        <IconChoiceCard
                            key={option.value}
                            icon={option.icon}
                            label={option.label}
                            description={option.description}
                            active={experienceLevel === option.value}
                            onClick={() =>
                                setExperienceLevel(
                                    experienceLevel === option.value
                                        ? null
                                        : option.value,
                                )
                            }
                        />
                    ))}
                </div>
            </FieldGroup>

            <FieldGroup label="Sessions per week">
                <SessionsDial
                    options={SESSIONS_OPTIONS}
                    value={sessionsPerWeek}
                    onChange={chooseSessions}
                />
            </FieldGroup>

            <FieldGroup label="Training goal">
                <div className="flex flex-col gap-1.5">
                    {GOAL_OPTIONS.map((option) => (
                        <IconChoiceCard
                            key={option.value}
                            icon={option.icon}
                            label={option.label}
                            description={option.description}
                            active={goalType === option.value}
                            onClick={() =>
                                setGoalType(
                                    goalType === option.value
                                        ? null
                                        : option.value,
                                )
                            }
                        />
                    ))}
                </div>
            </FieldGroup>

            <FieldGroup label="Usual run days">
                <p className="mb-2 font-sans text-xs text-text-3">
                    {sessionsPerWeek === null
                        ? 'Pick your sessions per week first.'
                        : `Pick ${sessionsPerWeek} · ${runDays.length} of ${sessionsPerWeek} selected.`}
                </p>
                <DayRow
                    items={DAY_OPTIONS.map((day) => {
                        const active = runDays.includes(day.offset);
                        return (
                            <DayCell
                                key={day.offset}
                                label={day.label}
                                active={active}
                                disabled={
                                    sessionsPerWeek === null ||
                                    (!active &&
                                        runDays.length >= sessionsPerWeek)
                                }
                                onClick={() => toggleRunDay(day.offset)}
                            />
                        );
                    })}
                />
            </FieldGroup>

            {runDays.length > 0 && (
                <div className="mb-1 rounded-xl bg-muted p-2.5">
                    <FieldLabel>Which one&rsquo;s the long run?</FieldLabel>
                    <DayRow
                        items={DAY_OPTIONS.map((day) =>
                            runDays.includes(day.offset) ? (
                                <DayCell
                                    key={day.offset}
                                    label={day.label}
                                    active
                                    flagCandidate={longRunDay !== day.offset}
                                    longRun={longRunDay === day.offset}
                                    onClick={() => setLongRunDay(day.offset)}
                                />
                            ) : (
                                <div
                                    key={day.offset}
                                    className="w-8"
                                    aria-hidden
                                />
                            ),
                        )}
                    />
                </div>
            )}

            <div className="mt-4 flex flex-col items-center gap-2">
                <PillButton
                    tone="horizon"
                    className="w-full justify-center"
                    onClick={submit}
                    disabled={processing || !isDirty || daysIncomplete}
                >
                    Save changes
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
    );
}

function FieldLabel({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <div className="mb-1.5 text-label-micro text-text-3">{children}</div>
    );
}

function FieldGroup({
    label,
    children,
}: Readonly<{ label: string; children: ReactNode }>) {
    return (
        <div className="mb-4">
            <FieldLabel>{label}</FieldLabel>
            {children}
        </div>
    );
}
