import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface HeroPanelProps {
    children: ReactNode;
    /** When true, applies the pre-dawn 160deg sky→sky-deep→sky-2 gradient. */
    gradient?: boolean;
    className?: string;
}

const GRADIENT = 'bg-[linear-gradient(160deg,var(--color-sky-deep)_0%,var(--color-sky)_60%,var(--color-sky-2)_100%)]';

export default function HeroPanel({ children, gradient = true, className }: Readonly<HeroPanelProps>) {
    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-2xl px-9 py-8 text-cream',
                gradient ? GRADIENT : 'bg-sky',
                className,
            )}
        >
            {children}
        </div>
    );
}
