import type { Plugin } from 'chart.js';
import { useMemo } from 'react';
import { Line } from 'react-chartjs-2';

import { StatTile } from '@/components/StatTile';
import { TrendPanel } from '@/components/TrendPanel';
import { baseOptions, crosshair, scales } from '@/components/charts/setup';
import {
    CONSISTENCY_BANDS,
    consistencyTrend,
    withinRange,
    type RangeKey,
} from '@/data/mock';
import { num, shortDate } from '@/lib/format';
import { SERIES, STATUS } from '@/lib/palette';

function bandFor(sec: number) {
    return (
        CONSISTENCY_BANDS.find((b) => sec <= b.max) ?? CONSISTENCY_BANDS.at(-1)!
    );
}

const BAND_TINTS = ['#27755112', '#2775510a', '#84631414', '#b23a4f16'];

/**
 * The thresholds PaceConsistency.php already uses, drawn as grounds rather than
 * stated. The band names live in a key under the chart, not inside the plot,
 * because in the plot they sit exactly where the line ends up.
 */
const bands: Plugin<'line'> = {
    id: 'consistencyBands',
    beforeDatasetsDraw(chart) {
        const { ctx, chartArea, scales: s } = chart;
        let low = 0;
        ctx.save();
        CONSISTENCY_BANDS.forEach((band, i) => {
            const yTop = s.y.getPixelForValue(Math.min(band.max, s.y.max));
            const yBottom = s.y.getPixelForValue(Math.max(low, s.y.min));
            const top = Math.max(yTop, chartArea.top);
            const bottom = Math.min(yBottom, chartArea.bottom);
            if (bottom > top) {
                ctx.fillStyle = BAND_TINTS[i];
                ctx.fillRect(
                    chartArea.left,
                    top,
                    chartArea.right - chartArea.left,
                    bottom - top,
                );
            }
            low = band.max;
        });
        ctx.restore();
    },
};

function BandKey() {
    let low = 0;
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-ink-3">
            {CONSISTENCY_BANDS.map((band, i) => {
                const bound =
                    low === 0
                        ? `to ${band.max}s`
                        : i === CONSISTENCY_BANDS.length - 1
                          ? `over ${low}s`
                          : `${low} to ${band.max}s`;
                low = band.max;
                return (
                    <li
                        key={band.label}
                        className="inline-flex items-center gap-1.5"
                    >
                        <span
                            aria-hidden
                            className="size-2.5 rounded-[3px] border border-line"
                            style={{ background: BAND_TINTS[i] }}
                        />
                        {band.label}
                        <span className="num text-ink-3/80">{bound}</span>
                    </li>
                );
            })}
        </ul>
    );
}

export function ConsistencyTrend({ range }: Readonly<{ range: RangeKey }>) {
    const rows = useMemo(() => withinRange(consistencyTrend, range), [range]);
    const defined = rows.filter((r) => r.variabilitySec !== null);
    const latest = defined.at(-1)!;
    const first = defined[0];
    const band = bandFor(latest.variabilitySec!);

    const data = useMemo(
        () => ({
            labels: rows.map((r) => shortDate(r.weekEnding)),
            datasets: [
                {
                    label: 'Split spread',
                    data: rows.map((r) => r.variabilitySec),
                    borderColor: SERIES.primary,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    pointBackgroundColor: SERIES.primary,
                    pointBorderColor: '#f1f5f8',
                    pointBorderWidth: 2,
                    tension: 0.3,
                    spanGaps: true,
                    fill: false,
                },
            ],
        }),
        [rows],
    );

    const options = useMemo(
        () => ({
            ...baseOptions,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx: { parsed: { y: number | null } }) =>
                            ctx.parsed.y === null
                                ? ''
                                : `${num(ctx.parsed.y)} sec spread · ${bandFor(ctx.parsed.y).label.toLowerCase()}`,
                    },
                },
            },
            scales: scales({
                suggestedMin: 0,
                suggestedMax: 26,
                ticks: {
                    color: '#60666d',
                    padding: 8,
                    maxTicksLimit: 5,
                    callback: (v: string | number) => `${v}s`,
                },
            }),
        }),
        [],
    );

    const summary = `Split spread went from ${num(first.variabilitySec!)} to ${num(latest.variabilitySec!)} seconds.`;

    return (
        <TrendPanel
            eyebrow="Pacing"
            title="Pace Consistency"
            description="How far apart your kilometre splits sit inside a single run, in seconds. Lower is steadier, which usually means you judged the effort well rather than going out hot."
        >
            <div className="grid grid-cols-3 gap-2 sm:gap-3">
                <StatTile
                    label="This week"
                    value={num(latest.variabilitySec!)}
                    unit="sec"
                    hint={band.label}
                    tone={band.tone}
                />
                <StatTile
                    label="A year ago"
                    value={num(first.variabilitySec!)}
                    unit="sec"
                    hint={bandFor(first.variabilitySec!).label}
                />
                <StatTile
                    label="Steadiest week"
                    value={num(
                        Math.min(...defined.map((r) => r.variabilitySec!)),
                    )}
                    unit="sec"
                    tone="good"
                />
            </div>

            <div
                role="img"
                aria-label={`Pace consistency trend. ${summary}`}
                className="h-[200px] sm:h-[260px]"
            >
                <span className="sr-only">{summary}</span>
                <Line
                    data={data}
                    options={options}
                    plugins={[bands, crosshair]}
                />
            </div>

            <BandKey />

            <p className="text-xs" style={{ color: STATUS[band.tone] }}>
                <span className="font-semibold">{band.label}.</span>{' '}
                <span className="text-ink-3">
                    Under 8 seconds is a run you paced almost perfectly. Over 20
                    and the run had a story in it.
                </span>
            </p>
        </TrendPanel>
    );
}
