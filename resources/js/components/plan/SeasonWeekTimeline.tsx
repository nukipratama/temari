import { type ReactNode } from 'react';

import Chip from '@/components/ui/Chip';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { formatNaiveMonthDayId } from '@/lib/pace';
import { PHASE_LABEL, PHASE_TONE } from '@/pages/Plan';

import type { SeasonSummaryWeek } from './SeasonPhaseBar';

/** Weeks kept visible on both sides of the current week before collapsing behind a toggle. */
const VISIBLE_RADIUS = 2;

function WeekVolumeBar({
    plannedKm,
    actualKm,
    maxKm,
}: Readonly<{ plannedKm: number; actualKm: number | null; maxKm: number }>) {
    const plannedPct = maxKm > 0 ? Math.min(100, (plannedKm / maxKm) * 100) : 0;
    const actualPct =
        actualKm != null && maxKm > 0
            ? Math.min(100, (actualKm / maxKm) * 100)
            : null;

    return (
        <div
            aria-hidden
            className="relative h-2 min-w-16 flex-1 rounded-full bg-foreground/[0.06]"
        >
            <div
                className="absolute inset-y-0 left-0 rounded-full border border-dashed border-border-strong"
                style={{ width: `${plannedPct}%` }}
            />
            {actualPct != null && (
                <div
                    className="absolute inset-y-0 left-0 rounded-full bg-horizon"
                    style={{ width: `${actualPct}%` }}
                />
            )}
        </div>
    );
}

function WeekRow({
    week,
    maxKm,
}: Readonly<{ week: SeasonSummaryWeek; maxKm: number }>) {
    return (
        <div
            className={cn(
                'flex items-center gap-2 py-2',
                week.type === 'current' && 'text-foreground',
            )}
        >
            <span className="w-12 shrink-0 font-mono text-[10px] font-semibold uppercase tracking-wider text-text-3">
                {formatNaiveMonthDayId(week.week_start)}
            </span>
            <Chip tone={PHASE_TONE[week.phase] ?? 'neutral'}>
                {PHASE_LABEL[week.phase] ?? week.phase}
            </Chip>
            <WeekVolumeBar
                plannedKm={week.planned_km}
                actualKm={week.actual_km}
                maxKm={maxKm}
            />
            <span className="w-16 shrink-0 text-right text-[11px] tabular-nums text-text-2">
                {week.actual_km != null
                    ? `${Math.round(week.actual_km)}/${Math.round(week.planned_km)} km`
                    : `${Math.round(week.planned_km)} km`}
            </span>
        </div>
    );
}

function WeekGroupToggle({
    label,
    children,
}: Readonly<{ label: string; children: ReactNode }>) {
    return (
        <Collapsible>
            <CollapsibleTrigger className="group focus-ring flex items-center gap-1 py-1 text-xs text-text-3 hover:text-foreground">
                <Icon
                    icon="mdi:chevron-down"
                    width={12}
                    height={12}
                    className="transition-transform group-aria-expanded:rotate-180"
                />
                {label}
            </CollapsibleTrigger>
            <CollapsibleContent>{children}</CollapsibleContent>
        </Collapsible>
    );
}

/**
 * The season's week-by-week planned-vs-actual volume, across the whole
 * arc (not just `PlanController`'s rolling 3-history/4-lookahead render
 * window — this reads {@see SeasonSummaryBuilder}'s season-wide figures).
 * Weeks outside a small window around the current week collapse behind a
 * toggle rather than always rendering a potentially 12-20-row list.
 */
export default function SeasonWeekTimeline({
    weeks,
}: Readonly<{ weeks: SeasonSummaryWeek[] }>) {
    if (weeks.length === 0) {
        return null;
    }

    const currentIndex = weeks.findIndex((w) => w.type === 'current');
    const anchor = currentIndex === -1 ? 0 : currentIndex;
    const visibleStart = Math.max(0, anchor - VISIBLE_RADIUS);
    const visibleEnd = Math.min(weeks.length - 1, anchor + VISIBLE_RADIUS);

    const earlier = weeks.slice(0, visibleStart);
    const visible = weeks.slice(visibleStart, visibleEnd + 1);
    const later = weeks.slice(visibleEnd + 1);

    const maxKm = Math.max(
        ...weeks.map((w) => Math.max(w.planned_km, w.actual_km ?? 0)),
        1,
    );

    return (
        <div className="flex flex-col divide-y divide-border/60">
            {earlier.length > 0 && (
                <WeekGroupToggle label={`${earlier.length} weeks earlier`}>
                    {earlier.map((week) => (
                        <WeekRow
                            key={week.week_start}
                            week={week}
                            maxKm={maxKm}
                        />
                    ))}
                </WeekGroupToggle>
            )}
            {visible.map((week) => (
                <WeekRow key={week.week_start} week={week} maxKm={maxKm} />
            ))}
            {later.length > 0 && (
                <WeekGroupToggle label={`${later.length} weeks ahead`}>
                    {later.map((week) => (
                        <WeekRow
                            key={week.week_start}
                            week={week}
                            maxKm={maxKm}
                        />
                    ))}
                </WeekGroupToggle>
            )}
        </div>
    );
}
