import { memo } from 'react';

import type { ActivityDetail, Mood, RunCard } from '@/types/inertia';

import MotionLink from '@/components/MotionLink';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { MOOD_FILL } from '@/lib/mood';
import { moodFromActivity } from '@/lib/moodFromActivity';
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

export interface RunNote {
    oneline: string;
    mood: Mood;
}

interface RunListRowProps {
    detail: ActivityDetail;
    mood?: Mood | null;
    note?: RunNote | null;
    /** The run's earned Kartu, when one has been generated. */
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
    const safeMood: Mood = note?.mood ?? mood ?? moodFromActivity(detail);
    const startTime = formatNaiveTimeId(detail.start_date_local);

    return (
        <MotionLink
            href={activityUrl(detail)}
            className="block border-b border-border-strong p-3.5 text-sm transition last:border-b-0 hover:bg-background"
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-1.5">
                    <span
                        aria-hidden
                        className={cn(
                            'size-[7px] flex-none rounded-full',
                            MOOD_FILL[safeMood],
                        )}
                    />
                    <span className="truncate text-[0.8125rem] leading-[1.2] font-bold text-foreground">
                        {detail.name ?? 'Run'}
                    </span>
                    <span className="flex-none font-mono text-[0.8125rem] leading-[1.2] font-bold text-foreground">
                        · {km} km
                    </span>
                    {runCard && (
                        <Icon
                            icon="mdi:sparkle-outline"
                            width={12}
                            height={12}
                            className={cn(
                                'flex-none',
                                RARITY_INK[runCard.rarity],
                            )}
                            aria-label={`${runCard.rarity} kartu`}
                        />
                    )}
                </div>
                <span className="flex-none font-mono text-[0.59375rem] leading-[1.2] text-text-3">
                    {formatNaiveMonthDayId(detail.start_date_local)}
                    {startTime && ` · ${startTime}`}
                </span>
            </div>
            <div className="mt-1.25 flex items-baseline gap-1.75 font-mono">
                <b className="text-[0.8125rem] leading-[1.2] font-extrabold text-foreground">
                    {formatDurationHMS(detail.elapsed_time)}
                </b>
                <span className="text-[0.6875rem] text-border-strong">·</span>
                <b className="text-[0.8125rem] leading-[1.2] font-extrabold text-foreground">
                    {paceLabel}
                </b>
                <span className="text-[0.6875rem] text-border-strong">·</span>
                <span className="text-[0.8125rem] leading-[1.2] font-extrabold text-foreground">
                    {hr ?? '—'} bpm
                </span>
            </div>
            {note && (
                <p className="mt-1.25 truncate font-serif text-[0.65625rem] leading-[1.2] text-text-2 italic">
                    &quot;{renderBold(note.oneline)}&quot;
                </p>
            )}
        </MotionLink>
    );
}

export default memo(RunListRow);
