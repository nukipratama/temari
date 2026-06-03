import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { pillButtonVariants } from '@/lib/variants';
import type { PillTone } from '@/components/ui/PillButton';

interface PillLinkProps {
    href: string;
    children: ReactNode;
    tone?: PillTone;
    size?: 'sm' | 'md';
    /** Switch ghost to a cream-on-sky variant. */
    onSky?: boolean;
    preserveScroll?: boolean;
    /** Fires on click (e.g. dismiss a modal before navigating). */
    onClick?: () => void;
    className?: string;
}

/**
 * A navigation pill: the visual twin of {@link PillButton} but rendered as an
 * Inertia `<Link>`, so a CTA that *navigates* is a real anchor instead of an
 * invalid `<a><button>` nesting.
 */
export default function PillLink({
    href,
    children,
    tone = 'sky',
    size = 'md',
    onSky = false,
    preserveScroll,
    onClick,
    className,
}: Readonly<PillLinkProps>) {
    return (
        <Link
            href={href}
            preserveScroll={preserveScroll}
            onClick={onClick}
            className={cn(pillButtonVariants({ tone, size, onSky }), className)}
        >
            {children}
        </Link>
    );
}
