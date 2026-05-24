import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';

interface BrandMarkProps {
    size?: 'hero' | 'compact';
    /** Wordmark color tone — flip to 'cream' when the mark sits on a dark hero surface. */
    tone?: 'ink' | 'cream';
    tagline?: boolean;
    className?: string;
}

export default function BrandMark({ size = 'hero', tone = 'ink', tagline = false, className }: Readonly<BrandMarkProps>) {
    if (size === 'compact') {
        return (
            <div className={cn('flex items-center gap-2.5', className)}>
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-leaf text-white">
                    <Icon icon="mdi:run-fast" width={20} height={20} aria-hidden />
                </span>
                <span className={cn('font-semibold tracking-tight', tone === 'cream' ? 'text-cream' : 'text-ink')}>TemanLari</span>
            </div>
        );
    }

    return (
        <div className={cn('flex flex-col items-center text-center', className)}>
            <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-leaf text-white shadow-sm">
                <Icon icon="mdi:run-fast" width={36} height={36} aria-hidden />
            </span>
            <h1 className="mt-5 text-4xl font-semibold tracking-tight text-ink">TemanLari</h1>
            {tagline && (
                <p className="mt-2 text-base text-ink">Setiap Langkah Berarti</p>
            )}
        </div>
    );
}
