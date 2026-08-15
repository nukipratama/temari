import { TrendPanel } from '@/components/TrendPanel';
import { Badge } from '@/components/ui/badge';
import { distanceRecords, paceRecords } from '@/data/mock';
import { duration, pace, shortDate } from '@/lib/format';

export function RecordsPanel() {
    return (
        <TrendPanel
            eyebrow="Records"
            title="Personal Bests"
            description="Every distance you have a best for, and the fastest pace you have held for a given stretch of time. Temari logs these off your synced runs, you never enter one by hand."
        >
            <div>
                <div className="mb-3 flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold text-ink">
                        By distance
                    </h3>
                    <span className="num text-xs text-ink-3">
                        {distanceRecords.length} PRs
                    </span>
                </div>
                <ul className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
                    {distanceRecords.map((r) => {
                        const gained = r.previousSec
                            ? r.previousSec - r.valueSec
                            : null;
                        return (
                            <li
                                key={r.category}
                                className="flex flex-col gap-1 rounded-(--r-tile) bg-surface-sunken p-(--pad-tile)"
                            >
                                <span className="eyebrow text-[11px] text-ink-3">
                                    {r.label}
                                </span>
                                <span className="num text-xl leading-none text-ink">
                                    {duration(r.valueSec)}
                                </span>
                                <span className="num text-xs text-ink-3">
                                    {pace(r.valueSec / (r.distanceM / 1000))}{' '}
                                    /km
                                </span>
                                <span className="mt-1 flex flex-wrap items-center gap-1.5">
                                    <span className="text-xs text-ink-3">
                                        {shortDate(r.setAt)}
                                    </span>
                                    {gained ? (
                                        <Badge
                                            variant="outline"
                                            className="border-leaf-ink/40 text-leaf-ink"
                                        >
                                            −{duration(gained)}
                                        </Badge>
                                    ) : null}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div>
                <div className="mb-3 flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold text-ink">
                        Best effort by time
                    </h3>
                    <span className="num text-xs text-ink-3">
                        {paceRecords.length} PRs
                    </span>
                </div>
                <ul className="divide-y divide-border overflow-hidden rounded-(--r-tile) bg-surface-sunken">
                    {paceRecords.map((r) => (
                        <li
                            key={r.category}
                            className="flex items-center justify-between gap-3 px-4 py-3"
                        >
                            <span className="text-sm text-ink-2">
                                {r.label}
                            </span>
                            <span className="flex items-baseline gap-3">
                                <span className="num text-sm text-ink">
                                    {pace(r.paceSec)}
                                    <span className="ml-1 text-xs font-normal text-ink-3">
                                        /km
                                    </span>
                                </span>
                                <span className="num w-14 text-right text-xs text-ink-3">
                                    {shortDate(r.setAt)}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </TrendPanel>
    );
}
