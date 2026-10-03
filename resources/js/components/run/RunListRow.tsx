import { Link } from '@inertiajs/react';
import { Sparkle } from 'lucide-react';
import { memo } from 'react';

import type { ActivityDetail, Mood, RunCard } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { EFFORT_STRIPE_CLASS } from '@/lib/effort';
import { MOOD_FILL, MOOD_LABEL } from '@/lib/mood';
import {
    formatDurationHMS,
    formatKm,
    formatNaiveMonthDayId,
    formatNaiveTimeId,
    formatPace,
    paceSecPerKm,
} from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { activityUrl } from '@/lib/routes';
import { RARITY_INK } from '@/lib/runcard';
import { morphRunCard } from '@/lib/runMorph';
import { EFFORT_WORD } from '@/pages/Activities/calendarBars';

export interface RunNote {
    oneline: string;
    mood: Mood;
}

interface RunListRowProps {
    detail: ActivityDetail;
    mood?: Mood | null;
    note?: RunNote | null;
    /** The run's earned Card, when one has been generated. */
    runCard?: RunCard | null;
}

function RunListRow({
    detail,
    mood = null,
    note = null,
    runCard = null,
}: Readonly<RunListRowProps>) {
    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.elapsed_time, detail.distance);
    const paceLabel = paceSec != null ? formatPace(paceSec) : '—';
    const hr =
        detail.average_heartrate != null
            ? Math.round(detail.average_heartrate)
            : null;
    const knownMood: Mood | null = note?.mood ?? mood ?? null;
    const name = detail.name ?? 'Run';
    const startTime = formatNaiveTimeId(detail.start_date_local);
    const stripeClass = EFFORT_STRIPE_CLASS[detail.effort ?? 'unknown'];

    return (
        <Link
            href={activityUrl(detail)}
            data-run-morph={detail.activity_id}
            viewTransition={morphRunCard(detail.activity_id)}
            className={cn(
                'pressable block p-3.5 text-sm transition hover:bg-background',
                stripeClass,
            )}
        >
            <div className="flex min-w-0 items-center gap-1.5">
                {knownMood !== null && (
                    <span
                        aria-hidden
                        className={cn(
                            'size-[7px] flex-none rounded-full',
                            MOOD_FILL[knownMood],
                        )}
                    />
                )}
                <span
                    title={name}
                    className="truncate text-xs leading-[1.2] font-bold text-foreground"
                >
                    {name}
                </span>
                <span className="flex-none font-mono text-xs leading-[1.2] font-bold text-foreground tabular-nums">
                    · {km} km
                </span>
                {runCard && (
                    <Icon
                        icon={Sparkle}
                        width={12}
                        height={12}
                        className={cn('flex-none', RARITY_INK[runCard.rarity])}
                        aria-label={`${runCard.rarity} card`}
                    />
                )}
            </div>
            <div className="mt-1.25 flex flex-wrap items-baseline gap-x-1.75 gap-y-0.5 font-mono tabular-nums">
                <b className="text-xs leading-[1.2] font-extrabold text-foreground">
                    {formatDurationHMS(detail.elapsed_time)}
                </b>
                <span className="text-[0.6875rem] text-border-strong">·</span>
                <b className="text-xs leading-[1.2] font-extrabold text-foreground">
                    {paceLabel}
                </b>
                <span className="text-[0.6875rem] text-border-strong">·</span>
                <span className="text-xs leading-[1.2] font-extrabold text-foreground">
                    {hr ?? '—'} bpm
                </span>
                <span className="ml-auto text-[0.6875rem] leading-[1.2] whitespace-nowrap text-text-3">
                    {formatNaiveMonthDayId(detail.start_date_local)}
                    {startTime && (
                        <span className="max-[359px]:hidden">
                            {` · ${startTime}`}
                        </span>
                    )}
                </span>
            </div>
            {note && (
                <p className="narration-dense mt-1.25 truncate">
                    &quot;{renderBold(note.oneline)}&quot;
                </p>
            )}
            <span className="sr-only">
                {`, ${EFFORT_WORD[detail.effort ?? 'unknown']} effort`}
                {knownMood && `, ${MOOD_LABEL[knownMood]} mood`}
            </span>
        </Link>
    );
}

export default memo(RunListRow);
