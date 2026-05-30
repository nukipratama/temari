import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { chipVariants } from '@/lib/variants';

export type ChipTone = 'neutral' | 'horizon' | 'leaf' | 'sky' | 'onSky';

interface ChipProps {
    children: ReactNode;
    tone?: ChipTone;
    size?: 'sm' | 'md';
    className?: string;
}

export default function Chip({ children, tone = 'neutral', size = 'sm', className }: Readonly<ChipProps>) {
    return (
        <span className={cn(chipVariants({ tone, size }), className)}>
            {children}
        </span>
    );
}
