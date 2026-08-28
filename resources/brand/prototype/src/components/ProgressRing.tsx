import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/utils';

/**
 * resources/brand/today-redesign.html's .ring-wrap/.ring-label, with an
 * animated fill (framer-motion via useCountUp) instead of a static
 * stroke-dashoffset — settles at the exact same geometry either way.
 */
export function ProgressRing({
    credited,
    total,
    size = 60,
    strokeWidth = 6,
    className,
}: Readonly<{
    credited: number;
    total: number;
    size?: number;
    strokeWidth?: number;
    className?: string;
}>) {
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const ratio = total > 0 ? credited / total : 0;
    const tweenedRatio = useCountUp(ratio);
    const tweenedCredited = useCountUp(credited);
    const offset = circumference * (1 - tweenedRatio);

    return (
        <div
            className={cn('relative flex-none', className)}
            style={{ width: size, height: size }}
        >
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                className="-rotate-90"
                aria-hidden
            >
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={strokeWidth}
                    className="stroke-border-strong"
                />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={strokeWidth}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={offset}
                    className="stroke-icon-accent"
                />
            </svg>
            <span className="absolute inset-0 flex items-center justify-center font-mono text-[10.5px] font-extrabold text-foreground">
                {Math.round(tweenedCredited)}/{total}
            </span>
        </div>
    );
}
