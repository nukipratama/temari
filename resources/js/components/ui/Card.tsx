import { type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { cardVariants } from '@/lib/variants';

export type CardTone = 'card' | 'sky' | 'onSky' | 'empty';
export type CardPadding = 'none' | 'panel' | 'card' | 'hero';

interface CardProps {
    /** Default 'card' — the one card surface; 'sky' is the dark panel itself, 'onSky' a card mounted on one. */
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
