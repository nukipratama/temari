import { motion, useMotionValue } from 'framer-motion';
import { cn } from '@/lib/cn';
import type { Rarity } from '@/types/inertia';

interface PackWrapperProps {
    rarity: Rarity;
    /** Fired once the user tears the pack open (drag past threshold, or tap). */
    onOpen: () => void;
    className?: string;
}

// Rarity-tinted foil over the translucent plastic.
const FOIL_TINT: Record<Rarity, string> = {
    common: 'bg-rarity-common/25',
    uncommon: 'bg-rarity-uncommon/25',
    rare: 'bg-rarity-rare/30',
    epic: 'bg-rarity-epic/30',
    legendary: 'bg-rarity-legendary/35',
};

// Drag distance (px) past which the pull commits to opening.
const TEAR_THRESHOLD = 56;

/**
 * The plastic foil cover for the card reveal. The user drags the zip-strip tab
 * across (or taps anywhere) to tear it open; `onOpen` fires and the parent
 * unmounts this with an AnimatePresence peel-away exit. Touch-first: no keyboard
 * gesture (reveals happen on mobile). Reduced-motion is handled by the parent,
 * which skips rendering this entirely.
 */
export default function PackWrapper({ rarity, onOpen, className }: Readonly<PackWrapperProps>) {
    const x = useMotionValue(0);

    const handleDragEnd = (): void => {
        if (x.get() >= TEAR_THRESHOLD) {
            onOpen();
        } else {
            x.set(0);
        }
    };

    return (
        <motion.div
            data-testid="pack-wrapper"
            initial={{ opacity: 1 }}
            exit={{ opacity: 0, x: '60%', rotate: 6, scale: 1.06, transition: { duration: 0.45, ease: [0.4, 0, 0.2, 1] } }}
            className={cn('absolute inset-0 z-20 cursor-pointer select-none overflow-hidden rounded-[16px]', className)}
            onClick={onOpen}
        >
            {/* Frosted plastic over the card + rarity foil tint + a gloss sheen. */}
            <div className="absolute inset-0 bg-cream/70 backdrop-blur-[3px]" />
            <div className={cn('absolute inset-0', FOIL_TINT[rarity])} />
            <span
                aria-hidden
                className="absolute inset-0 rounded-[16px]"
                style={{ background: 'linear-gradient(115deg, transparent 38%, rgba(255,255,255,0.55) 50%, transparent 62%)' }}
            />

            {/* Perforated tear line + draggable zip-strip tab. */}
            <div className="absolute inset-x-0 top-7 flex items-center">
                <div className="h-px flex-1 border-t-2 border-dashed border-ink/20" />
            </div>
            <motion.button
                type="button"
                drag="x"
                dragConstraints={{ left: 0, right: 120 }}
                dragElastic={0.15}
                style={{ x }}
                onDragEnd={handleDragEnd}
                onClick={(e) => {
                    e.stopPropagation();
                    onOpen();
                }}
                aria-label="Tarik buat buka kartu"
                className="focus-ring absolute left-4 top-3 inline-flex items-center gap-1.5 rounded-full bg-sky px-3 py-1.5 font-mono text-[11px] font-bold uppercase tracking-[0.12em] text-cream shadow-lg"
            >
                Tarik <span aria-hidden>→</span>
            </motion.button>

            <div className="absolute inset-x-0 bottom-5 text-center font-mono text-[11px] uppercase tracking-[0.14em] text-ink/60">
                Tarik atau ketuk buat buka
            </div>
        </motion.div>
    );
}
