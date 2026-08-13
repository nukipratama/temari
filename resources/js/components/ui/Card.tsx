import { type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { cardVariants } from '@/lib/variants';

export type CardTone = 'card' | 'onSky' | 'empty';
export type CardPadding = 'none' | 'panel' | 'card' | 'hero';

interface CardProps {
    /** Default 'card' — the one card surface; 'onSky' for a dark panel. */
    tone?: CardTone;
    /** Default 'card' — the --pad-card role. */
    padding?: CardPadding;
    /** Render as <section> when the card is a top-level page block. */
    as?: 'div' | 'section' | 'article' | 'aside';
    className?: string;
    children: ReactNode;
}

export default function Card({
    tone = 'card',
    padding = 'card',
    as: Component = 'div',
    className,
    children,
}: Readonly<CardProps>) {
    return (
        <Component className={cn(cardVariants({ tone, padding }), className)}>
            {children}
        </Component>
    );
}
