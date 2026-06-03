import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface SectionLabelProps {
    children: ReactNode;
    /** `onSky` = cream text on a sky panel. */
    onSky?: boolean;
    className?: string;
}

export default function SectionLabel({ children, onSky = false, className }: Readonly<SectionLabelProps>) {
    return (
        <div
            className={cn(
                'mb-3.5 flex items-center gap-3 text-label-small',
                onSky ? 'text-ink-on-sky' : 'text-ink-2',
                className,
            )}
        >
            <span>{children}</span>
            <span aria-hidden className="h-px flex-1 bg-current opacity-20" />
        </div>
    );
}
