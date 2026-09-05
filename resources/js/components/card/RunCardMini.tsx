import type { CSSProperties } from 'react';

import type { CardEdition, Mood, Rarity } from '@/types/inertia';

import RouteGlyph from '@/components/card/RouteGlyph';
import ThreadBandGlyph from '@/components/card/ThreadBandGlyph';
import TemariMark from '@/components/TemariMark';
import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';
import { moodSigilColor } from '@/lib/mood';
import {
    RARITY_HEX,
    RARITY_LABELS,
    RARITY_SYMBOL,
    RARITY_TEXT,
} from '@/lib/runcard';
import { rarityVariants } from '@/lib/variants';

interface RunCardMiniProps {
    name: string;
    rarity?: Rarity;
    mood?: Mood;
    date?: string;
    polyline?: string | null;
    edition?: CardEdition | null;
    /**
     * Login's teaser slot, which the prototype draws at 78x84: the art window
     * and the rarity ribbon only, with no name, edition or date.
     */
    compact?: boolean;
    className?: string;
}

/**
 * Compact mini-TCG tile: the same dark-frame language as the full card at
 * 140px. Bright art window with the route hero + a tiny corner brand mark, a
 * dark stat block with the rarity ribbon, name, and edition/date.
 */
export default function RunCardMini({
    name,
    rarity = 'epic',
    mood,
    date,
    polyline,
    edition,
    compact = false,
    className,
}: Readonly<RunCardMiniProps>) {
    const rarityHex = RARITY_HEX[rarity];
    const moodColor = mood ? moodSigilColor(mood) : null;
    const rootStyle = { '--rarity': rarityHex } as CSSProperties;
    // Pearl backdrop matching the full Card + canvas share card: a rarity tier
    // glow up top, an optional mood echo bottom-right, over a cream depth gradient.
    const artStyle: CSSProperties = {
        background: [
            `radial-gradient(ellipse at 30% 26%, ${rarityHex}30 0%, ${rarityHex}12 42%, transparent 70%)`,
            moodColor
                ? `radial-gradient(ellipse at 82% 84%, ${moodColor}22 0%, transparent 60%)`
                : '',
            `linear-gradient(to bottom, #fcf9f3, var(--color-cream-deep))`,
        ]
            .filter(Boolean)
            .join(', '),
    };

    return (
        <div
            role="img"
            aria-label={name}
            style={rootStyle}
            className={cn(
                'relative flex flex-none flex-col overflow-hidden rounded-[12px] border-[1.5px] bg-sky-deep p-1',
                compact ? 'h-[84px] w-[78px]' : 'w-[140px]',
                rarityVariants.border({ rarity }),
                className,
            )}
        >
            {/* ART WINDOW */}
            <div
                className={cn(
                    'relative w-full overflow-hidden rounded-[8px]',
                    compact ? 'min-h-0 flex-1' : 'aspect-[4/3]',
                )}
                style={artStyle}
            >
                {polyline != null && polyline !== '' && (
                    <div className="absolute inset-0">
                        <RouteGlyph
                            rarity={rarity}
                            color={rarityHex}
                            polyline={polyline}
                        />
                    </div>
                )}
                {!compact && (
                    <span
                        aria-hidden
                        className="pointer-events-none absolute bottom-0.5 right-0.5"
                    >
                        <TemariMark size={26} color="var(--color-ink)" />
                    </span>
                )}
            </div>

            {/* STAT BLOCK */}
            <div
                className={cn(
                    'text-cream',
                    compact ? 'px-0.5 pt-1' : 'px-1.5 pt-1 pb-0.5',
                )}
            >
                <div className="flex items-center gap-1">
                    <span
                        aria-hidden
                        className={cn(
                            'text-[0.5rem] leading-none',
                            RARITY_TEXT[rarity],
                        )}
                    >
                        {RARITY_SYMBOL[rarity]}
                    </span>
                    <Eyebrow
                        as="span"
                        token="micro"
                        className={cn(
                            'text-[0.5rem] tracking-[0.12em]',
                            RARITY_TEXT[rarity],
                        )}
                    >
                        {RARITY_LABELS[rarity]}
                    </Eyebrow>
                </div>
                {!compact && (
                    <div className="mt-0.5 line-clamp-2 font-sans text-[0.75rem] font-extrabold uppercase leading-[1.06] tracking-[0.01em] text-cream">
                        {name}
                    </div>
                )}
                {!compact &&
                    (edition != null || (date != null && date !== '')) && (
                        <div className="mt-0.5 font-mono text-[0.5625rem] tabular-nums leading-tight text-ink-on-sky">
                            {edition != null && (
                                <span>
                                    #{edition.index}/{edition.total}
                                </span>
                            )}
                            {edition != null && date != null && date !== '' && (
                                <span className="mx-1 opacity-40">·</span>
                            )}
                            {date != null && date !== '' && <span>{date}</span>}
                        </div>
                    )}
            </div>

            {/* Thread-band rarity accent (Slice 9c) — mirrors Card's, scaled
                down for the mini tile. Additive to the rarity border above,
                not a re-hue. */}
            {!compact && (
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center"
                >
                    <ThreadBandGlyph rarity={rarity} width={44} height={5} />
                </div>
            )}
        </div>
    );
}
