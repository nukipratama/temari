import { useEffect, useLayoutEffect, useRef, useState } from 'react';

import EmptyPanel from '@/components/ui/EmptyPanel';
import { formatDurationHMS, formatNaiveMonthDayId } from '@/lib/pace';

const VIEW_W = 300;
const VIEW_H = 78;
const VIEW_PAD_X = 8;

interface Point {
    x: number;
    y: number;
    label: string;
    time: number;
    pr: boolean;
}

type Tip = Point;

/**
 * Best time per week for one distance, as the prototype draws it: a filled
 * polyline with a fatter marker on the PR, each point tappable for a tooltip.
 * Y is inverted — a faster time sits higher — and X is spaced by real elapsed
 * days, so an uneven gap between two attempts is not flattened into progress.
 */
export default function JourneyChart({
    weeks,
    timesSec,
}: Readonly<{
    weeks: ReadonlyArray<string>;
    timesSec: ReadonlyArray<number | null>;
}>) {
    const chartRef = useRef<HTMLDivElement>(null);
    const tipRef = useRef<HTMLDivElement>(null);
    const [tip, setTip] = useState<Tip | null>(null);

    useEffect(() => {
        function close(event: MouseEvent) {
            if (!chartRef.current?.contains(event.target as Node)) {
                setTip(null);
            }
        }
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    useLayoutEffect(() => {
        if (!tip || !tipRef.current || !chartRef.current) return;
        const halfTip = tipRef.current.offsetWidth / 2;
        const clamped = Math.min(
            Math.max(tip.x, halfTip + 4),
            chartRef.current.clientWidth - halfTip - 4,
        );
        tipRef.current.style.left = `${clamped}px`;
    }, [tip]);

    const points = buildPoints(weeks, timesSec);
    if (points.length === 0) {
        return (
            <EmptyPanel title="Not enough runs at this distance yet to draw a journey line." />
        );
    }

    function toggle(point: Point) {
        setTip((prev) =>
            prev?.label === point.label
                ? null
                : {
                      x:
                          ((point.x + VIEW_PAD_X) / (VIEW_W + VIEW_PAD_X * 2)) *
                          (chartRef.current?.clientWidth ?? VIEW_W),
                      y: (point.y / VIEW_H) * VIEW_H,
                      label: point.label,
                      time: point.time,
                      pr: point.pr,
                  },
        );
    }

    const path = points.map((p) => `${p.x},${p.y}`).join(' ');
    const summary = `From ${formatDurationHMS(points[0].time)} on ${points[0].label} to ${formatDurationHMS(points.at(-1)!.time)} on ${points.at(-1)!.label}.`;

    return (
        <div ref={chartRef} className="relative mt-3.5">
            <span className="sr-only">{`Best time journey. ${summary}`}</span>
            <svg
                viewBox={`-${VIEW_PAD_X} 0 ${VIEW_W + VIEW_PAD_X * 2} ${VIEW_H}`}
                width="100%"
                height={VIEW_H}
                preserveAspectRatio="none"
            >
                <defs>
                    <linearGradient
                        id="journeyFade"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stopColor="var(--color-horizon-ink)"
                            stopOpacity="0.28"
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--color-horizon-ink)"
                            stopOpacity="0"
                        />
                    </linearGradient>
                </defs>
                <polygon
                    points={`${path} ${VIEW_W},${VIEW_H} 0,${VIEW_H}`}
                    fill="url(#journeyFade)"
                />
                <polyline
                    points={path}
                    fill="none"
                    stroke="var(--color-horizon-ink)"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
                {points.map((point) => (
                    <g
                        key={point.label}
                        role="button"
                        tabIndex={0}
                        aria-label={`${point.label}: ${formatDurationHMS(point.time)}${point.pr ? ', personal record' : ''}`}
                        className="group cursor-pointer focus:outline-none"
                        onClick={() => toggle(point)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                toggle(point);
                            }
                        }}
                    >
                        <circle
                            cx={point.x}
                            cy={point.y}
                            r="10"
                            fill="transparent"
                        />
                        <circle
                            cx={point.x}
                            cy={point.y}
                            r={point.pr ? 5 : 2.5}
                            fill={
                                point.pr
                                    ? 'var(--color-horizon)'
                                    : 'var(--color-horizon-ink)'
                            }
                            stroke={point.pr ? 'var(--color-card)' : undefined}
                            strokeWidth={point.pr ? 2 : undefined}
                            className="origin-center transition-transform [transform-box:fill-box] group-hover:scale-150 group-focus:scale-150"
                        />
                    </g>
                ))}
            </svg>
            {tip && (
                <div
                    ref={tipRef}
                    className="pointer-events-none absolute -translate-x-1/2 -translate-y-[130%] rounded-sm bg-sky px-2 py-1 font-mono text-[0.625rem] font-bold whitespace-nowrap text-cream shadow-e2"
                    style={{ left: tip.x, top: tip.y }}
                >
                    {`${tip.label}${tip.pr ? ' · PR' : ''} · ${formatDurationHMS(tip.time)}`}
                </div>
            )}
        </div>
    );
}

/**
 * Weeks with a recorded time, projected into the viewBox. A single point sits
 * mid-rail rather than dividing by a zero span.
 */
function buildPoints(
    weeks: ReadonlyArray<string>,
    timesSec: ReadonlyArray<number | null>,
): Point[] {
    const recorded = weeks
        .map((week, index) => ({ week, time: timesSec[index] }))
        .filter(
            (row): row is { week: string; time: number } => row.time != null,
        );

    if (recorded.length === 0) return [];

    const days = recorded.map((row) => Date.parse(row.week) / 86_400_000);
    const daySpan = days.at(-1)! - days[0];
    const times = recorded.map((row) => row.time);
    const slowest = Math.max(...times);
    const fastest = Math.min(...times);
    const timeSpan = slowest - fastest;
    const best = recorded.reduce((a, b) => (b.time < a.time ? b : a));

    return recorded.map((row, index) => ({
        x:
            daySpan === 0
                ? VIEW_W / 2
                : ((days[index] - days[0]) / daySpan) * VIEW_W,
        // 8px of headroom top and bottom so a marker never clips the viewBox.
        y:
            timeSpan === 0
                ? VIEW_H / 2
                : VIEW_H -
                  8 -
                  ((slowest - row.time) / timeSpan) * (VIEW_H - 16),
        label: formatNaiveMonthDayId(row.week),
        time: row.time,
        pr: row.week === best.week,
    }));
}
