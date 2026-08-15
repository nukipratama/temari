import type { Chart, ChartEvent, Plugin } from 'chart.js';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Line } from 'react-chartjs-2';

import { StatTile } from '@/components/StatTile';
import { TrendPanel } from '@/components/TrendPanel';
import { baseOptions, crosshair, scales } from '@/components/charts/setup';
import {
    fitnessTrend,
    milestones,
    withinRange,
    type RangeKey,
} from '@/data/mock';
import { num, shortDate, signed } from '@/lib/format';
import { PEWTER, SERIES } from '@/lib/palette';
import { cn } from '@/lib/utils';

function fitnessHint(ctl: number): string {
    if (ctl < 25) return 'Still building';
    if (ctl < 50) return 'Trending up';
    if (ctl < 80) return 'Stable';
    return 'High';
}

function fatigueHint(atl: number): string {
    if (atl < 25) return 'Fresh';
    if (atl < 55) return 'Normal';
    if (atl < 85) return 'Tired';
    return 'Heavy';
}

export function FitnessTrend({ range }: Readonly<{ range: RangeKey }>) {
    const [selected, setSelected] = useState<string | null>(null);

    const rows = useMemo(() => withinRange(fitnessTrend, range), [range]);
    const marks = useMemo(() => {
        const index = new Map(rows.map((r, i) => [r.date, i]));
        return milestones
            .filter((m) => index.has(m.date))
            .map((m) => ({ ...m, index: index.get(m.date)! }));
    }, [rows]);

    const active = marks.find((m) => m.key === selected) ?? null;

    /**
     * react-chartjs-2 registers the `plugins` prop once, at chart creation, so a
     * plugin that closes over state keeps drawing the state it was born with —
     * which had the milestone layer painting the 12-month badges onto the 90-day
     * scale. The plugin is therefore created once and reads live values through
     * refs, and selection changes ask the chart to repaint explicitly.
     */
    const marksRef = useRef(marks);
    const selectedRef = useRef(selected);
    marksRef.current = marks;
    selectedRef.current = selected;

    const chartRef = useRef<Chart<'line'> | null>(null);
    useEffect(() => {
        chartRef.current?.update('none');
    }, [marks, selected]);

    /**
     * Badges earned days apart land on the same pixel on a phone at 12 months,
     * so markers within a marker's width of each other collapse into one that
     * carries a count. The chips below stay the precise control.
     */
    const cluster = useCallback((xFor: (index: number) => number) => {
        const groups: Array<{ x: number; members: typeof marksRef.current }> =
            [];
        for (const mark of marksRef.current) {
            const x = xFor(mark.index);
            const last = groups.at(-1);
            if (last && x - last.x < 22) last.members.push(mark);
            else groups.push({ x, members: [mark] });
        }
        return groups;
    }, []);

    const milestonePlugin = useMemo<Plugin<'line'>>(
        () => ({
            id: 'milestones',
            afterDatasetsDraw(chart) {
                const { ctx, chartArea, scales: s } = chart;
                if (marksRef.current.length === 0) return;
                ctx.save();
                for (const group of cluster((i) => s.x.getPixelForValue(i))) {
                    const { x, members } = group;
                    const isActive = members.some(
                        (m) => m.key === selectedRef.current,
                    );
                    ctx.beginPath();
                    ctx.setLineDash(isActive ? [] : [2, 4]);
                    ctx.strokeStyle = isActive
                        ? PEWTER.horizonInk
                        : `${PEWTER.ink3}59`;
                    ctx.lineWidth = 1;
                    ctx.moveTo(x, chartArea.top + 20);
                    ctx.lineTo(x, chartArea.bottom);
                    ctx.stroke();

                    ctx.setLineDash([]);
                    ctx.beginPath();
                    ctx.arc(
                        x,
                        chartArea.top + 10,
                        isActive ? 11 : 9,
                        0,
                        Math.PI * 2,
                    );
                    ctx.fillStyle = isActive
                        ? PEWTER.horizon
                        : PEWTER.surfaceElev;
                    ctx.fill();
                    ctx.lineWidth = isActive ? 2 : 1;
                    ctx.strokeStyle = isActive
                        ? PEWTER.horizonInk
                        : PEWTER.line;
                    ctx.stroke();

                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    if (members.length === 1) {
                        ctx.font = `${isActive ? 13 : 11}px system-ui`;
                        ctx.fillText(members[0].emoji, x, chartArea.top + 11);
                    } else {
                        ctx.font = '600 11px system-ui';
                        ctx.fillStyle = PEWTER.ink2;
                        ctx.fillText(
                            `${members.length}`,
                            x,
                            chartArea.top + 11,
                        );
                    }
                }
                ctx.restore();
            },
        }),
        [cluster],
    );

    const data = useMemo(
        () => ({
            labels: rows.map((r) => shortDate(r.date)),
            datasets: [
                {
                    label: 'Fitness',
                    data: rows.map((r) => r.ctl),
                    borderColor: SERIES.primary,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.3,
                    fill: true,
                    backgroundColor: (ctx: { chart: Chart }) => {
                        const { chartArea, ctx: c } = ctx.chart;
                        if (!chartArea) return `${SERIES.primaryFill}40`;
                        const g = c.createLinearGradient(
                            0,
                            chartArea.top,
                            0,
                            chartArea.bottom,
                        );
                        g.addColorStop(0, `${SERIES.primaryFill}66`);
                        g.addColorStop(1, `${SERIES.primaryFill}0a`);
                        return g;
                    },
                },
                {
                    label: 'Fatigue',
                    data: rows.map((r) => r.atl),
                    borderColor: SERIES.reference,
                    borderWidth: 1.5,
                    borderDash: [5, 4],
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.3,
                    fill: false,
                },
            ],
        }),
        [rows],
    );

    const options = useMemo(
        () => ({
            ...baseOptions,
            layout: { padding: { top: 24 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items: Array<{ dataIndex: number }>) => {
                            const row = rows[items[0]?.dataIndex ?? 0];
                            const mark = marks.find(
                                (m) => m.date === row?.date,
                            );
                            return mark
                                ? `${shortDate(row.date)} · ${mark.emoji} ${mark.name}`
                                : shortDate(row?.date ?? '');
                        },
                    },
                },
            },
            onClick: (_e: ChartEvent, _els: unknown, chart: Chart) => {
                const px = chart.tooltip?.caretX;
                if (px == null) return;
                const near = cluster((i) => chart.scales.x.getPixelForValue(i))
                    .map((g) => ({ g, d: Math.abs(g.x - px) }))
                    .sort((a, b) => a.d - b.d)[0];
                if (!near || near.d >= 18) return;
                // Repeat taps step through a cluster rather than sticking on its first badge.
                setSelected((cur) => {
                    const keys = near.g.members.map((m) => m.key);
                    const at = cur === null ? -1 : keys.indexOf(cur);
                    return at === keys.length - 1 ? null : keys[at + 1];
                });
            },
            scales: scales(),
        }),
        [rows, marks],
    );

    const latest = rows[rows.length - 1];
    const first = rows[0];
    const summary = `Fitness ${num(first.ctl)} to ${num(latest.ctl)} over ${rows.length} days, fatigue now ${num(latest.atl)}.`;

    return (
        <TrendPanel
            eyebrow="Load"
            title="Fitness and Fatigue"
            description="Fitness is your training load averaged over a long window, fatigue over a short one. When the fitness line climbs and the fatigue line sits under it, the work is sticking."
        >
            <div className="grid grid-cols-3 gap-2 sm:gap-3">
                <StatTile
                    label="Fitness"
                    value={num(latest.ctl)}
                    unit="CTL"
                    hint={fitnessHint(latest.ctl)}
                />
                <StatTile
                    label="Fatigue"
                    value={num(latest.atl)}
                    unit="ATL"
                    hint={fatigueHint(latest.atl)}
                />
                <StatTile
                    label="Form"
                    value={signed(latest.form)}
                    hint={latest.form >= 0 ? 'Rested' : 'Carrying load'}
                    tone={latest.form >= 0 ? 'good' : 'neutral'}
                />
            </div>

            <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-ink-3">
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden
                        className="h-0.5 w-6 rounded-full"
                        style={{ background: SERIES.primary }}
                    />
                    Fitness
                </span>
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden
                        className="h-0 w-6 border-t-2 border-dashed"
                        style={{ borderColor: SERIES.reference }}
                    />
                    Fatigue
                </span>
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden
                        className="grid size-4 place-items-center rounded-full border border-line bg-surface-elev text-[9px]"
                    >
                        🏅
                    </span>
                    Badge earned
                </span>
            </div>

            <div
                role="img"
                aria-label={`Fitness and fatigue over ${rows.length} days. ${summary}`}
                className="h-[220px] sm:h-[300px]"
            >
                <span className="sr-only">{summary}</span>
                <Line
                    ref={chartRef}
                    data={data}
                    options={options}
                    plugins={[crosshair, milestonePlugin]}
                />
            </div>

            <div className="flex flex-col gap-3">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold text-ink">
                        Milestones on this stretch
                    </h3>
                    <span className="text-xs text-ink-3">
                        <span className="num">{marks.length}</span> badges
                    </span>
                </div>

                {marks.length === 0 ? (
                    <p className="text-sm text-ink-3">
                        No badges landed in this window. Widen the range to see
                        the rest of the year.
                    </p>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {marks.map((mark) => (
                            <li key={mark.key} className="shrink-0">
                                <button
                                    type="button"
                                    aria-pressed={mark.key === selected}
                                    onClick={() =>
                                        setSelected((cur) =>
                                            cur === mark.key ? null : mark.key,
                                        )
                                    }
                                    className={cn(
                                        'flex items-center gap-2 rounded-full border px-3 py-2 text-xs whitespace-nowrap transition-colors',
                                        mark.key === selected
                                            ? 'border-horizon-ink bg-horizon/25 text-ink'
                                            : 'border-line bg-surface-elev text-ink-2 hover:bg-cream-deep',
                                    )}
                                >
                                    <span aria-hidden>{mark.emoji}</span>
                                    <span className="font-semibold">
                                        {mark.name}
                                    </span>
                                    <span className="num text-ink-3">
                                        {shortDate(mark.date)}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {active ? (
                    <div className="rounded-(--r-tile) border border-horizon-ink/30 bg-horizon/12 p-(--pad-tile)">
                        <p className="text-sm font-semibold text-ink">
                            {active.emoji} {active.name}
                            <span className="num ml-2 font-normal text-ink-3">
                                {shortDate(active.date)}
                            </span>
                        </p>
                        <p className="mt-1 text-sm text-ink-2">{active.note}</p>
                        <p className="mt-1 text-xs text-ink-3">
                            {active.criterion}
                        </p>
                    </div>
                ) : (
                    <p className="text-xs text-ink-3">
                        Pick a badge to mark it on the line, or tap a marker on
                        the chart.
                    </p>
                )}
            </div>
        </TrendPanel>
    );
}
