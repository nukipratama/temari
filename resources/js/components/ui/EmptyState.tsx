import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface EmptyStateProps {
    /** The placeholder line (short, Temari-voiced). */
    children: ReactNode;
    /** Extra / override utilities, merged last so callers win (e.g. taller padding). */
    className?: string;
}

/**
 * Shared empty-state placeholder: the dashed cream-deep panel shown wherever a
 * surface has no data yet. Centralizes the dashed-border + serif-italic
 * treatment so each surface stops re-typing it.
 */
export default function EmptyState({ children, className }: Readonly<EmptyStateProps>) {
    return (
        <div
            className={cn(
                'rounded-2xl border-2 border-dashed border-cream-deep bg-cream/40 px-6 py-8 text-center',
                className,
            )}
        >
            <p className="font-display text-base italic text-ink-3">{children}</p>
        </div>
    );
}
