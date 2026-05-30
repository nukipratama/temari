import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { cardVariants } from '@/lib/variants';

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

export default function Card({
    tone = 'cream',
    padding = 'md',
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
