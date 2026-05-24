import { cn } from '@/lib/cn';
import { RARITY_BORDER } from '@/lib/runcard';
import type { Rarity } from '@/types/inertia';

interface KartuMiniProps {
    name: string;
    rarity?: Rarity;
    date?: string;
    className?: string;
}

const RARITY_CORNER: Record<Rarity, string> = {
    common: 'border-t-rarity-common',
    uncommon: 'border-t-rarity-uncommon',
    rare: 'border-t-rarity-rare',
    epic: 'border-t-rarity-epic',
    legendary: 'border-t-rarity-legendary',
};

export default function KartuMini({
    name,
    rarity = 'epic',
    date,
    className,
}: Readonly<KartuMiniProps>) {
    return (
        <div
            className={cn(
                'relative w-[140px] flex-none overflow-hidden rounded-[10px] border-[1.5px] bg-cream px-3.5 py-3',
                RARITY_BORDER[rarity],
                className,
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'absolute right-0 top-0 h-0 w-0 border-l-[14px] border-l-transparent border-t-[14px]',
                    RARITY_CORNER[rarity],
                )}
            />
            <div className="mb-1.5 font-display text-[17px] leading-[1.05] text-ink">{name}</div>
            {date != null && date !== '' && (
                <div className="font-mono text-[9px] uppercase tracking-[0.08em] text-ink-3">{date}</div>
            )}
        </div>
    );
}
