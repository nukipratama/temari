import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type ChipTone = 'neutral' | 'horizon' | 'leaf' | 'sky' | 'onSky';

interface ChipProps {
    children: ReactNode;
    tone?: ChipTone;
    size?: 'sm' | 'md';
    className?: string;
}

const TONE_CLASS: Record<ChipTone, string> = {
    neutral: 'bg-ink/[0.06] text-ink-2',
    horizon: 'bg-horizon/[0.18] text-horizon-deep',
    leaf: 'bg-leaf/[0.18] text-leaf',
    sky: 'bg-sky/[0.08] text-sky',
    onSky: 'bg-cream/10 text-cream/80',
};

export default function Chip({ children, tone = 'neutral', size = 'sm', className }: Readonly<ChipProps>) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full font-mono font-semibold uppercase tracking-[0.08em]',
                size === 'sm' ? 'px-[9px] py-[3px] text-[10px]' : 'px-[11px] py-[5px] text-[11px]',
                TONE_CLASS[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
