import { Flame } from 'lucide-react';

import type { PlanDay } from '@/lib/plan';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import {
    EFFORT_EDGE_CLASS,
    EFFORT_ICON_CLASS,
    sessionTypeEffort,
} from '@/lib/effort';
import { formatKm } from '@/lib/pace';
import { dayStatusGlyph, SESSION_TYPE_ICON, weekdayLabel } from '@/lib/plan';

const kmFigure = (km: number | null): string =>
    formatKm(km === null ? null : km * 1000, 1);

/** The borderless column both Today's and Plan's week strip wrap their day
 *  content in — only the ring/selection state differs between the two. */
export const DAY_CELL_CLASS =
    'focus-ring relative flex flex-col items-center gap-0.5 h-full pt-1.5 pb-2 transition-colors hover:bg-muted';

/**
 * The shared day cell content for Today's week widget and Plan's week strip
 * (design/day-cell, #1295): weekday, the session-type icon in its planned
 * effort color, the km line, the status glyph in the corner, and the
 * square, full-width effort bar along the bottom. The interactive wrapper
 * (a `Link` on Today, a tab `button` on Plan) and its selection/ring
 * treatment stay with each caller.
 */
export function DayCellBody({
    day,
    hasElapsed,
}: Readonly<{ day: PlanDay; hasElapsed: boolean }>) {
    const isRest = day.session_type === 'rest';
    const ran = hasElapsed && day.actual_km !== null;
    const glyph = dayStatusGlyph(day);
    const effort = sessionTypeEffort(day.session_type);
    const planned = isRest ? null : (day.prescribed_km ?? day.distance_km);

    return (
        <>
            {glyph !== null && (
                <Icon
                    icon={glyph}
                    width={8}
                    height={8}
                    className="absolute top-1 right-1 text-foreground"
                    aria-hidden
                />
            )}
            <span className="text-label-micro text-foreground">
                {weekdayLabel(day.date)}
            </span>
            <Icon
                icon={SESSION_TYPE_ICON[day.session_type] ?? Flame}
                width={13}
                height={13}
                className={EFFORT_ICON_CLASS[effort]}
                aria-hidden
            />
            {ran ? (
                <>
                    <span className="text-meta font-bold tracking-tight whitespace-nowrap tabular-nums text-foreground">
                        {kmFigure(day.actual_km)} km
                    </span>
                    {!isRest && (
                        <span className="text-meta leading-tight tracking-tight whitespace-nowrap text-text-2">
                            of {kmFigure(planned)}
                        </span>
                    )}
                </>
            ) : (
                <span className="text-meta tracking-tight whitespace-nowrap text-foreground">
                    {isRest ? 'rest' : `${kmFigure(planned)} km`}
                </span>
            )}
            <span
                aria-hidden
                className={cn(
                    'absolute inset-x-0 -bottom-[3px]',
                    EFFORT_EDGE_CLASS[effort],
                )}
            />
        </>
    );
}
