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
