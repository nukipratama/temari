import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

type BackLinkTone = 'muted' | 'accent';

interface BackLinkProps {
    /** Destination. */
    href: string;
    /** Label after the arrow, e.g. "Riwayat · Jejak" or "Kembali ke Hari Ini". */
    children: ReactNode;
    /** `muted` (default) for detail-page breadcrumbs; `accent` for empty-state CTAs. */
    tone?: BackLinkTone;
    /** Spacing only (mb-*, mt-*); the visual style is owned by the component. */
    className?: string;
}

const TONE_CLASS: Record<BackLinkTone, string> = {
    muted: 'text-ink-2 hover:text-horizon-deep',
    accent: 'text-horizon-deep hover:text-ember-deep',
};

/**
 * The one back/breadcrumb link. A left arrow + a label, in the mono-uppercase
 * micro-label register, so every "go back" affordance reads identically across
 * detail pages and empty states.
 */
export default function BackLink({ href, children, tone = 'muted', className }: Readonly<BackLinkProps>) {
    return (
        <Link
            href={href}
            className={cn(
                'inline-flex items-center gap-1 font-mono text-xs font-bold uppercase tracking-[0.14em] transition',
                TONE_CLASS[tone],
                className,
            )}
        >
            <Icon icon="mdi:arrow-left" width={14} height={14} aria-hidden />
            {children}
        </Link>
    );
}
