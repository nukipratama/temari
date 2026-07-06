import { cn } from '@/lib/cn';
import {
    RARITY_BORDER,
    RARITY_HEX,
    RARITY_LABELS,
    RARITY_POSE,
    RARITY_SYMBOL,
    RARITY_TEXT,
} from '@/lib/runcard';
import RouteGlyph from '@/components/card/RouteGlyph';
import Temari from '@/components/temari/Temari';
import { moodSigilColor } from '@/lib/mood';
import type { CardEdition, Mood, Rarity } from '@/types/inertia';
import type { CSSProperties } from 'react';

interface KartuMiniProps {
    name: string;
    rarity?: Rarity;
    mood?: Mood;
    date?: string;
    polyline?: string | null;
    edition?: CardEdition | null;
    className?: string;
}

/**
 * Compact mini-TCG tile: the same dark-frame language as the full card at
 * 140px. Bright art window with the route hero + a tiny corner bunny, a dark
 * stat block with the rarity ribbon, name, and edition/date.
 */
export default function KartuMini({
    name,
    rarity = 'epic',
    mood,
    date,
    polyline,
    edition,
    className,
}: Readonly<KartuMiniProps>) {
    const rarityHex = RARITY_HEX[rarity];
    const moodColor = mood ? moodSigilColor(mood) : null;
    const rootStyle = { '--rarity': rarityHex } as CSSProperties;
    // Pearl backdrop matching the full Kartu + canvas share card: a rarity tier
    // glow up top, an optional mood echo bottom-right, over a cream depth gradient.
    const artStyle: CSSProperties = {
        background: [
            `radial-gradient(ellipse at 30% 26%, ${rarityHex}30 0%, ${rarityHex}12 42%, transparent 70%)`,
            moodColor ? `radial-gradient(ellipse at 82% 84%, ${moodColor}22 0%, transparent 60%)` : '',
            `linear-gradient(to bottom, #fcf9f3, var(--color-cream-deep))`,
        ].filter(Boolean).join(', '),
    };

    return (
        <div
            role="img"
            aria-label={name}
            style={rootStyle}
            className={cn(
                'relative flex w-[140px] flex-none flex-col overflow-hidden rounded-[12px] border-[1.5px] bg-sky-deep p-1',
                RARITY_BORDER[rarity],
                className,
            )}
        >
            {/* ART WINDOW */}
            <div className="relative aspect-[4/3] w-full overflow-hidden rounded-[8px]" style={artStyle}>
                {polyline != null && polyline !== '' && (
                    <div className="absolute inset-0">
                        <RouteGlyph rarity={rarity} color={rarityHex} polyline={polyline} />
                    </div>
                )}
                <span aria-hidden className="pointer-events-none absolute bottom-0.5 right-0.5">
                    <Temari pose={RARITY_POSE[rarity]} size={26} animate={false} dropShadow={false} />
                </span>
            </div>

            {/* STAT BLOCK */}
            <div className="px-1.5 pt-1 pb-0.5 text-cream">
                <div className="flex items-center gap-1">
                    <span aria-hidden className={cn('text-[8px] leading-none', RARITY_TEXT[rarity])}>
                        {RARITY_SYMBOL[rarity]}
                    </span>
                    <span className={cn('font-mono text-[8px] font-bold uppercase tracking-[0.12em]', RARITY_TEXT[rarity])}>
                        {RARITY_LABELS[rarity]}
                    </span>
                </div>
                <div className="mt-0.5 line-clamp-2 font-collectible text-[12px] font-semibold uppercase leading-[1.06] tracking-[0.01em] text-cream">
                    {name}
                </div>
                {(edition != null || (date != null && date !== '')) && (
                    <div className="mt-0.5 font-mono text-[9px] tabular-nums leading-tight text-ink-on-sky">
                        {edition != null && (
                            <span>#{edition.index}/{edition.total}</span>
                        )}
                        {edition != null && date != null && date !== '' && (
                            <span className="mx-1 opacity-40">·</span>
                        )}
                        {date != null && date !== '' && <span>{date}</span>}
                    </div>
                )}
            </div>
        </div>
    );
}
