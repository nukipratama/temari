import { useMemo } from 'react';

import MetricExplainer from '@/components/MetricExplainer';
import Eyebrow from '@/components/ui/Eyebrow';
import { ctlDaysAgo, ctlNow, ctlPeak } from '@/lib/trends';

import FitnessPanel, {
    type FitnessChartAnnotations,
    type FitnessTrendPoint,
} from './panels/FitnessPanel';
import { Stat, StatDelta } from './Stat';

const CTL_MEANING =
    "long-term load builds slowly from six weeks of running. it counts running only, so it tracks how much you've run, not fitness.";
const DAYS_AGO = 30;
const CLIMB_THRESHOLD = 2;

function trendWord(delta: number): string {
    if (delta >= CLIMB_THRESHOLD) return 'climbing';
    if (delta <= -CLIMB_THRESHOLD) return 'falling';
    return 'flat';
}

interface MonthComparisonProps {
    trend: ReadonlyArray<FitnessTrendPoint>;
    annotations?: FitnessChartAnnotations;
    className?: string;
}

/**
 * "long-term load" owns the CTL chart: load-now and a-month-ago are
 * read off the same 365-day series the chart plots, so the card and the
 * line can never disagree (direction A, #967). Long-term load now is the
 * section's hero number; best-this-year is a plain secondary reading below
 * it, not paired side-by-side with anything, so it stays a Stat rather than
 * a tile (MASTER.md's tile rule only fires once two numbers sit side by
 * side).
 */
export default function MonthComparison({
    trend,
    annotations,
    className,
}: Readonly<MonthComparisonProps>) {
    const now = useMemo(() => ctlNow(trend), [trend]);
    const monthAgo = useMemo(() => ctlDaysAgo(trend, DAYS_AGO), [trend]);
    const peak = useMemo(() => ctlPeak(trend), [trend]);
    const delta = now !== null && monthAgo !== null ? now - monthAgo : null;

    return (
        <section className={className}>
            <Eyebrow as="h2" token="small" tone="ink-2">
                long-term load
            </Eyebrow>
            <p className="mt-1 text-xs text-text-3">{CTL_MEANING}</p>
            <div className="mt-3">
                <FitnessPanel
                    trend={trend}
                    annotations={annotations}
                    highlightDays={DAYS_AGO}
                />
            </div>
            <hr className="my-4 border-dashed border-border" />
            <Stat
                label={
                    <>
                        long-term load now
                        <MetricExplainer metricKey="ctl" size="xs" />
                    </>
                }
                value={now !== null ? now.toFixed(1) : '—'}
                delta={delta !== null ? <StatDelta value={delta} /> : undefined}
                sub={
                    monthAgo !== null
                        ? `${monthAgo.toFixed(1)} a month ago · ${trendWord(delta ?? 0)}`
                        : undefined
                }
            />
            <Stat
                className="mt-3"
                size="sm"
                label="best this year"
                value={peak !== null ? peak.toFixed(1) : '—'}
                delta={
                    now !== null && peak !== null ? (
                        <StatDelta value={now - peak} />
                    ) : undefined
                }
                sub={
                    now !== null && peak !== null
                        ? now >= peak
                            ? 'today is the high point'
                            : 'where today sits against it'
                        : undefined
                }
            />
        </section>
    );
}
