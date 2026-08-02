import type { DailyRow } from '@/pages/AiUsage/types';

import Card from '@/components/ui/Card';
import {
    fmt,
    formatCost,
    formatDayLabel,
    formatDayLabelShort,
} from '@/pages/AiUsage/helpers';

export default function DailyChart({
    data,
    currency,
}: Readonly<{ data: DailyRow[]; currency: string }>) {
    const maxTotal = Math.max(...data.map((d) => d.total), 1);
    const totalCost = data.reduce((sum, d) => sum + d.cost, 0);

    return (
        <Card tone="cream" padding="md" className="mt-4 bg-surface-elev">
            <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-small text-ink-3">
                    {data.length} hari
                </span>
                <span className="text-sm text-ink-2">
                    Estimasi biaya:{' '}
                    <span className="font-semibold text-ink">
                        {formatCost(totalCost, currency)}
                    </span>
                </span>
            </div>

            <div className="flex gap-1.5" style={{ height: 180 }}>
                {data.map((d) => {
                    const heightPct = Math.max((d.total / maxTotal) * 100, 2);

                    return (
                        <div
                            key={d.day}
                            className="group relative flex flex-1 flex-col items-center justify-end"
                            style={{ minWidth: 0 }}
                        >
                            {/* Tooltip */}
                            <div className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg border border-line bg-surface-elev px-3 py-2 text-xs opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
                                <div className="font-semibold text-ink">
                                    {formatDayLabel(d.day)}
                                </div>
                                <div className="text-ink-2">
                                    {fmt(d.total)} token
                                </div>
                                <div className="text-ink-3">{d.calls} call</div>
                                <div className="text-ink-3">
                                    {formatCost(d.cost, currency)}
                                </div>
                            </div>

                            {/* Bar */}
                            <div
                                className="w-full rounded-t-sm bg-horizon transition-colors group-hover:bg-horizon-deep"
                                style={{ height: `${heightPct}%` }}
                                aria-label={`${formatDayLabel(d.day)}: ${fmt(d.total)} token`}
                            />
                        </div>
                    );
                })}
            </div>

            {/* X-axis labels */}
            <div className="mt-2 flex gap-1.5">
                {data.map((d) => (
                    <div key={d.day} className="flex-1 text-center">
                        {data.length <= 14 ? (
                            <span className="text-meta">
                                {formatDayLabelShort(d.day)}
                            </span>
                        ) : (
                            <span
                                className="text-meta block truncate"
                                title={formatDayLabel(d.day)}
                            >
                                {formatDayLabel(d.day)}
                            </span>
                        )}
                    </div>
                ))}
            </div>
        </Card>
    );
}
