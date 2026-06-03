import { type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { pillButtonVariants } from '@/lib/variants';

export type PillTone = 'horizon' | 'sky' | 'ghost';

interface PillButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
    children: ReactNode;
    tone?: PillTone;
    size?: 'sm' | 'md';
    /** Switch ghost to a cream-on-sky variant. */
    onSky?: boolean;
}

export default function PillButton({
    children,
    tone = 'sky',
    size = 'md',
    onSky = false,
    className,
    type = 'button',
    ...rest
}: Readonly<PillButtonProps>) {
    return (
        <button
            type={type}
            className={cn(pillButtonVariants({ tone, size, onSky }), className)}
            {...rest}
        >
            {children}
        </button>
    );
}
