import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface PageHeroProps {
    eyebrow: string;
    lead?: string;
    emph: ReactNode;
    /** Set when the hero sits on a dark sky/horizon panel — flips text + accent tones. */
    onSky?: boolean;
    className?: string;
}

const EYEBROW = 'font-mono text-[11px] font-bold uppercase tracking-[0.18em]';
const HEADLINE_ON_SKY = 'font-display text-display-xl text-cream';
const HEADLINE_ON_CREAM = 'font-display text-display-lg text-ink';

export default function PageHero({ eyebrow, lead, emph, onSky = false, className }: Readonly<PageHeroProps>) {
    return (
        <div className={className}>
            <div className={cn('mb-3', EYEBROW, onSky ? 'text-horizon' : 'text-ink-3')}>
                {eyebrow}
            </div>
            <h1 className={onSky ? HEADLINE_ON_SKY : HEADLINE_ON_CREAM}>
                {lead && <>{lead} </>}
                <em className={cn('italic', onSky ? 'text-horizon' : 'text-horizon-deep')}>{emph}</em>
            </h1>
        </div>
    );
}
