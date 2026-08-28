import { Icon } from '@iconify/react';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

import { cn } from '@/lib/cn';

type BackLinkTone = 'muted' | 'accent';

interface BackLinkProps {
    /** Destination. */
    href: string;
    /** Label after the arrow, e.g. "History · Log" or "Back to Today". */
    children: ReactNode;
    /** `muted` (default) for detail-page breadcrumbs; `accent` for empty-state CTAs. */
    tone?: BackLinkTone;
    /** Spacing only (mb-*, mt-*); the visual style is owned by the component. */
    className?: string;
}

const TONE_CLASS: Record<BackLinkTone, string> = {
    muted: 'text-text-2 hover:text-horizon-ink',
    accent: 'text-horizon-ink hover:text-ember-ink',
};

/**
 * The one back/breadcrumb link. A left arrow + a label, in the mono-uppercase
 * micro-label register, so every "go back" affordance reads identically across
 * detail pages and empty states.
 */
export default function BackLink({
    href,
    children,
    tone = 'muted',
    className,
}: Readonly<BackLinkProps>) {
    return (
        <Link
            href={href}
            className={cn(
                'focus-ring inline-flex items-center gap-1 rounded text-label-small transition',
                TONE_CLASS[tone],
                className,
            )}
        >
            <Icon icon="mdi:arrow-left" width={14} height={14} aria-hidden />
            {children}
        </Link>
    );
}
