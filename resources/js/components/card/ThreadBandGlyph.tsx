import type { Rarity } from '@/types/inertia';

import { RARITY_BAND_COUNT, RARITY_HEX, threadBandLines } from '@/lib/runcard';

interface ThreadBandGlyphProps {
    rarity: Rarity;
    width?: number;
    height?: number;
    className?: string;
}

const VB_W = 60;
const VB_H = 10;

/**
 * The thread-band rarity accent (Slice 9c): a small stitched cluster whose
 * density scales with tier (Common=1 stitch .. Legendary=5), the top two
 * tiers' extra stitches leaning the opposite way to cross the rest. Additive
 * to the existing rarity border/glow, not a re-hue — meant to sit in a
 * card's own border padding, clear of any dynamic content. Geometry from
 * `threadBandLines` in lib/runcard, shared with the canvas share-card
 * renderer so both draw the same pattern. Stroke color always follows the
 * rarity's own tint (`RARITY_HEX`), so callers only need to pass `rarity`.
 */
export default function ThreadBandGlyph({
    rarity,
    width = 60,
    height = 10,
    className,
}: Readonly<ThreadBandGlyphProps>) {
    const lines = threadBandLines(RARITY_BAND_COUNT[rarity]);
    const color = RARITY_HEX[rarity];
    return (
        <svg
            aria-hidden
            width={width}
            height={height}
            viewBox={`0 0 ${VB_W} ${VB_H}`}
            className={className}
        >
            {lines.map((l) => (
                <line
                    key={`${l.x1}-${l.y1}`}
                    x1={l.x1 * VB_W}
                    y1={l.y1 * VB_H}
                    x2={l.x2 * VB_W}
                    y2={l.y2 * VB_H}
                    stroke={color}
                    strokeWidth={1.6}
                    strokeLinecap="round"
                    opacity={l.opacity}
                />
            ))}
        </svg>
    );
}
