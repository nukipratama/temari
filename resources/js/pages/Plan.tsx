import type { FormDataConvertible } from '@inertiajs/core';

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Card from '@/components/ui/Card';
import Chip, { type ChipTone } from '@/components/ui/Chip';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { formatNaiveIdDate, formatPace, todayLocalIso } from '@/lib/pace';

interface PlanDay {
    id: number;
    date: string;
    phase: string;
    session_type: string;
    distance_band: string;
    pace_band: string | null;
    pace_sec_per_km: number | null;
    distance_km: number;
    pinned: boolean;
    status: string;
    clamp_note: string | null;
}

interface PlanWeek {
    week_start: string;
    phase: string;
    type: 'history' | 'current' | 'lookahead';
    days: PlanDay[];
}

interface PlanProps {
    race: { race_date: string; name: string | null } | null;
    sessionsPerWeek: number;
    weeks: PlanWeek[];
}

const SESSION_TYPE_LABEL: Record<string, string> = {
    easy: 'Easy',
    long: 'Long run',
    tempo: 'Tempo',
    interval: 'Interval',
    rest: 'Rest',
};

const PHASE_LABEL: Record<string, string> = {
    base: 'Base',
    build: 'Build',
    peak: 'Peak',
    taper: 'Taper',
    deload: 'Deload',
};

const PHASE_TONE: Record<string, ChipTone> = {
    base: 'neutral',
    build: 'sky',
    peak: 'horizon',
    taper: 'horizon',
    deload: 'neutral',
};

const BAND_ORDER = ['short', 'medium', 'long'] as const;

function paceLabel(day: PlanDay): string | null {
    if (day.pace_sec_per_km == null) return null;
    return `${formatPace(day.pace_sec_per_km)}/km`;
}

export default function Plan({
    race,
    sessionsPerWeek,
    weeks,
}: Readonly<PlanProps>) {
    const [regenerating, setRegenerating] = useState(false);
    const today = todayLocalIso();

    const regenerate = () => {
        router.post(
            '/plan/regenerate',
            {},
            {
                preserveScroll: true,
                onStart: () => setRegenerating(true),
                onFinish: () => setRegenerating(false),
            },
        );
    };

    const patchDay = (
        day: PlanDay,
        attributes: Record<string, FormDataConvertible>,
    ) => {
        router.patch(`/plan/sessions/${day.id}`, attributes, {
            preserveScroll: true,
        });
    };

    const togglePin = (day: PlanDay) => patchDay(day, { pinned: !day.pinned });

    const cycleBand = (day: PlanDay) => {
        const index = BAND_ORDER.indexOf(
            day.distance_band as (typeof BAND_ORDER)[number],
        );
        const next = BAND_ORDER[(index + 1) % BAND_ORDER.length];
        patchDay(day, { distance_band: next });
    };

    const toggleBlock = (day: PlanDay) => {
        if (day.session_type === 'rest') {
            patchDay(day, {
                session_type: 'easy',
                distance_band: 'medium',
                pace_band: 'easy',
            });
        } else {
            patchDay(day, { session_type: 'rest' });
        }
    };

    const moveDay = (day: PlanDay, newDate: string) => {
        if (newDate === '' || newDate === day.date) return;
        patchDay(day, { date: newDate });
    };

    const deleteDay = (day: PlanDay) => {
        router.delete(`/plan/sessions/${day.id}`, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Plan" />
            <PageContainer>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Eyebrow token="hero" tone="ink-2" className="mb-3.5">
                            Plan
                        </Eyebrow>
                        <h1 className="font-display text-display-lg text-ink">
                            The weeks ahead.
                        </h1>
                        <p className="mt-2 max-w-xl font-sans text-sm leading-relaxed text-ink-2">
                            {race
                                ? `Built around ${race.name ?? 'your race'} on ${formatNaiveIdDate(race.race_date, 'long')}, about ${sessionsPerWeek} sessions a week.`
                                : `No race set yet, so this cycles a steady build-and-deload rhythm, about ${sessionsPerWeek} sessions a week.`}{' '}
                            <Link
                                href="/race"
                                className="underline underline-offset-2 hover:text-ink"
                            >
                                {race ? 'Change your race' : 'Set a race'}
                            </Link>
                        </p>
                    </div>
                    <PillButton
                        tone="sky"
                        onClick={regenerate}
                        disabled={regenerating}
                    >
                        {regenerating ? 'Replanning…' : 'Regenerate'}
                    </PillButton>
                </header>

                {weeks.length === 0 && (
                    <EmptyPanel
                        pose="proud"
                        title="No plan yet."
                        body="Hit Regenerate and Temari will lay out the weeks ahead."
                        className="mt-8"
                    />
                )}

                <div className="mt-8 flex flex-col gap-8">
                    {weeks.map((week) => (
                        <section key={week.week_start}>
                            <div className="flex items-center gap-2.5">
                                <SectionLabel>
                                    Week of{' '}
                                    {formatNaiveIdDate(week.week_start, 'long')}
                                </SectionLabel>
                                <Chip
                                    tone={PHASE_TONE[week.phase] ?? 'neutral'}
                                >
                                    {PHASE_LABEL[week.phase] ?? week.phase}
                                </Chip>
                                {week.type === 'history' && (
                                    <Chip tone="neutral">History</Chip>
                                )}
                            </div>

                            <div className="mt-3 flex flex-col gap-2">
                                {week.days.map((day) => {
                                    const editable =
                                        day.date >= today &&
                                        week.type !== 'history';

                                    return (
                                        <Card
                                            key={day.date}
                                            padding="sm"
                                            className={cn(
                                                'flex flex-wrap items-center justify-between gap-3',
                                                day.date === today &&
                                                    'border-horizon',
                                            )}
                                        >
                                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                                <span className="w-24 shrink-0 font-mono text-xs font-semibold uppercase tracking-wider text-ink-3">
                                                    {formatNaiveIdDate(
                                                        day.date,
                                                        'short',
                                                    )}
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="text-sm font-semibold text-ink">
                                                        {SESSION_TYPE_LABEL[
                                                            day.session_type
                                                        ] ??
                                                            day.session_type}{' '}
                                                        {day.session_type !==
                                                            'rest' && (
                                                            <span className="ml-1.5 font-normal text-ink-2">
                                                                {
                                                                    day.distance_km
                                                                }{' '}
                                                                km
                                                                {paceLabel(
                                                                    day,
                                                                ) &&
                                                                    ` · ${paceLabel(day)}`}
                                                            </span>
                                                        )}
                                                        {day.pinned && (
                                                            <span
                                                                className="ml-1.5 text-ink-3"
                                                                title="Pinned"
                                                                aria-label="Pinned"
                                                            >
                                                                📌
                                                            </span>
                                                        )}
                                                    </p>
                                                    {day.clamp_note && (
                                                        <p className="mt-0.5 text-xs italic text-ink-2">
                                                            {day.clamp_note}
                                                        </p>
                                                    )}
                                                    {week.type === 'history' &&
                                                        day.session_type !==
                                                            'rest' && (
                                                            <p className="mt-0.5 text-xs text-ink-3">
                                                                {day.status ===
                                                                'done'
                                                                    ? 'Done'
                                                                    : day.status ===
                                                                        'missed'
                                                                      ? 'Missed'
                                                                      : ''}
                                                            </p>
                                                        )}
                                                </div>
                                            </div>

                                            {editable && (
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    {day.session_type !==
                                                        'rest' && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                cycleBand(day)
                                                            }
                                                            className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-horizon/60 hover:text-ink"
                                                        >
                                                            Resize
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            toggleBlock(day)
                                                        }
                                                        className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-horizon/60 hover:text-ink"
                                                    >
                                                        {day.session_type ===
                                                        'rest'
                                                            ? 'Restore'
                                                            : 'Block'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            togglePin(day)
                                                        }
                                                        className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-horizon/60 hover:text-ink"
                                                    >
                                                        {day.pinned
                                                            ? 'Unpin'
                                                            : 'Pin'}
                                                    </button>
                                                    <input
                                                        type="date"
                                                        aria-label={`Move ${day.date}`}
                                                        min={today}
                                                        defaultValue={day.date}
                                                        onChange={(e) =>
                                                            moveDay(
                                                                day,
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="focus-ring rounded-full border border-line bg-surface px-2 py-1 text-label-micro text-ink-3"
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            deleteDay(day)
                                                        }
                                                        aria-label={`Delete ${day.date}`}
                                                        className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-ember hover:text-ember"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            )}
                                        </Card>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            </PageContainer>
        </>
    );
}

Plan.layout = appLayout;
