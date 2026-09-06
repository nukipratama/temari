import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

interface SkeletonProps {
    /** Size / shape utilities (height, width, rounding). Defaults to a rounded bar. */
    className?: string;
}

/**
 * A single shimmering placeholder block for loading states. Compose several to
 * mock a shape; pass sizing via className. Decorative, so it's aria-hidden.
 * The sweep itself lives in the `.skeleton` class in app.css.
 */
export default function Skeleton({ className }: Readonly<SkeletonProps>) {
    return <div aria-hidden className={cn('skeleton rounded', className)} />;
}

const PROSE_WIDTHS = ['w-full', 'w-[70%]', 'w-[85%]'];

function Shape({
    className,
    children,
}: Readonly<{ className?: string; children: ReactNode }>) {
    return (
        <div role="status" aria-label="Loading" className={className}>
            {children}
        </div>
    );
}

/** Three ragged text bars, the shape AnalysisStatus already uses for pending narration. */
export function SkeletonProse({ className }: Readonly<SkeletonProps>) {
    return (
        <Shape className={cn('flex flex-col gap-1.5', className)}>
            {PROSE_WIDTHS.map((width) => (
                <Skeleton key={width} className={cn('h-[1.625em]', width)} />
            ))}
        </Shape>
    );
}

/** A rail of stat tiles, sized like the ones in ProfileHero. */
export function SkeletonStats({
    count = 3,
    className,
}: Readonly<SkeletonProps & { count?: number }>) {
    return (
        <Shape className={cn('flex gap-2', className)}>
            {Array.from({ length: count }, (_, i) => (
                <Skeleton
                    key={i}
                    className="h-[86px] grow shrink-0 basis-[108px] rounded-sm"
                />
            ))}
        </Shape>
    );
}

/** A plot area. Override the height through className. */
export function SkeletonChart({ className }: Readonly<SkeletonProps>) {
    return (
        <Shape>
            <Skeleton
                className={cn('h-[180px] w-full rounded-lg', className)}
            />
        </Shape>
    );
}

/** Repeated list rows. */
export function SkeletonRows({
    count = 3,
    className,
}: Readonly<SkeletonProps & { count?: number }>) {
    return (
        <Shape className={cn('flex flex-col gap-2', className)}>
            {Array.from({ length: count }, (_, i) => (
                <Skeleton key={i} className="h-14 w-full rounded-lg" />
            ))}
        </Shape>
    );
}
