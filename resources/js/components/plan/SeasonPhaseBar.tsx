import { PHASE_COLORS, type PlanPhaseKey } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { PHASE_LABEL } from '@/pages/Plan';

export interface SeasonSummaryWeek {
    week_start: string;
    phase: string;
    type: 'history' | 'current' | 'lookahead';
    planned_km: number;
    actual_km: number | null;
}

interface PhaseSegment {
    phase: string;
    /** The first week of this run — stable across renders, used as the React key. */
    startWeek: string;
    plannedKm: number;
    state: 'done' | 'current' | 'upcoming';
}

function phaseColor(phase: string): string {
    return PHASE_COLORS[phase as PlanPhaseKey] ?? 'var(--color-text-3)';
}

/** Groups consecutive same-phase weeks into one bar segment, in season order. */
function segmentsFor(weeks: SeasonSummaryWeek[]): PhaseSegment[] {
    const segments: PhaseSegment[] = [];

    for (const week of weeks) {
        const last = segments[segments.length - 1];
        if (last && last.phase === week.phase) {
            last.plannedKm += week.planned_km;
            if (week.type === 'current') last.state = 'current';
            else if (last.state !== 'current' && week.type === 'lookahead')
                last.state = 'upcoming';
        } else {
            segments.push({
                phase: week.phase,
                startWeek: week.week_start,
                plannedKm: week.planned_km,
                state:
                    week.type === 'current'
                        ? 'current'
                        : week.type === 'history'
                          ? 'done'
                          : 'upcoming',
            });
        }
    }

    return segments;
}

/**
 * The season's periodization arc at a glance: one bar segment per phase
 * (Base/Build/Peak/Taper for a race-oriented season, a repeating Build/
 * Deload cycle for a self-scaled one — see `App\Enums\PlanPhase`), width
 * proportional to that phase's share of the season's total planned volume.
 * Consecutive same-phase weeks merge into one segment rather than always
 * rendering a fixed 4-column layout, so a self-scaled season's cycling
 * phases render honestly instead of forcing a race-shaped arc onto data
 * that was never that shape.
 */
export default function SeasonPhaseBar({
    weeks,
}: Readonly<{ weeks: SeasonSummaryWeek[] }>) {
    if (weeks.length === 0) {
        return null;
    }

    const segments = segmentsFor(weeks);
    const totalKm = segments.reduce((sum, s) => sum + s.plannedKm, 0);
    const loggedKm = weeks.reduce((sum, w) => sum + (w.actual_km ?? 0), 0);
    const loggedThroughKm = weeks
        .filter((w) => w.type !== 'lookahead')
        .reduce((sum, w) => sum + w.planned_km, 0);

    return (
        <div className="flex flex-col gap-2">
            <div
                className="flex h-2.5 gap-1"
                role="img"
                aria-label={`Season plan by phase: ${segments.map((s) => `${PHASE_LABEL[s.phase] ?? s.phase} ${Math.round(s.plannedKm)} km`).join(', ')}.`}
            >
                {segments.map((segment) => (
                    <div
                        key={segment.startWeek}
                        className={cn(
                            'rounded-full',
                            segment.state === 'current' &&
                                'ring-2 ring-foreground/40',
                        )}
                        style={{
                            width:
                                totalKm > 0
                                    ? `${(segment.plannedKm / totalKm) * 100}%`
                                    : `${100 / segments.length}%`,
                            backgroundColor: phaseColor(segment.phase),
                            opacity: segment.state === 'upcoming' ? 0.35 : 1,
                        }}
                    />
                ))}
            </div>
            <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-text-2">
                {segments.map((segment) => (
                    <span
                        key={segment.startWeek}
                        className="flex items-center gap-1"
                    >
                        <span
                            aria-hidden
                            className="h-1.5 w-1.5 rounded-full"
                            style={{
                                backgroundColor: phaseColor(segment.phase),
                            }}
                        />
                        {PHASE_LABEL[segment.phase] ?? segment.phase}
                        {' · '}
                        {Math.round(segment.plannedKm)} km
                    </span>
                ))}
            </div>
            {loggedThroughKm > 0 && (
                <p className="text-xs text-text-2">
                    {Math.round(loggedKm)} km logged of{' '}
                    {Math.round(loggedThroughKm)} km planned so far this season.
                </p>
            )}
        </div>
    );
}
