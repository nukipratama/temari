import { Link } from '@inertiajs/react';
import {
    Fragment,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type KeyboardEvent as ReactKeyboardEvent,
    type PointerEvent as ReactPointerEvent,
} from 'react';

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
    activityId: number | null;
}

/**
 * Best time per week for one distance, as the prototype draws it: a filled
 * polyline with a fatter marker on the PR. Y is inverted — a faster time
 * sits higher — and X is spaced by real elapsed days, so an uneven gap
 * between two attempts is not flattened into progress.
 *
 * A pointer drag (touch) or hover (mouse) scrubs a readout across the line,
 * snapping to the nearest week; arrow keys move between weeks from the
 * keyboard. `touch-pan-y` keeps vertical page scroll working on mobile —
 * only a horizontal-ish gesture is claimed as a scrub.
 */
export default function JourneyChart({
    weeks,
    timesSec,
    activityIds = [],
}: Readonly<{
    weeks: ReadonlyArray<string>;
    timesSec: ReadonlyArray<number | null>;
    activityIds?: ReadonlyArray<number | null>;
}>) {
    const chartRef = useRef<HTMLDivElement>(null);
    const tipRef = useRef<HTMLDivElement>(null);
    const draggingRef = useRef(false);
    const [selectedIndex, setSelectedIndex] = useState<number | null>(null);

    const points = buildPoints(weeks, timesSec, activityIds);

    useEffect(() => {
        function close(event: MouseEvent) {
            if (!chartRef.current?.contains(event.target as Node)) {
                setSelectedIndex(null);
            }
        }
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    useLayoutEffect(() => {
        if (selectedIndex === null || !tipRef.current || !chartRef.current) {
            return;
        }
        const point = points[selectedIndex];
        const halfTip = tipRef.current.offsetWidth / 2;
        const containerWidth = chartRef.current.clientWidth || VIEW_W;
        const rawX =
            ((point.x + VIEW_PAD_X) / (VIEW_W + VIEW_PAD_X * 2)) *
            containerWidth;
        const clamped = Math.min(
            Math.max(rawX, halfTip + 4),
            containerWidth - halfTip - 4,
        );
        tipRef.current.style.left = `${clamped}px`;
    }, [selectedIndex, points]);

    if (points.length === 0) {
        return (
            <EmptyPanel title="not enough runs at this distance yet to draw a journey line." />
        );
    }

    function nearestIndexFromClientX(clientX: number): number {
        const rect = chartRef.current?.getBoundingClientRect();
        const width = rect?.width || VIEW_W;
        const left = rect?.left ?? 0;
        const ratio = (clientX - left) / width;
        const viewX = ratio * (VIEW_W + VIEW_PAD_X * 2) - VIEW_PAD_X;

        let closest = 0;
        let closestDistance = Infinity;
        points.forEach((point, index) => {
            const distance = Math.abs(point.x - viewX);
            if (distance < closestDistance) {
                closestDistance = distance;
                closest = index;
            }
        });
        return closest;
    }

    function handlePointerDown(event: ReactPointerEvent<HTMLDivElement>) {
        // Pointer capture retargets the click that follows to the capturing
        // element, which would swallow a tap on the readout's "open run"
        // link. Let a press starting on the link through untouched.
        if ((event.target as HTMLElement).closest('a')) {
            return;
        }
        event.currentTarget.setPointerCapture?.(event.pointerId);
        draggingRef.current = true;
        setSelectedIndex(nearestIndexFromClientX(event.clientX));
    }

    function handlePointerMove(event: ReactPointerEvent<HTMLDivElement>) {
        if (event.pointerType !== 'mouse' && !draggingRef.current) {
            return;
        }
        setSelectedIndex(nearestIndexFromClientX(event.clientX));
    }

    function endDrag() {
        draggingRef.current = false;
    }

    function handleKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
        if (event.key === 'Escape') {
            setSelectedIndex(null);
            return;
        }
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }
        event.preventDefault();
        setSelectedIndex((prev) => {
            const base = prev ?? points.length - 1;
            const next = event.key === 'ArrowLeft' ? base - 1 : base + 1;
            return Math.min(Math.max(next, 0), points.length - 1);
        });
    }

    const path = points.map((p) => `${p.x},${p.y}`).join(' ');
    const summary = `from ${formatDurationHMS(points[0].time)} on ${points[0].label} to ${formatDurationHMS(points.at(-1)!.time)} on ${points.at(-1)!.label}.`;
    const selected = selectedIndex !== null ? points[selectedIndex] : null;

    return (
        <div
            ref={chartRef}
            className="focus-ring relative mt-3.5 touch-pan-y"
            tabIndex={0}
            role="slider"
            aria-label="Best time journey. Drag, hover or use the arrow keys to scrub between weeks."
            aria-valuemin={0}
            aria-valuemax={points.length - 1}
            aria-valuenow={selectedIndex ?? points.length - 1}
            aria-valuetext={
                selected
                    ? `${selected.label}: ${formatDurationHMS(selected.time)}${selected.pr ? ', personal record' : ''}`
                    : undefined
            }
            onPointerDown={handlePointerDown}
            onPointerMove={handlePointerMove}
            onPointerUp={endDrag}
            onPointerCancel={endDrag}
            onKeyDown={handleKeyDown}
        >
            <span className="sr-only">{`Best time journey. ${summary}`}</span>
            <svg
                viewBox={`-${VIEW_PAD_X} 0 ${VIEW_W + VIEW_PAD_X * 2} ${VIEW_H}`}
                width="100%"
                height={VIEW_H}
                preserveAspectRatio="none"
                aria-hidden
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
                {selected && (
                    <line
                        x1={selected.x}
                        x2={selected.x}
                        y1="0"
                        y2={VIEW_H}
                        stroke="var(--color-border-strong)"
                        strokeWidth="1"
                        strokeDasharray="2 2"
                    />
                )}
                {points.map((point, index) =>
                    point.pr ? (
                        <Fragment key={point.label}>
                            <Dot
                                point={point}
                                color="var(--color-card)"
                                diameter={12}
                            />
                            <Dot
                                point={point}
                                color="var(--color-horizon)"
                                diameter={8}
                            />
                        </Fragment>
                    ) : (
                        <Dot
                            key={point.label}
                            point={point}
                            color="var(--color-horizon-ink)"
                            diameter={index === selectedIndex ? 10 : 5}
                        />
                    ),
                )}
            </svg>
            {selected && (
                <div
                    ref={tipRef}
                    className="pointer-events-none absolute -translate-x-1/2 -translate-y-[130%] rounded-sm bg-sky px-2 py-1 font-mono text-[0.625rem] font-bold whitespace-nowrap text-cream shadow-e2"
                    style={{ top: selected.y }}
                >
                    <span>{`${selected.label}${selected.pr ? ' · PR' : ''} · ${formatDurationHMS(selected.time)}`}</span>
                    {selected.activityId !== null && (
                        <Link
                            href={`/activities/${selected.activityId}`}
                            className="pointer-events-auto ml-1 underline"
                        >
                            open run →
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}

function Dot({
    point,
    color,
    diameter,
}: Readonly<{ point: Point; color: string; diameter: number }>) {
    return (
        <line
            x1={point.x}
            y1={point.y}
            x2={point.x}
            y2={point.y}
            stroke={color}
            strokeWidth={diameter}
            strokeLinecap="round"
            vectorEffect="non-scaling-stroke"
        />
    );
}

/**
 * Weeks with a recorded time, projected into the viewBox. A single point sits
 * mid-rail rather than dividing by a zero span.
 */
function buildPoints(
    weeks: ReadonlyArray<string>,
    timesSec: ReadonlyArray<number | null>,
    activityIds: ReadonlyArray<number | null>,
): Point[] {
    const recorded = weeks
        .map((week, index) => ({
            week,
            time: timesSec[index],
            activityId: activityIds[index] ?? null,
        }))
        .filter(
            (
                row,
            ): row is {
                week: string;
                time: number;
                activityId: number | null;
            } => row.time != null,
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
        activityId: row.activityId,
    }));
}
