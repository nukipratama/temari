import type { ReactNode } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';

export type PageHeroSize = '2xl' | 'xl' | 'lg' | 'md' | 'sm' | 'quote-lg';

interface PageHeroProps {
    /** A plain string renders as the standard hero eyebrow. Pass a ReactNode
     *  (e.g. a <BackLink>, or an <Eyebrow> with its own className) for
     *  anything else. Omit for a headline with no label. */
    eyebrow?: ReactNode;
    /** Display-scale step (`text-display-{size}`), or `'quote-lg'` for the
     *  Temari-voice quote register (`text-quote-lg`, paired with `italic`).
     *  Default 'lg', the app's standard page-title weight; pick a bigger/
     *  smaller step to shape the page's own top-fold hierarchy. */
    size?: PageHeroSize;
    /** Dark HeroPanel/sky-panel context: cream headline text. Default false. */
    onSky?: boolean;
    /** Italicize the whole headline (Temari-voice register). Default false —
     *  compose an inline <em>/<span> in children for partial emphasis instead. */
    italic?: boolean;
    className?: string;
    /** Full headline content — line breaks and inline emphasis are
     *  caller-composed so each page keeps its own top-fold shape. */
    children: ReactNode;
}

const SIZE_CLASS: Record<PageHeroSize, string> = {
    '2xl': 'text-display-2xl',
    xl: 'text-display-xl',
    lg: 'text-display-lg',
    md: 'text-display-md',
    sm: 'text-display-sm',
    'quote-lg': 'text-quote-lg',
};

/**
 * The shared "eyebrow + headline" top-fold shell used across page headers.
 * Owns only the h1's font/size/color; eyebrow content and headline markup
 * are fully caller-composed as children.
 */
export default function PageHero({
    eyebrow,
    size = 'lg',
    onSky = false,
    italic = false,
    className,
    children,
}: Readonly<PageHeroProps>) {
    const eyebrowNode =
        typeof eyebrow === 'string' ? (
            <Eyebrow
                token="hero"
                tone={onSky ? 'horizon' : 'ink-2'}
                className="mb-3.5"
            >
                {eyebrow}
            </Eyebrow>
        ) : (
            eyebrow
        );

    return (
        <div className={className}>
            {eyebrowNode}
            <h1
                className={cn(
                    'font-serif',
                    SIZE_CLASS[size],
                    italic && 'italic',
                    onSky ? 'text-cream' : 'text-foreground',
                )}
            >
                {children}
            </h1>
        </div>
    );
}
