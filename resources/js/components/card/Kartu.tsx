import { cn } from '@/lib/cn';
import { RARITY_BORDER, RARITY_LABELS } from '@/lib/runcard';
import Chip from '@/components/ui/Chip';
import type { Rarity } from '@/types/inertia';

interface KartuProps {
    name: string;
    subtitle?: string | null;
    km: string;
    durasi: string;
    trimp: string | number;
    rarity?: Rarity;
    tags?: ReadonlyArray<string>;
    size?: 'md' | 'lg' | 'xl';
    /** When mounted inside a sky panel, the bg flips to cream-deep for contrast. */
    onSky?: boolean;
    className?: string;
}

const RARITY_FLAG_BG: Record<Rarity, string> = {
    common: 'bg-rarity-common text-cream',
    uncommon: 'bg-rarity-uncommon text-cream',
    rare: 'bg-rarity-rare text-cream',
    epic: 'bg-rarity-epic text-ink',
    legendary: 'bg-rarity-legendary text-ink',
};

const SIZE_PADDING: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'p-5',
    lg: 'px-6 py-[22px]',
    xl: 'px-8 py-7',
};

const SIZE_TITLE: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'text-[22px]',
    lg: 'text-[32px]',
    xl: 'text-[44px]',
};

const SIZE_STAT: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'text-xl',
    lg: 'text-[28px]',
    xl: 'text-4xl',
};

const SIZE_STAT_GAP: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'gap-[18px]',
    lg: 'gap-[18px]',
    xl: 'gap-9',
};

export default function Kartu({
    name,
    subtitle,
    km,
    durasi,
    trimp,
    rarity = 'epic',
    tags,
    size = 'md',
    onSky = false,
    className,
}: Readonly<KartuProps>) {
    return (
        <div
            className={cn(
                'relative flex flex-col overflow-hidden rounded-[14px] border-[1.5px]',
                onSky ? 'bg-cream-deep' : 'bg-cream',
                RARITY_BORDER[rarity],
                SIZE_PADDING[size],
                size === 'xl' ? 'gap-[18px]' : 'gap-3',
                className,
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'absolute right-0 top-0 rounded-bl-lg px-2.5 py-1 font-mono text-[10px] font-bold uppercase tracking-[0.12em]',
                    RARITY_FLAG_BG[rarity],
                )}
            >
                {RARITY_LABELS[rarity]}
            </span>
            <div>
                <div
                    className={cn(
                        'font-display leading-none tracking-[-0.01em] text-ink',
                        SIZE_TITLE[size],
                    )}
                >
                    {name}
                </div>
                {subtitle != null && subtitle !== '' && (
                    <div
                        className={cn(
                            'mt-1.5 font-sans text-ink-3',
                            size === 'xl' ? 'text-[15px]' : 'text-xs',
                        )}
                    >
                        {subtitle}
                    </div>
                )}
            </div>
            <div className={cn('flex items-baseline', SIZE_STAT_GAP[size])}>
                <Stat label="KM" value={km} sizeClass={SIZE_STAT[size]} />
                <Stat label="Durasi" value={durasi} sizeClass={SIZE_STAT[size]} />
                <Stat label="TRIMP" value={trimp} sizeClass={SIZE_STAT[size]} />
            </div>
            {tags && tags.length > 0 && (
                <div className={cn('flex flex-wrap gap-1.5', size === 'xl' ? 'mt-1.5' : 'mt-0.5')}>
                    {tags.map((t) => (
                        <Chip key={t} tone="horizon">{t}</Chip>
                    ))}
                </div>
            )}
        </div>
    );
}

function Stat({ label, value, sizeClass }: Readonly<{ label: string; value: string | number; sizeClass: string }>) {
    return (
        <div>
            <div className={cn('font-sans font-bold leading-none tabular-nums text-ink', sizeClass)}>
                {value}
            </div>
            <div className="mt-1 font-mono text-[9px] uppercase tracking-[0.14em] text-ink-3">
                {label}
            </div>
        </div>
    );
}
