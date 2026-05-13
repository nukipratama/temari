import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';

interface BrandMarkProps {
    /**
     * `hero` — large stacked layout for pre-auth landings (chip on top,
     * wordmark + tagline below). `compact` — inline chip + wordmark for
     * the app header.
     */
    size?: 'hero' | 'compact';
    /** Show "Setiap Langkah Berarti" tagline. Hero only. */
    tagline?: boolean;
    className?: string;
}

/**
 * The TemanLari brand mark — forest-green run-fast chip + wordmark.
 * One source of truth so Login, Welcome, and AppHeader stay in sync if
 * the icon, chip color, or wordmark spelling ever changes.
 */
export default function BrandMark({ size = 'hero', tagline = false, className }: Readonly<BrandMarkProps>) {
    if (size === 'compact') {
        return (
            <div className={cn('flex items-center gap-2.5', className)}>
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-500 text-white">
                    <Icon icon="mdi:run-fast" width={20} height={20} aria-hidden />
                </span>
                <span className="font-semibold tracking-tight text-ink dark:text-ink-dark">TemanLari</span>
            </div>
        );
    }

    return (
        <div className={cn('flex flex-col items-center text-center', className)}>
            <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-500 text-white shadow-sm">
                <Icon icon="mdi:run-fast" width={36} height={36} aria-hidden />
            </span>
            <h1 className="mt-5 text-4xl font-semibold tracking-tight text-ink dark:text-ink-dark">TemanLari</h1>
            {tagline && (
                <p className="mt-2 text-base text-ink dark:text-ink-dark">Setiap Langkah Berarti</p>
            )}
        </div>
    );
}
