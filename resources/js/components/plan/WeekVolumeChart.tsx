import type { PlanDay } from '@/lib/plan';

import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { computeAdherence, STATUS_BAR_FILL, weekdayLabel } from '@/lib/plan';

const TALLIED_STATUSES = ['done', 'partial', 'missed', 'overreached'] as const;

function complianceTally(days: PlanDay[]): string {
    return TALLIED_STATUSES.map((status) => ({
        status,
        count: days.filter((d) => d.status === status).length,
    }))
        .filter(({ count }) => count > 0)
        .map(({ status, count }) => `${count} ${status}`)
        .join(' · ');
}

/**
 * The week's planned-vs-actual volume, one column per day: a dashed outline
 * for what the plan asked for, a filled bar coloured by that day's
 * compliance verdict for what was actually run.
 */
export default function WeekVolumeChart({
    days,
    isCurrent = false,
}: Readonly<{ days: PlanDay[]; isCurrent?: boolean }>) {
    const maxKm = Math.max(
        ...days.map((d) => Math.max(d.distance_km, d.actual_km ?? 0)),
        1,
    );
    const adherence = computeAdherence(days);
    const tally = complianceTally(days);

    return (
        <Card padding="panel" className="mb-3 border-border-strong">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-label-micro text-text-2">
                        Volume {isCurrent ? 'this week' : 'that week'}
                    </p>
                    {tally !== '' && (
                        <p className="mt-1 text-xs text-text-2">
                            {tally}
                            {isCurrent && ' so far'}
                        </p>
                    )}
                </div>
                {adherence != null && (
                    <p className="font-mono text-xl font-bold tabular-nums text-horizon-ink">
                        {adherence}%
                    </p>
                )}
            </div>
            <div className="mt-3 mb-1.5 flex items-center gap-3 text-xs text-text-2">
                <span className="flex items-center gap-1">
                    <span
                        className="size-2 rounded-xs border border-dashed border-border-strong"
                        aria-hidden
                    />
                    planned
                </span>
                <span className="flex items-center gap-1">
                    <span
                        className="size-2 rounded-xs bg-horizon"
                        aria-hidden
                    />
                    actual
                </span>
            </div>
            <div className="flex h-16 items-end gap-1.5">
                {days.map((day) => (
                    <div
                        key={day.date}
                        className="flex flex-1 flex-col items-center gap-1"
                    >
                        <div className="relative flex h-14 w-full items-end justify-center">
                            {day.distance_km > 0 && (
                                <div
                                    className="absolute w-full rounded-t-xs border border-dashed border-border-strong"
                                    style={{
                                        height: `${(day.distance_km / maxKm) * 100}%`,
                                    }}
                                    aria-hidden
                                />
                            )}
                            {day.actual_km != null && (
                                <div
                                    className={cn(
                                        'absolute w-full rounded-t-xs',
                                        STATUS_BAR_FILL[day.status] ??
                                            'bg-horizon',
                                    )}
                                    style={{
                                        height: `${(day.actual_km / maxKm) * 100}%`,
                                    }}
                                />
                            )}
                        </div>
                        <span className="text-label-micro text-text-3">
                            {weekdayLabel(day.date)}
                        </span>
                    </div>
                ))}
            </div>
        </Card>
    );
}
