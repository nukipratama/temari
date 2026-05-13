import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

/**
 * Renders Laravel paginator links — supports active, inactive, and disabled
 * (null url) states. The label may contain HTML entities like `&laquo;` so
 * each pill uses dangerouslySetInnerHTML.
 */
export default function Paginator({ links, className }: Readonly<{ links: PaginatorLink[]; className?: string }>) {
    return (
        <nav className={cn('mt-6 flex flex-wrap items-center justify-center gap-2', className)}>
            {links.map((link, i) => (
                <PaginatorPill key={i} link={link} />
            ))}
        </nav>
    );
}

function PaginatorPill({ link }: Readonly<{ link: PaginatorLink }>) {
    if (link.url === null) {
        return (
            <span
                className="rounded-lg border border-line px-3 py-1.5 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark"
                dangerouslySetInnerHTML={{ __html: link.label }}
            />
        );
    }
    return (
        <Link
            href={link.url}
            className={cn(
                'rounded-lg border px-3 py-1.5 text-xs transition',
                link.active
                    ? 'border-brand-500 bg-brand-500 font-semibold text-white'
                    : 'border-line text-ink hover:border-ink-soft dark:border-line-dark dark:text-ink-dark',
            )}
            dangerouslySetInnerHTML={{ __html: link.label }}
        />
    );
}
