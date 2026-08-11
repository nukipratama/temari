import type { Rarity } from '@/types/inertia';

import { RARITY_BAND_COUNT, threadBandLines } from '@/lib/runcard';

interface ThreadBandGlyphProps {
    rarity: Rarity;
    color: string;
    width?: number;
    height?: number;
    className?: string;
}

/**
 * The thread-band rarity accent (Slice 9c): a small stitched cluster whose
 * density scales with tier (Common=1 stitch .. Legendary=5), the top two
 * tiers' extra stitches leaning the opposite way to cross the rest. Additive
 * to the existing rarity border/glow, not a re-hue — meant to sit in a
 * card's own border padding, clear of any dynamic content. Geometry from
 * `threadBandLines` in lib/runcard, shared with the canvas share-card
 * renderer so both draw the same pattern.
 */
export default function ThreadBandGlyph({
    rarity,
    color,
    width = 60,
    height = 10,
    className,
}: Readonly<ThreadBandGlyphProps>) {
    const lines = threadBandLines(RARITY_BAND_COUNT[rarity]);
    return (
        <svg
            aria-hidden
            width={width}
            height={height}
            viewBox="0 0 60 10"
            className={className}
        >
            {lines.map((l) => (
                <line
                    key={`${l.x1}-${l.y1}`}
                    x1={l.x1 * 60}
                    y1={l.y1 * 10}
                    x2={l.x2 * 60}
                    y2={l.y2 * 10}
                    stroke={color}
                    strokeWidth={1.6}
                    strokeLinecap="round"
                    opacity={l.opacity}
                />
            ))}
        </svg>
    );
}
