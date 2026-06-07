import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface SectionLabelProps {
    children: ReactNode;
    /** `onSky` = cream text on a sky panel. */
    onSky?: boolean;
    /**
     * Render a leading family-color dot instead of the trailing divider rule.
     * The mono-caps eyebrow used across the dashboard cards.
     */
    dot?: boolean;
    /** Dot color utility (e.g. `bg-horizon`). Only applies when `dot` is set. Defaults to `bg-ink-3`. */
    dotClass?: string;
    /** Label type tier. The `dot` eyebrows run at the 11px `micro` tier. */
    size?: 'small' | 'micro';
    className?: string;
}

export default function SectionLabel({
    children,
    onSky = false,
    dot = false,
    dotClass = 'bg-ink-3',
    size = dot ? 'micro' : 'small',
    className,
}: Readonly<SectionLabelProps>) {
    return (
        <div
            className={cn(
                'mb-3.5 flex items-center gap-3',
                size === 'micro' ? 'gap-1.5 text-label-micro' : 'gap-3 text-label-small',
                onSky ? 'text-ink-on-sky' : 'text-ink-2',
                className,
            )}
        >
            {dot && <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', dotClass)} />}
            <span>{children}</span>
            {!dot && <span aria-hidden className="h-px flex-1 bg-current opacity-20" />}
        </div>
    );
}
