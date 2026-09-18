import { useState } from 'react';

import { phaseColor } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { PHASE_LABEL, phaseGroupKey, type SeasonSummaryWeek } from '@/lib/plan';

/** What a cell's hover/tap/focus reveals: its phase name, plus that it's the current week. */
function cellLabel(week: SeasonSummaryWeek): string {
    const base = PHASE_LABEL[phaseGroupKey(week)] ?? week.phase;
    return week.type === 'current' ? `${base}, current week` : base;
}

/**
 * The season at a glance, one cell per week from season start to race day: a
 * flat neutral "maintain" band for the weeks before the race block opens,
 * phase-coloured cells for the weeks inside it. Reads the `zone` each
 * week of the season summary already carries
 * ({@see docs/decisions/the-block-opens-on-a-computed-date.md}) rather than
 * computing anything of its own. A general week's own `phase` (the
 * self-scaled cycle alternates Build/Deload before the block opens) is
 * deliberately ignored so the band reads as one continuous fill, not a
 * flicker of per-week colour.
 *
 * "Now" is drawn, not marked: every week up to and including the current one
 * is solid, every week still ahead is faded (`opacity-40`) - in both the
 * neutral band and the phase-coloured block - so the solid/faded edge alone
 * reads as "you are here". A separate ring/dot marker used to sit on the
 * current cell alone; isolated against the flat neutral band it read as a
 * stray broken box rather than a marker, which is why this reads the fill
 * instead.
 *
 * No number ever prints here - the phase name is the only thing hover, tap
 * or keyboard focus reveals, and it doubles as each cell's accessible name
 * (with ", current week" appended for the one cell that is). A goal-less
 * season has no block at all, so it renders nothing rather than an
 * all-neutral bar with nothing to show.
 */
export default function PhaseRibbon({
    weeks,
}: Readonly<{ weeks: SeasonSummaryWeek[] }>) {
    const [activeIndex, setActiveIndex] = useState<number | null>(null);

    if (!weeks.some((week) => week.zone === 'block')) {
        return null;
    }

    const clearIfActive = (index: number) => {
        setActiveIndex((current) => (current === index ? null : current));
    };

    const active = activeIndex === null ? null : weeks[activeIndex];

    return (
        <div className="mt-3">
            <div className="flex h-3 w-full overflow-hidden rounded-full border border-border">
                {weeks.map((week, index) => (
                    <button
                        key={week.week_start}
                        type="button"
                        aria-label={cellLabel(week)}
                        className={cn(
                            'focus-ring h-full min-w-0 flex-1',
                            week.zone === 'general' && 'bg-muted',
                            week.type === 'lookahead' && 'opacity-40',
                        )}
                        style={
                            week.zone === 'block'
                                ? { backgroundColor: phaseColor(week.phase) }
                                : undefined
                        }
                        onMouseEnter={() => setActiveIndex(index)}
                        onMouseLeave={() => clearIfActive(index)}
                        onFocus={() => setActiveIndex(index)}
                        onBlur={() => clearIfActive(index)}
                        onClick={() => setActiveIndex(index)}
                    />
                ))}
            </div>
            <p className="mt-1 text-label-micro text-text-2" aria-live="polite">
                {active ? cellLabel(active) : ' '}
            </p>
        </div>
    );
}
