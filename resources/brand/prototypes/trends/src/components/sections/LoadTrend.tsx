import type { Plugin } from 'chart.js';
import { useMemo } from 'react';
import { Line } from 'react-chartjs-2';

import { StatTile } from '@/components/StatTile';
import { TrendPanel } from '@/components/TrendPanel';
import { baseOptions, crosshair, scales } from '@/components/charts/setup';
import { useCountUp } from '@/hooks/useCountUp';
import { weeklyLoad, withinRange, type RangeKey } from '@/data/mock';
import { num, shortDate } from '@/lib/format';
import { PEWTER, SERIES, STATUS, type StatusKey } from '@/lib/palette';

/**
 * Strain (hundreds) and monotony (0 to 5) are different scales, so they get two
 * charts rather than two y axes on one.
 */
interface Threshold {
    at: number;
    label: string;
}

function thresholdRule(thresholds: ReadonlyArray<Threshold>): Plugin<'line'> {
    return {
        id: 'thresholds',
        beforeDatasetsDraw(chart) {
            const { ctx, chartArea, scales: s } = chart;
            ctx.save();
            for (const t of thresholds) {
                if (t.at > s.y.max || t.at < s.y.min) continue;
                const y = s.y.getPixelForValue(t.at);
                ctx.beginPath();
                ctx.setLineDash([2, 4]);
                ctx.strokeStyle = `${PEWTER.ink3}66`;
                ctx.lineWidth = 1;
                ctx.moveTo(chartArea.left, y);
                ctx.lineTo(chartArea.right, y);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.font = '10px system-ui';
                ctx.fillStyle = `${PEWTER.ink3}c0`;
                ctx.textAlign = 'right';
                ctx.textBaseline = 'bottom';
                ctx.fillText(t.label, chartArea.right - 4, y - 3);
            }
            ctx.restore();
        },
    };
}

const strainRule = thresholdRule([
    { at: 250, label: 'light' },
    { at: 500, label: 'heavy' },
]);
const monotonyRule = thresholdRule([
    { at: 1.5, label: 'healthy' },
    { at: 2, label: 'monotonous' },
]);

function strainTone(v: number): StatusKey {
    return v < 250 ? 'good' : v < 500 ? 'watch' : 'high';
}

function monotonyTone(v: number): StatusKey {
    return v < 1.5 ? 'good' : v < 2 ? 'watch' : 'high';
}

const TONE_WORD: Record<StatusKey, string> = {
    good: 'Healthy',
    watch: 'Worth watching',
    high: 'High',
};

function MiniChart({
    title,
    gloss,
    labels,
    values,
    rule,
    color,
    suggestedMax,
    format,
}: Readonly<{
    title: string;
    gloss: string;
    labels: ReadonlyArray<string>;
    values: ReadonlyArray<number | null>;
    rule: Plugin<'line'>;
    color: string;
    suggestedMax: number;
    format: (v: number) => string;
}>) {
    const data = {
        labels: [...labels],
        datasets: [
            {
                label: title,
                data: [...values],
                borderColor: color,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 5,
                tension: 0.3,
                spanGaps: true,
                fill: false,
            },
        ],
    };

    const options = {
        ...baseOptions,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: (ctx: { parsed: { y: number | null } }) =>
                        ctx.parsed.y === null
                            ? ''
                            : `${title}: ${format(ctx.parsed.y)}`,
                },
            },
        },
        scales: scales({
            suggestedMin: 0,
            suggestedMax,
            ticks: { color: '#60666d', padding: 8, maxTicksLimit: 4 },
        }),
    };

    const scored = values.filter((v): v is number => v !== null);
    const summary =
        scored.length > 0
            ? `${title} from ${format(scored[0])} to ${format(scored.at(-1)!)}, peaking at ${format(Math.max(...scored))}.`
            : `No ${title.toLowerCase()} to report in this window.`;

    return (
        <div className="flex flex-col gap-2">
            <div>
                <h3 className="text-sm font-semibold text-ink">{title}</h3>
                <p className="text-xs text-ink-3">{gloss}</p>
            </div>
            <div
                role="img"
                aria-label={`${title} by week. ${summary}`}
                className="h-[170px] sm:h-[200px]"
            >
                <span className="sr-only">{summary}</span>
                <Line
                    data={data}
                    options={options}
                    plugins={[rule, crosshair]}
                />
            </div>
        </div>
    );
}

export function LoadTrend({ range }: Readonly<{ range: RangeKey }>) {
    const rows = useMemo(() => withinRange(weeklyLoad, range), [range]);
    const labels = rows.map((r) => shortDate(r.weekEnding));

    const strain = rows.map((r) => (r.weekly ? r.strain : null));
    // A week with no running has no monotony to report, so it is a gap, not a zero.
    const monotony = rows.map((r) => (r.weekly ? r.monotony : null));

    const lastStrain = [...strain].reverse().find((v) => v !== null) ?? 0;
    const lastMonotony = [...monotony].reverse().find((v) => v !== null) ?? 0;
    const peakStrain = Math.max(...strain.map((v) => v ?? 0));

    const strainTween = useCountUp(lastStrain);
    const monotonyTween = useCountUp(lastMonotony);
    const peakStrainTween = useCountUp(peakStrain);

    return (
        <TrendPanel
            eyebrow="Load quality"
            title="Strain and Monotony"
            description="Monotony is how same-y your week looked, strain is that sameness multiplied by how much you did. A hard week is fine. A hard week where every day looked identical is the one that bites."
        >
            <div className="grid grid-cols-3 gap-2 sm:gap-3">
                <StatTile
                    label="Strain"
                    value={num(strainTween, 0)}
                    hint={TONE_WORD[strainTone(lastStrain)]}
                    tone={strainTone(lastStrain)}
                />
                <StatTile
                    label="Monotony"
                    value={monotonyTween.toFixed(2)}
                    hint={TONE_WORD[monotonyTone(lastMonotony)]}
                    tone={monotonyTone(lastMonotony)}
                />
                <StatTile
                    label="Peak strain"
                    value={num(peakStrainTween, 0)}
                    hint="In this window"
                />
            </div>

            <div className="grid gap-6 sm:grid-cols-2">
                <MiniChart
                    title="Strain"
                    gloss="Weekly load times monotony."
                    labels={labels}
                    values={strain}
                    rule={strainRule}
                    color={SERIES.primary}
                    suggestedMax={700}
                    format={(v) => num(v, 0)}
                />
                <MiniChart
                    title="Monotony"
                    gloss="Weekly mean load divided by its spread."
                    labels={labels}
                    values={monotony}
                    rule={monotonyRule}
                    color={SERIES.reference}
                    suggestedMax={2.6}
                    format={(v) => v.toFixed(2)}
                />
            </div>

            <p className="text-xs text-ink-3">
                <span
                    className="font-semibold"
                    style={{ color: STATUS[monotonyTone(lastMonotony)] }}
                >
                    {TONE_WORD[monotonyTone(lastMonotony)]}.
                </span>{' '}
                Gaps in these lines are weeks you did not run at all, which have
                no monotony to report rather than a monotony of zero.
            </p>
        </TrendPanel>
    );
}
