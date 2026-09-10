interface SparklineProps {
    values: readonly number[];
    ariaLabel: string;
}

const VIEW_WIDTH = 100;
const VIEW_HEIGHT = 24;
const GAP = 0.25;

/**
 * Inline SVG bar sparkline. Deliberately not a chart library: one of these
 * renders per athlete row, and the whole vocabulary here is "how tall".
 */
export default function Sparkline({
    values,
    ariaLabel,
}: Readonly<SparklineProps>) {
    const peak = Math.max(...values, 0);
    const slot = values.length > 0 ? VIEW_WIDTH / values.length : VIEW_WIDTH;

    return (
        <svg
            role="img"
            aria-label={ariaLabel}
            viewBox={`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`}
            preserveAspectRatio="none"
            className="h-6 w-[100px]"
        >
            {values.map((value, index) => {
                const key = `${index}`;
                const height =
                    peak > 0 && value > 0
                        ? Math.max((value / peak) * VIEW_HEIGHT, 1)
                        : 0.75;

                return (
                    <rect
                        key={key}
                        x={index * slot}
                        y={VIEW_HEIGHT - height}
                        width={Math.max(slot - GAP, 0.5)}
                        height={height}
                        className={value > 0 ? 'fill-horizon' : 'fill-border'}
                    />
                );
            })}
        </svg>
    );
}
