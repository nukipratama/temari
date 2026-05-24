import { type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type PillTone = 'horizon' | 'sky' | 'ghost';

interface PillButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
    children: ReactNode;
    tone?: PillTone;
    size?: 'sm' | 'md';
    /** Switch ghost to a cream-on-sky variant. */
    onSky?: boolean;
}

const TONE_CLASS: Record<PillTone, string> = {
    horizon: 'bg-horizon text-sky hover:bg-horizon-deep',
    sky: 'bg-sky text-cream hover:bg-sky-deep',
    ghost: 'bg-transparent text-ink border-[1.5px] border-ink/[0.18] hover:border-ink-2',
};

const GHOST_ON_SKY = 'bg-transparent text-cream border-[1.5px] border-cream/30 hover:border-cream/60';

export default function PillButton({
    children,
    tone = 'horizon',
    size = 'md',
    onSky = false,
    className,
    type = 'button',
    ...rest
}: Readonly<PillButtonProps>) {
    const toneClass = onSky && tone === 'ghost' ? GHOST_ON_SKY : TONE_CLASS[tone];
    return (
        <button
            type={type}
            className={cn(
                'inline-flex items-center gap-2 rounded-full font-sans font-medium transition',
                size === 'sm' ? 'px-3.5 py-2 text-[13px]' : 'px-[22px] py-3 text-sm',
                toneClass,
                className,
            )}
            {...rest}
        >
            {children}
        </button>
    );
}
