import { useMemo, useState } from 'react';
import { Line } from 'react-chartjs-2';

import { StatTile } from '@/components/StatTile';
import { TrendPanel } from '@/components/TrendPanel';
import { baseOptions, crosshair, scales } from '@/components/charts/setup';
import { SegmentedControl } from '@/components/SegmentedControl';
import { useCountUp } from '@/hooks/useCountUp';
import {
    currentVdot,
    vdotByRecord,
    vdotLimiter,
    vdotTrend,
    withinRange,
    type RangeKey,
} from '@/data/mock';
import { num, shortDate, signed } from '@/lib/format';
import { SERIES } from '@/lib/palette';

const READINGS = [
    { key: 'both', label: 'Both' },
    { key: 'fromRecords', label: 'From your PRs' },
    { key: 'rolling90', label: 'Rolling 90 days' },
] as const;

type Reading = (typeof READINGS)[number]['key'];

export function VdotTrend({ range }: Readonly<{ range: RangeKey }>) {
    const [reading, setReading] = useState<Reading>('rolling90');
    const rows = useMemo(() => withinRange(vdotTrend, range), [range]);

    const showRecords = reading !== 'rolling90';
    const showRolling = reading !== 'fromRecords';

    const data = useMemo(
        () => ({
            labels: rows.map((r) => shortDate(r.weekEnding)),
            datasets: [
                ...(showRecords
                    ? [
                          {
                              label: 'From your PRs',
                              data: rows.map((r) => r.fromRecords),
                              borderColor: SERIES.primary,
                              backgroundColor: `${SERIES.primaryFill}33`,
                              borderWidth: 2,
                              pointRadius: 0,
                              pointHoverRadius: 4,
                              stepped: 'after' as const,
                              fill: showRolling ? false : true,
                          },
                      ]
                    : []),
                ...(showRolling
                    ? [
                          {
                              label: 'Rolling 90 days',
                              data: rows.map((r) => r.rolling90),
                              borderColor: SERIES.reference,
                              borderWidth: 1.5,
                              borderDash: [5, 4],
                              pointRadius: 0,
                              pointHoverRadius: 4,
                              tension: 0.3,
                              spanGaps: true,
                              fill: false,
                          },
                      ]
                    : []),
            ],
        }),
        [rows, showRecords, showRolling],
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
                                : `${ctx.dataset.label}: ${num(ctx.parsed.y)}`,
                    },
                },
            },
            scales: scales({
                ticks: { color: '#60666d', padding: 8, maxTicksLimit: 5 },
            }),
        }),
        [],
    );

    const scored = rows.filter((r) => r.fromRecords !== null);
    const first = scored[0]?.fromRecords ?? currentVdot;
    const latest = scored.at(-1)?.fromRecords ?? currentVdot;
    const change = latest - first;
    const summary = `VDOT from your PRs went from ${num(first)} to ${num(latest)}.`;

    const vdotTween = useCountUp(currentVdot);
    const changeTween = useCountUp(change);

    return (
        <TrendPanel
            eyebrow="Fitness score"
            title="VDOT History"
            description="VDOT is a single running fitness number worked out from your best effort, using the Jack Daniels formula. Higher means you are holding a faster pace for the same cost."
            action={
                <SegmentedControl
                    label="VDOT reading"
                    value={reading}
                    options={READINGS}
                    onChange={setReading}
                />
            }
        >
            <div className="grid grid-cols-3 gap-2 sm:gap-3">
                <StatTile label="VDOT now" value={num(vdotTween)} tone="good" />
                <StatTile
                    label="Over this window"
                    value={signed(changeTween)}
                    hint={change > 0 ? 'Moving up' : 'Flat'}
                />
                <StatTile
                    label="Set by"
                    value={vdotLimiter}
                    hint="Your slowest-scoring PR"
                />
            </div>

            <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-ink-3">
                {showRecords ? (
                    <span className="inline-flex items-center gap-2">
                        <span
                            aria-hidden
                            className="h-0.5 w-6 rounded-full"
                            style={{ background: SERIES.primary }}
                        />
                        From your PRs
                    </span>
                ) : null}
                {showRolling ? (
                    <span className="inline-flex items-center gap-2">
                        <span
                            aria-hidden
                            className="h-0 w-6 border-t-2 border-dashed"
                            style={{ borderColor: SERIES.reference }}
                        />
                        Rolling 90 days
                    </span>
                ) : null}
            </div>

            <div
                role="img"
                aria-label={`VDOT history. ${summary}`}
                className="h-[200px] sm:h-[260px]"
            >
                <span className="sr-only">{summary}</span>
                <Line data={data} options={options} plugins={[crosshair]} />
            </div>

            <div className="rounded-(--r-tile) bg-surface-sunken p-(--pad-tile)">
                <p className="eyebrow mb-3 text-[11px] text-ink-3">
                    What each PR scores
                </p>
                <ul className="flex flex-wrap gap-x-5 gap-y-2">
                    {vdotByRecord.map((r) => (
                        <li
                            key={r.label}
                            className="flex items-baseline gap-1.5"
                        >
                            <span className="text-xs text-ink-3">
                                {r.label}
                            </span>
                            <span
                                className={
                                    r.vdot === currentVdot
                                        ? 'num text-sm text-leaf-ink'
                                        : 'num text-sm text-ink-2'
                                }
                            >
                                {num(r.vdot)}
                            </span>
                        </li>
                    ))}
                </ul>
                <p className="mt-3 text-xs text-ink-3">
                    Temari keeps the slowest of these, so a prescribed pace
                    never outruns a real result. Right now that is your{' '}
                    {vdotLimiter}.
                </p>
            </div>
        </TrendPanel>
    );
}
