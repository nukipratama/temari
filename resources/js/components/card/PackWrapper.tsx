import { motion, useMotionValue, useTransform } from 'framer-motion';
import { cn } from '@/lib/cn';
import { BunnyGlyph } from '@/components/BrandMark';
import { RARITY_HEX } from '@/lib/runcard';
import type { Rarity } from '@/types/inertia';

interface PackWrapperProps {
    rarity: Rarity;
    /** Fired once the user tears the pack open (drag past threshold, or tap). */
    onOpen: () => void;
    className?: string;
}

// Drag distance (px) past which the pull commits to opening, and the full pull
// span the peel transforms are mapped across.
const TEAR_THRESHOLD = 56;
const DRAG_MAX = 120;

// Glow strength climbs with rarity so a legendary pack visibly radiates more
// than a common one even before it's torn.
const GLOW_ALPHA: Record<Rarity, number> = {
    common: 0.16,
    uncommon: 0.24,
    rare: 0.34,
    epic: 0.46,
    legendary: 0.6,
};

// A few bunnies tiled into a sealed card-back so the card underneath is hidden
// (surprise preserved) and the pack reads as a real foil, not a smudge. Stable
// keys (not array indices) keep the tiles identity-stable across renders.
const BACK_TILES = ['t0', 't1', 't2', 't3', 't4', 't5', 't6', 't7', 't8', 't9', 't10', 't11'];

/**
 * The foil cover for the card reveal. The whole foil is draggable: as the user
 * pulls the zip tab to the right it peels away with the finger (fading + tilting
 * via the shared drag motion value); releasing past the threshold (or a tap)
 * fires `onOpen` and the parent unmounts this with a peel-away exit. While
 * sealed, a holographic sheen sweeps across and a rarity glow pulses underneath.
 * Touch-first (no keyboard gesture); reduced-motion is handled by the parent,
 * which skips rendering this entirely.
 */
export default function PackWrapper({ rarity, onOpen, className }: Readonly<PackWrapperProps>) {
    const x = useMotionValue(0);
    // The foil peels with the pull: it fades and tilts as x grows.
    const foilOpacity = useTransform(x, [0, DRAG_MAX], [1, 0.12]);
    const foilRotate = useTransform(x, [0, DRAG_MAX], [0, 7]);
    const rarityHex = RARITY_HEX[rarity];
    const glow = GLOW_ALPHA[rarity];

    const handleDragEnd = (): void => {
        if (x.get() >= TEAR_THRESHOLD) {
            onOpen();
        }
        // Anything short of the threshold springs back via dragSnapToOrigin.
    };

    return (
        <motion.div
            data-testid="pack-wrapper"
            drag="x"
            dragConstraints={{ left: 0, right: DRAG_MAX }}
            dragElastic={0.1}
            dragSnapToOrigin
            style={{ x }}
            onDragEnd={handleDragEnd}
            onClick={onOpen}
            aria-label="Tarik atau ketuk buat buka kartu"
            initial={{ opacity: 1 }}
            exit={{ opacity: 0, x: '60%', rotate: 10, scale: 1.06, transition: { duration: 0.45, ease: [0.4, 0, 0.2, 1] } }}
            className={cn('absolute inset-0 z-20 cursor-grab select-none overflow-hidden rounded-[16px] active:cursor-grabbing', className)}
        >
            {/* The peeling foil group — everything that fades/tilts as you pull. */}
            <motion.div style={{ opacity: foilOpacity, rotate: foilRotate }} className="absolute inset-0 origin-top">
                {/* Opaque sealed base so the card can't be read through it. */}
                <div className="absolute inset-0 bg-cream-deep" />
                <div className={cn('absolute inset-0', rarityFoilTint(rarity))} />

                {/* Card-back motif: tiled bunnies, faint, so it reads as a pack back. */}
                <div aria-hidden className="absolute inset-0 flex flex-wrap content-center items-center justify-center gap-5 p-6 opacity-[0.09]">
                    {BACK_TILES.map((id, i) => (
                        <span key={id} className={i % 2 === 0 ? 'rotate-6' : '-rotate-6'}>
                            <BunnyGlyph size={34} tone="ink" />
                        </span>
                    ))}
                </div>

                {/* Rarity glow pulsing underneath — something rare waits inside. */}
                <motion.span
                    aria-hidden
                    className="absolute inset-0"
                    style={{ background: `radial-gradient(circle at 50% 44%, ${rarityHex}, transparent 62%)` }}
                    animate={{ opacity: [glow * 0.45, glow, glow * 0.45] }}
                    transition={{ duration: 2.4, repeat: Infinity, ease: 'easeInOut' }}
                />

                {/* Holographic sheen sweeping across on a loop. */}
                <motion.span
                    aria-hidden
                    className="absolute inset-y-0 -left-1/3 w-1/3"
                    style={{ background: 'linear-gradient(115deg, transparent, rgba(255,255,255,0.7), transparent)' }}
                    animate={{ x: ['0%', '440%'] }}
                    transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut', repeatDelay: 0.5 }}
                />

                {/* Perforated tear line. */}
                <div className="absolute inset-x-0 top-7 flex items-center">
                    <div className="h-px flex-1 border-t-2 border-dashed border-ink/20" />
                </div>
            </motion.div>

            {/* Zip-strip tab — the visual pull affordance; stays crisp while peeling. */}
            <span
                aria-hidden
                className="absolute left-4 top-3 inline-flex items-center gap-1.5 rounded-full bg-sky px-3 py-1.5 font-mono text-[11px] font-bold uppercase tracking-[0.12em] text-cream shadow-lg"
            >
                Tarik <span aria-hidden>→</span>
            </span>

            <div className="absolute inset-x-0 bottom-5 text-center font-mono text-[11px] uppercase tracking-[0.14em] text-ink/60">
                Tarik atau ketuk buat buka
            </div>
        </motion.div>
    );
}

// Rarity-tinted foil over the sealed base.
function rarityFoilTint(rarity: Rarity): string {
    const tints: Record<Rarity, string> = {
        common: 'bg-rarity-common/25',
        uncommon: 'bg-rarity-uncommon/25',
        rare: 'bg-rarity-rare/30',
        epic: 'bg-rarity-epic/30',
        legendary: 'bg-rarity-legendary/35',
    };
    return tints[rarity];
}
