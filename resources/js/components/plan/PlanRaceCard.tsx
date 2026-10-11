import { Link, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import type {
    PlanRaceCard as PlanRaceCardPayload,
    RaceOutcomeCandidate,
} from '@/types/inertia';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import Skeleton from '@/components/ui/Skeleton';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { cn } from '@/lib/cn';
import {
    formatKm,
    formatNaiveMonthDayId,
    formatPace,
    formatRaceTime,
    parseNaiveLocalDate,
} from '@/lib/pace';
import { weeksLabel, weeksToRace } from '@/lib/plan';
import { RACE_DEMO_BLOCKED, raceDistanceLabel } from '@/lib/raceGoal';
import { pillButtonVariants } from '@/lib/variants';

const CARD = 'rounded-panel border bg-card pad-card';

function countdown(card: PlanRaceCardPayload, today: string): string {
    const days = card.days_to_race ?? 0;

    return days < 14
        ? `in ${days} days`
        : `in ${weeksLabel(weeksToRace(today, card.race_date))}`;
}

function weekday(iso: string): string {
    return (
        parseNaiveLocalDate(iso)
            ?.toLocaleDateString('en-US', { weekday: 'long' })
            .toLowerCase() ?? ''
    );
}

function goalPace(card: PlanRaceCardPayload): string {
    return `${formatPace(card.goal_time_sec / (card.distance_m / 1000))} /km`;
}

function RaceLinkCard({
    className,
    children,
}: Readonly<{ className: string; children: ReactNode }>) {
    return (
        <Link
            href="/race"
            className={cn(
                CARD,
                'focus-ring flex items-center justify-between gap-3',
                className,
            )}
        >
            <div className="min-w-0">{children}</div>
            <Icon
                icon={ChevronRight}
                className="size-4 flex-none text-text-3"
                aria-hidden
            />
        </Link>
    );
}

function ResultPending({
    card,
    candidate,
    className,
}: Readonly<{
    card: PlanRaceCardPayload;
    candidate: RaceOutcomeCandidate | null | undefined;
    className?: string;
}>) {
    const [processing, setProcessing] = useState(false);
    const {
        open: demoBlocked,
        setOpen: setDemoBlocked,
        guard,
    } = useDemoGuard();

    const post = (payload: Record<string, number | string>) => {
        guard(() =>
            router.post(`/race/${card.race_id}/outcome`, payload, {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            }),
        );
    };

    const pickLabel = candidate ? 'pick another' : 'pick a run';

    return (
        <section
            aria-label="race result"
            className={cn(CARD, 'border-border', className)}
        >
            <Eyebrow token="micro" tone="ink-2">
                {formatNaiveMonthDayId(card.race_date)} ·{' '}
                {candidate === null ? 'how did it go?' : 'is this your race?'}
            </Eyebrow>
            {candidate === undefined ? (
                <Skeleton className="mt-2 h-4 w-48" />
            ) : (
                <>
                    {candidate && (
                        <p className="mt-1 font-mono text-sm text-foreground tabular-nums">
                            {`${candidate.name ?? 'run'} · ${formatKm(candidate.distance_m, 1)} km · ${formatRaceTime(candidate.elapsed_time_sec)}`}
                        </p>
                    )}
                    <div className="mt-3 flex flex-wrap gap-2">
                        {candidate && (
                            <PillButton
                                tone="horizon"
                                size="xs"
                                disabled={processing}
                                onClick={() =>
                                    post({
                                        outcome: 'confirmed',
                                        activity_id: candidate.activity_id,
                                    })
                                }
                            >
                                yes
                            </PillButton>
                        )}
                        <Link
                            href="/race"
                            className={pillButtonVariants({
                                tone: 'muted',
                                size: 'xs',
                            })}
                        >
                            {pickLabel}
                        </Link>
                        <PillButton
                            tone="ghost"
                            size="xs"
                            disabled={processing}
                            onClick={() => post({ outcome: 'did_not_run' })}
                        >
                            didn&apos;t run
                        </PillButton>
                    </div>
                </>
            )}
            <DemoBlockedModal
                open={demoBlocked}
                onClose={() => setDemoBlocked(false)}
                {...RACE_DEMO_BLOCKED}
            />
        </section>
    );
}

/**
 * The race Plan leads with: a quiet countdown card, race week in citrus with
 * the goal pace, a lime hero on race day, each linking to the race, or, once
 * the race has passed, the run to confirm as its result. A recorded result belongs to the season card.
 */
export default function PlanRaceCard({
    card,
    today,
    supportedTimeSec,
    candidate,
    className,
}: Readonly<{
    card: PlanRaceCardPayload | null;
    today: string;
    /** Deferred: undefined until it loads. */
    supportedTimeSec?: number | null;
    /** Deferred: undefined until it loads. */
    candidate?: RaceOutcomeCandidate | null;
    className?: string;
}>) {
    if (card === null || card.state === 'result_recorded') {
        return null;
    }

    const distance = raceDistanceLabel(card.distance_m);
    const goal = formatRaceTime(card.goal_time_sec);

    if (card.state === 'result_pending') {
        return (
            <ResultPending
                card={card}
                candidate={candidate}
                className={className}
            />
        );
    }

    if (card.state === 'day') {
        return (
            <Link
                href="/race"
                className={cn(
                    'focus-ring block rounded-panel bg-horizon pad-hero',
                    className,
                )}
            >
                <Eyebrow token="micro" className="text-sky">
                    race day · {distance}
                </Eyebrow>
                <p className="mt-2 font-serif text-quote-lg text-sky italic">
                    go get your {goal}.
                </p>
                <p className="mt-1 font-mono text-xs text-sky-2 tabular-nums">
                    {goalPace(card)}
                </p>
                <p className="font-mono text-xs text-sky-2">
                    start easy, finish strong
                </p>
            </Link>
        );
    }

    if (card.state === 'week') {
        const days = card.days_to_race ?? 0;

        return (
            <RaceLinkCard className={cn('border-citrus', className)}>
                <Eyebrow token="micro" className="text-citrus-ink">
                    {days === 7
                        ? 'race in 7 days'
                        : `race ${weekday(card.race_date)}`}
                </Eyebrow>
                <p className="mt-1 font-mono text-sm text-foreground tabular-nums">
                    {distance} {goal} · pace {goalPace(card)}
                </p>
                <p className="font-mono text-xs text-text-2">stay fresh</p>
            </RaceLinkCard>
        );
    }

    return (
        <RaceLinkCard className={cn('border-border', className)}>
            <Eyebrow token="micro" tone="ink-2">
                race · {formatNaiveMonthDayId(card.race_date)} ·{' '}
                {countdown(card, today)}
            </Eyebrow>
            <p className="mt-1 font-mono text-sm text-foreground tabular-nums">
                {distance} {goal}
                {supportedTimeSec != null && (
                    <span className="whitespace-nowrap">
                        {` vs supported ${formatRaceTime(supportedTimeSec)}`}
                    </span>
                )}
            </p>
        </RaceLinkCard>
    );
}
