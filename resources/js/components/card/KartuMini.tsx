import { cn } from '@/lib/cn';
import { RARITY_BORDER, RARITY_DOT, RARITY_TINT } from '@/lib/runcard';
import RouteGlyph from '@/components/card/RouteGlyph';
import type { CardEdition, Rarity } from '@/types/inertia';

interface KartuMiniProps {
    name: string;
    rarity?: Rarity;
    date?: string;
    polyline?: string | null;
    edition?: CardEdition | null;
    className?: string;
}

/**
 * Compact mini-TCG tile for the HariIni "kartu terakhir" strip: same visual
 * language as the full card (rarity-tinted frame, dot, route-glyph thumbnail,
 * Oswald nameplate, optional edition) at 140px.
 */
export default function KartuMini({
    name,
    rarity = 'epic',
    date,
    polyline,
    edition,
    className,
}: Readonly<KartuMiniProps>) {
    return (
        <div
            className={cn(
                'relative w-[140px] flex-none overflow-hidden rounded-[12px] border-[1.5px] bg-surface-card p-2.5',
                RARITY_BORDER[rarity],
                className,
            )}
        >
            <span aria-hidden className={cn('pointer-events-none absolute inset-0', RARITY_TINT[rarity])} />
            <div className="relative z-10 flex flex-col gap-1.5">
                <div className="flex items-center justify-between gap-1">
                    <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', RARITY_DOT[rarity])} />
                    {edition && (
                        <span className="font-collectible text-[10px] font-semibold tabular-nums text-ink-3">
                            #{edition.index}/{edition.total}
                        </span>
                    )}
                </div>
                {polyline != null && polyline !== '' && (
                    <div className="aspect-[5/3] w-full overflow-hidden rounded-md border border-line bg-surface-sunken">
                        <RouteGlyph rarity={rarity} polyline={polyline} />
                    </div>
                )}
                <div className="line-clamp-2 font-collectible text-[14px] font-semibold uppercase leading-[1.06] tracking-[0.01em] text-ink">
                    {name}
                </div>
                {date != null && date !== '' && (
                    <div className="font-mono text-[10px] uppercase tracking-[0.1em] text-ink-3">{date}</div>
                )}
            </div>
        </div>
    );
}
