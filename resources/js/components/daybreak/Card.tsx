import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type CardTone = 'cream' | 'cream-deep' | 'sky-glass' | 'empty';
export type CardPadding = 'sm' | 'md' | 'lg' | 'none';

interface CardProps {
    /** Default 'cream' — cream surface + cream-deep border on the page bg. */
    tone?: CardTone;
    /** Default 'md' — px-5 py-5. */
    padding?: CardPadding;
    /** Render as <section> when the card is a top-level page block. */
    as?: 'div' | 'section' | 'article' | 'aside';
    className?: string;
    children: ReactNode;
}

export const TONE_CLASS: Record<CardTone, string> = {
    cream: 'rounded-2xl border border-cream-deep bg-cream',
    'cream-deep': 'rounded-2xl bg-cream-deep',
    'sky-glass': 'rounded-2xl border border-cream/[0.12] bg-cream/[0.06] backdrop-blur',
    empty: 'rounded-2xl border-2 border-dashed border-cream-deep bg-cream/40',
};

export const PADDING_CLASS: Record<CardPadding, string> = {
    none: '',
    sm: 'px-4 py-3.5',
    md: 'px-5 py-5',
    lg: 'px-6 py-6',
};

export default function Card({
    tone = 'cream',
    padding = 'md',
    as: Component = 'div',
    className,
    children,
}: Readonly<CardProps>) {
    return (
        <Component className={cn(TONE_CLASS[tone], PADDING_CLASS[padding], className)}>
            {children}
        </Component>
    );
}
