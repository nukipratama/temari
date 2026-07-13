import { cn } from '@/lib/cn';

interface SkeletonProps {
    /** Size / shape utilities (height, width, rounding). Defaults to a rounded bar. */
    className?: string;
}

/**
 * A single pulsing placeholder block for loading states. Compose several to
 * mock a shape; pass sizing via className. Decorative, so it's aria-hidden.
 */
export default function Skeleton({ className }: Readonly<SkeletonProps>) {
    return <div aria-hidden className={cn('animate-pulse rounded bg-cream-deep/40', className)} />;
}
