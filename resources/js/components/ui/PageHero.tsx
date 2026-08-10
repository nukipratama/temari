import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

export type PageHeroSize = '2xl' | 'xl' | 'lg' | 'md' | 'sm';

interface PageHeroProps {
    /** Rendered above the headline as-is — usually an <Eyebrow>, occasionally
     *  a <BackLink> on sub-pages. Omit for a headline with no label. */
    eyebrow?: ReactNode;
    /** Display-scale step (`text-display-{size}`). Default 'lg', the app's
     *  standard page-title weight; pick a bigger/smaller step to shape the
     *  page's own top-fold hierarchy. */
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
    return (
        <div className={className}>
            {eyebrow}
            <h1
                className={cn(
                    'font-display',
                    SIZE_CLASS[size],
                    italic && 'italic',
                    onSky ? 'text-cream' : 'text-ink',
                )}
            >
                {children}
            </h1>
        </div>
    );
}
