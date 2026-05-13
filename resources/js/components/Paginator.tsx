import { cn } from '@/lib/cn';
import MotionLink from '@/components/MotionLink';
import { pressShrink } from '@/lib/motion';

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
                className="rounded-lg border border-line px-4 py-2 text-sm text-ink-meta dark:border-line-dark dark:text-ink-meta-dark"
                dangerouslySetInnerHTML={{ __html: link.label }}
            />
        );
    }
    return (
        <MotionLink
            href={link.url}
            whileTap={pressShrink}
            className={cn(
                'rounded-lg border px-4 py-2 text-sm transition',
                link.active
                    ? 'border-brand-500 bg-brand-500 font-semibold text-white'
                    : 'border-line text-ink hover:border-ink-soft dark:border-line-dark dark:text-ink-dark',
            )}
            dangerouslySetInnerHTML={{ __html: link.label }}
        />
    );
}
