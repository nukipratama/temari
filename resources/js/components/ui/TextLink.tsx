import { Link } from '@inertiajs/react';
import { type ReactNode, type ComponentProps } from 'react';
import { cn } from '@/lib/cn';

interface TextLinkProps {
    href: string;
    children: ReactNode;
    /** Inertia Link props (preserveScroll, etc.) */
    preserveScroll?: boolean;
    className?: string;
    /** External anchor — renders <a target="_blank"> instead of Inertia Link. */
    external?: boolean;
}

/**
 * Horizon-toned text link with WCAG-AA-safe contrast on cream surfaces:
 * uses horizon-deep instead of horizon, plus font-semibold at text-sm so
 * the visual weight clears the 4.5:1 small-text threshold on light bg.
 *
 * Use this anywhere an inline arrow link reads "Lihat detail →",
 * "Semua rekor →", etc. instead of dropping raw horizon-tinted text.
 */
export default function TextLink({
    href,
    children,
    preserveScroll,
    className,
    external = false,
}: Readonly<TextLinkProps>) {
    const cls = cn(
        'inline-flex items-center gap-1 text-sm font-semibold text-horizon-deep transition hover:text-ember-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-horizon-deep focus-visible:ring-offset-2 focus-visible:ring-offset-cream rounded',
        className,
    );

    if (external) {
        const externalProps: ComponentProps<'a'> = {
            href,
            target: '_blank',
            rel: 'noopener noreferrer',
            className: cls,
        };

        return <a {...externalProps}>{children}</a>;
    }

    return (
        <Link href={href} preserveScroll={preserveScroll} className={cls}>
            {children}
        </Link>
    );
}
