import type { Chart } from 'chart.js';
import { useMemo, useState } from 'react';
import { Line } from 'react-chartjs-2';

import { StatTile } from '@/components/StatTile';
import { TrendPanel } from '@/components/TrendPanel';
import { baseOptions, crosshair, scales } from '@/components/charts/setup';
import { SegmentedControl } from '@/components/SegmentedControl';
import { useCountUp } from '@/hooks/useCountUp';
import { distanceRecords, progressions } from '@/data/mock';
import { duration, monthYear, pace } from '@/lib/format';
import { SERIES } from '@/lib/palette';

export function ProgressionTrend() {
    const [category, setCategory] = useState('5km');
    const series = progressions.find((p) => p.category === category)!;
    const record = distanceRecords.find((r) => r.category === category)!;

    const points = series.points;
    const defined = points.filter((p) => p.timeSec !== null);
    const firstSec = defined[0]?.timeSec ?? null;
    const bestSec = Math.min(...defined.map((p) => p.timeSec!));
    const gained = firstSec !== null ? firstSec - bestSec : 0;

    const bestTween = useCountUp(record.valueSec);
    const gainedTween = useCountUp(gained);
    const goalTween = useCountUp(series.goalSec ?? 0);

    const data = useMemo(
        () => ({
            labels: points.map((p) => monthYear(p.date)),
            datasets: [
                {
                    label: 'Best time',
                    data: points.map((p) =>
                        p.timeSec === null ? null : p.timeSec / 60,
                    ),
                    borderColor: SERIES.primary,
                    borderWidth: 2,
                    pointRadius: points.map((p) =>
                        p.timeSec === bestSec ? 7 : 4,
                    ),
                    pointHoverRadius: 8,
                    pointBackgroundColor: points.map((p) =>
                        p.timeSec === bestSec
                            ? SERIES.primaryFill
                            : SERIES.primary,
                    ),
                    // A 2px surface ring keeps overlapping marks separated.
                    pointBorderColor: '#f1f5f8',
                    pointBorderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    spanGaps: true,
                    backgroundColor: (ctx: { chart: Chart }) => {
                        const { chartArea, ctx: c } = ctx.chart;
                        if (!chartArea) return `${SERIES.primaryFill}40`;
                        const g = c.createLinearGradient(
                            0,
                            chartArea.top,
                            0,
                            chartArea.bottom,
                        );
                        g.addColorStop(0, `${SERIES.primaryFill}0a`);
                        g.addColorStop(1, `${SERIES.primaryFill}59`);
                        return g;
                    },
                },
                ...(series.goalSec
                    ? [
                          {
                              label: 'Goal',
                              data: points.map(() => series.goalSec! / 60),
                              borderColor: SERIES.reference,
                              borderWidth: 1.5,
                              borderDash: [5, 4],
                              pointRadius: 0,
                              pointHoverRadius: 0,
                              tension: 0,
                              fill: false,
                          },
                      ]
                    : []),
            ],
        }),
        [points, bestSec, series.goalSec],
    );

    const options = useMemo(
        () => ({
            ...baseOptions,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx: {
                            dataset: { label?: string };
                            parsed: { y: number | null };
                        }) =>
                            ctx.parsed.y === null
                                ? ''
                                : `${ctx.dataset.label}: ${duration(ctx.parsed.y * 60)}`,
                    },
                },
            },
            scales: scales({
                // Reversed so a faster time sits higher, the same convention the
                // shipped ProgressionChart already uses.
                reverse: true,
                ticks: {
                    color: '#60666d',
                    padding: 8,
                    maxTicksLimit: 5,
                    callback: (v: string | number) => duration(Number(v) * 60),
                },
            }),
        }),
        [],
    );

    const summary =
        firstSec !== null
            ? `Best ${series.label} went from ${duration(firstSec)} to ${duration(bestSec)}.`
            : `Not enough ${series.label} runs yet.`;

    return (
        <TrendPanel
            eyebrow="Progression"
            title="Best Time by Distance"
            description="Your fastest effort at each distance, month by month. A gap means you did not race that distance that month, not that you got slower."
            action={
                <SegmentedControl
                    label="Distance"
                    value={category}
                    options={progressions.map((p) => ({
                        key: p.category,
                        label: p.label,
                    }))}
                    onChange={setCategory}
                />
            }
        >
            <div className="grid grid-cols-3 gap-2 sm:gap-3">
                <StatTile
                    label="Personal best"
                    value={duration(bestTween)}
                    hint={monthYear(record.setAt)}
                    tone="good"
                />
                <StatTile
                    label="Took off"
                    value={duration(gainedTween)}
                    hint="Since a year ago"
                />
                <StatTile
                    label="Goal"
                    value={series.goalSec ? duration(goalTween) : '—'}
                    hint={
                        series.goalSec
                            ? `${duration(record.valueSec - series.goalSec)} to go`
                            : 'No goal set'
                    }
                />
            </div>

            <div
                role="img"
                aria-label={`Best time progression for ${series.label}. ${summary}`}
                className="h-[220px] sm:h-[280px]"
            >
                <span className="sr-only">{summary}</span>
                <Line data={data} options={options} plugins={[crosshair]} />
            </div>

            <p className="text-xs text-ink-3">
                Personal best set on {monthYear(record.setAt)}, {record.runName}
                . That is {pace(record.valueSec / (record.distanceM / 1000))}{' '}
                per km.
            </p>
        </TrendPanel>
    );
}
