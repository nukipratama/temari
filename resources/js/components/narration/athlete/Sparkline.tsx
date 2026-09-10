import { formatCost, formatDay } from './format';

interface SparklineProps {
    points: Array<{ day: string; cost: number }>;
    currency: string;
}

const WIDTH = 240;
const HEIGHT = 40;

/**
 * Thirty days of daily spend as one inline path. Deliberately not a chart
 * library: the page draws exactly this shape once, and a devtools bundle is not
 * worth a dependency.
 */
export default function Sparkline({
    points,
    currency,
}: Readonly<SparklineProps>) {
    const peak = Math.max(...points.map((p) => p.cost), 0);
    const step = points.length > 1 ? WIDTH / (points.length - 1) : WIDTH;

    const path = points
        .map((point, index) => {
            const x = index * step;
            const y = HEIGHT - (peak === 0 ? 0 : (point.cost / peak) * HEIGHT);
            return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');

    const last = points[points.length - 1];
    const label =
        last === undefined
            ? 'no spend recorded'
            : `daily spend for the last ${points.length} days, peaking at ${formatCost(peak, currency)}, latest ${formatDay(last.day)} at ${formatCost(last.cost, currency)}`;

    return (
        <figure className="mt-2 w-full max-w-[240px]">
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                preserveAspectRatio="none"
                className="h-10 w-full text-leaf-ink"
                role="img"
                aria-label={label}
            >
                <path
                    d={path}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={1.5}
                    vectorEffect="non-scaling-stroke"
                    strokeLinejoin="round"
                />
            </svg>
            <figcaption className="mt-1 flex justify-between text-label-micro text-text-3">
                <span>
                    {points[0] !== undefined ? formatDay(points[0].day) : ''}
                </span>
                <span>peak {formatCost(peak, currency)}</span>
            </figcaption>
        </figure>
    );
}
