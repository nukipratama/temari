import { Icon } from '@iconify/react';
import type { ReactNode } from 'react';
import DecorativeBlur from '@/components/DecorativeBlur';
import { cn } from '@/lib/cn';

export type PageHeroTone = 'brand' | 'pop';

interface PageHeroProps {
    icon: string;
    title: string;
    subtitle?: ReactNode;
    /** Background colour family. `brand` = emerald, `pop` = mustard celebration. */
    tone?: PageHeroTone;
    /** Optional element rendered to the right (e.g. total-count pill). */
    trailing?: ReactNode;
    /** Optional eyebrow above the title (small uppercase label). */
    eyebrow?: ReactNode;
    className?: string;
}

const TONE_SURFACE: Record<PageHeroTone, string> = {
    brand: 'border-line bg-gradient-to-br from-brand-50 via-surface-warm to-accent-50',
    pop: 'border-pop-200 bg-gradient-to-br from-pop-50 via-surface-warm to-accent-50',
};

const TONE_ICON_TILE: Record<PageHeroTone, string> = {
    brand: 'bg-brand-500',
    pop: 'bg-pop-500',
};

const TONE_BLOB_PRIMARY: Record<PageHeroTone, string> = {
    brand: 'bg-pop-200/40',
    pop: 'bg-pop-300/40',
};

const TONE_BLOB_SECONDARY: Record<PageHeroTone, string> = {
    brand: 'bg-brand-200/40',
    pop: 'bg-accent-200/40',
};

/**
 * Section banner used at the top of Aktivitas, Catatan, Rekor, Kartu pages.
 * Renders the icon-tile + h1 + subtitle in a softly gradient surface with
 * two decorative blur blobs. Use `trailing` for a status pill on the right.
 */
export default function PageHero({
    icon,
    title,
    subtitle,
    tone = 'brand',
    trailing,
    eyebrow,
    className,
}: Readonly<PageHeroProps>) {
    return (
        <header
            className={cn(
                'relative overflow-hidden rounded-3xl border p-6 shadow-md',
                TONE_SURFACE[tone],
                className,
            )}
        >
            <DecorativeBlur className={cn('-right-16 -top-16 h-56 w-56', TONE_BLOB_PRIMARY[tone])} />
            <DecorativeBlur className={cn('-bottom-16 -left-10 h-48 w-48', TONE_BLOB_SECONDARY[tone])} />
            <div className="relative flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-start gap-3">
                    <span
                        aria-hidden
                        className={cn(
                            'flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white shadow-md ring-2 ring-white',
                            TONE_ICON_TILE[tone],
                        )}
                    >
                        <Icon icon={icon} width={24} height={24} />
                    </span>
                    <div>
                        {eyebrow !== undefined && eyebrow !== null && (
                            <p
                                className={cn(
                                    'text-xs font-semibold uppercase tracking-wider',
                                    tone === 'pop' ? 'text-pop-700' : 'text-brand-700',
                                )}
                            >
                                {eyebrow}
                            </p>
                        )}
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink">{title}</h1>
                        {subtitle !== undefined && subtitle !== null && (
                            <p className="mt-1 text-sm leading-relaxed text-ink-soft">{subtitle}</p>
                        )}
                    </div>
                </div>
                {trailing !== undefined && trailing !== null && (
                    <div className="self-start">{trailing}</div>
                )}
            </div>
        </header>
    );
}
