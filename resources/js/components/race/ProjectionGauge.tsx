import { useCountUp } from '@/hooks/useCountUp';
import { formatDurationHMS } from '@/lib/pace';

interface ProjectionGaugeProps {
    lowSec: number;
    predictedSec: number;
    highSec: number;
}

const CX = 70;
const CY = 70;
const R = 58;
const ARC_PATH = `M ${CX - R} ${CY} A ${R} ${R} 0 0 1 ${CX + R} ${CY}`;

function markerPosition(ratio: number) {
    const angleRad = ((180 - ratio * 180) * Math.PI) / 180;
    return {
        x: CX + R * Math.cos(angleRad),
        y: CY - R * Math.sin(angleRad),
    };
}

/** Semi-circle gauge placing the predicted finish time within its low-high range. */
export default function ProjectionGauge({
    lowSec,
    predictedSec,
    highSec,
}: Readonly<ProjectionGaugeProps>) {
    const span = highSec - lowSec || 1;
    const ratio = Math.min(1, Math.max(0, (predictedSec - lowSec) / span));
    const tweenedRatio = useCountUp(ratio);
    const marker = markerPosition(tweenedRatio);

    return (
        <div className="flex flex-col items-center">
            <svg width={140} height={78} viewBox="0 0 140 78" aria-hidden>
                <path
                    d={ARC_PATH}
                    pathLength={100}
                    fill="none"
                    strokeWidth={10}
                    strokeLinecap="round"
                    className="stroke-border-strong"
                />
                <path
                    d={ARC_PATH}
                    pathLength={100}
                    fill="none"
                    strokeWidth={10}
                    strokeLinecap="round"
                    strokeDasharray={`${tweenedRatio * 100} 100`}
                    className="stroke-icon-accent"
                />
                <circle
                    cx={marker.x}
                    cy={marker.y}
                    r={5}
                    strokeWidth={3}
                    className="fill-card stroke-icon-accent"
                />
            </svg>
            <div className="relative mt-1.5 h-3.5 w-[140px] font-mono text-xs font-bold tabular-nums text-text-2">
                <span className="absolute left-[8.6%] -translate-x-1/2">
                    {formatDurationHMS(lowSec)}
                </span>
                <span className="absolute right-[8.6%] translate-x-1/2">
                    {formatDurationHMS(highSec)}
                </span>
            </div>
        </div>
    );
}
