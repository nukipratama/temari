import type { CSSProperties } from 'react';

import { formatDurationHMS } from '@/lib/pace';

interface ProjectionRangeBarProps {
    goalSec: number;
    lowSec: number;
    predictedSec: number;
    highSec: number;
}

const AXIS_PAD_RATIO = 0.1;

/** Anchored by the same share of its own width, so a label at either end stays inside the bar. */
function labelAt(percent: number): CSSProperties {
    return { left: `${percent}%`, transform: `translateX(-${percent}%)` };
}

/** The goal against the projected range, on a straight time axis with faster finishes on the left. */
export default function ProjectionRangeBar({
    goalSec,
    lowSec,
    predictedSec,
    highSec,
}: Readonly<ProjectionRangeBarProps>) {
    const min = Math.min(goalSec, lowSec);
    const max = Math.max(goalSec, highSec);
    const pad = (max - min || 1) * AXIS_PAD_RATIO;
    const start = min - pad;
    const span = max + pad - start;
    const at = (sec: number) => ((sec - start) / span) * 100;

    const goal = at(goalSec);
    const low = at(lowSec);
    const high = at(highSec);

    return (
        <div
            role="img"
            aria-label={`goal ${formatDurationHMS(goalSec)}, projected ${formatDurationHMS(lowSec)} to ${formatDurationHMS(highSec)}, best estimate ${formatDurationHMS(predictedSec)}`}
            className="relative font-mono text-xs tabular-nums"
        >
            <div className="relative h-4">
                <span
                    className="absolute top-0 font-semibold whitespace-nowrap text-foreground"
                    style={labelAt(goal)}
                >
                    goal {formatDurationHMS(goalSec)}
                </span>
            </div>
            <div className="relative mt-1.5 h-2 rounded-full bg-muted">
                <span
                    data-marker="range"
                    className="absolute inset-y-0 rounded-full bg-icon-accent"
                    style={{ left: `${low}%`, width: `${high - low}%` }}
                />
                <span
                    data-marker="estimate"
                    className="absolute top-1/2 size-3.5 -translate-1/2 rounded-full border-[3px] border-icon-accent bg-card"
                    style={{ left: `${at(predictedSec)}%` }}
                />
                <span
                    data-marker="goal"
                    className="absolute -top-2 -bottom-1 w-0.5 -translate-x-1/2 rounded-full bg-foreground"
                    style={{ left: `${goal}%` }}
                />
            </div>
            <div className="relative mt-1.5 h-4 text-text-2">
                <span
                    className="absolute top-0 whitespace-nowrap"
                    style={labelAt(low)}
                >
                    {formatDurationHMS(lowSec)}
                </span>
                <span
                    className="absolute top-0 whitespace-nowrap"
                    style={labelAt(high)}
                >
                    {formatDurationHMS(highSec)}
                </span>
            </div>
        </div>
    );
}
