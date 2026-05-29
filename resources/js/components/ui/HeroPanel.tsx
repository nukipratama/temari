import { type CSSProperties, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface HeroPanelProps {
    children: ReactNode;
    /** When true, applies the pre-dawn 160deg sky→sky-deep→sky-2 gradient. */
    gradient?: boolean;
    className?: string;
    style?: CSSProperties;
}

const GRADIENT = 'bg-[linear-gradient(160deg,var(--color-sky-deep)_0%,var(--color-sky)_60%,var(--color-sky-2)_100%)]';

export default function HeroPanel({ children, gradient = true, className, style }: Readonly<HeroPanelProps>) {
    return (
        <div
            className={cn(
                // Mobile-first padding; callers bump `lg:` (no tailwind-merge in `cn`,
                // so the base intentionally leaves `lg:` to callers to avoid conflicts).
                'relative overflow-hidden rounded-2xl px-6 py-6 text-cream sm:px-8 sm:py-7',
                gradient ? GRADIENT : 'bg-sky',
                className,
            )}
            style={style}
        >
            {children}
        </div>
    );
}
