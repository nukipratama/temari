import { useRef, useState } from 'react';
import { motion, type Variants } from 'framer-motion';
import { cn } from '@/lib/cn';
import { MASCOT_GRADIENT, moodRing, moodSigilColor } from '@/lib/mood';
import { breath, idleByMood, tapReactions } from '@/lib/motion';
import { useGaze } from '@/hooks/useGaze';
import TemariBody from './TemariBody';
import TemariFace from './TemariFace';
import TemariSigil from './TemariSigil';
import type { Mood } from '@/types/inertia';

function resolveIdle(idle: 'none' | 'breath' | 'mood', mood: Mood): Variants | null {
    if (idle === 'none') return null;
    if (idle === 'breath') return breath;
    return idleByMood[mood] ?? breath;
}

function resolveActiveState(playingReaction: boolean, idleVariants: Variants | null): string | undefined {
    if (playingReaction) return 'play';
    if (idleVariants !== null) return 'idle';
    return undefined;
}

interface TemariMascotProps {
    mood: Mood;
    sigilPattern?: string;
    accessory?: string | null;
    sizeClass?: string;
    sigilPixels?: number;
    ringClass?: string;
    /** Idle animation: `none` (static), `breath` (uniform pulse), `mood` (mood-aware idle). */
    idle?: 'none' | 'breath' | 'mood';
    /** When true, tracks the cursor with the eyes (desktop only). */
    gazeTracking?: boolean;
    /** When true, tapping the mascot plays a one-shot reaction (wave / hop / spin). */
    interactive?: boolean;
    /** When true, container scales + tilts on hover. */
    hoverable?: boolean;
    className?: string;
    'aria-label'?: string;
}

/**
 * Full mascot composition with optional character-y behaviors:
 *
 *   - `idle="mood"` — mood-aware ambient animation (bouncy hops, dim sways, etc).
 *   - `gazeTracking` — eyes follow the cursor on desktop ([[useGaze]]).
 *   - `interactive` — tap to cycle through wave/hop/spin reactions.
 *   - `hoverable` — container scales + tilts on hover.
 *
 * Defaults are off so non-hero placements (list rows, strips) stay
 * static and cheap. The dashboard hero (BriefingCard) opts into all
 * four for a "feels like a character" effect; smaller placements
 * inherit the static look.
 */
export default function TemariMascot({
    mood,
    sigilPattern = 'dddd',
    accessory = null,
    sizeClass = 'h-32 w-32',
    sigilPixels = 128,
    ringClass = 'ring-4',
    idle = 'none',
    gazeTracking = false,
    interactive = false,
    hoverable = false,
    className,
    'aria-label': ariaLabel,
}: Readonly<TemariMascotProps>) {
    const color = moodSigilColor(mood);
    const wrapperRef = useRef<HTMLDivElement>(null);
    const gaze = useGaze(wrapperRef, { range: 240, falloff: 200 });

    const [reactionIdx, setReactionIdx] = useState<number | null>(null);
    const playTapReaction = () => {
        setReactionIdx((i) => (i === null ? 0 : (i + 1) % tapReactions.length));
    };

    const idleVariants = resolveIdle(idle, mood);
    const reactionVariants = reactionIdx === null ? null : tapReactions[reactionIdx];
    const playingReaction = reactionVariants !== null;
    const activeVariants = playingReaction ? reactionVariants : idleVariants;
    const activeState = resolveActiveState(playingReaction, idleVariants);

    const hoverProps = hoverable ? { whileHover: { scale: 1.06, rotate: -2 }, whileTap: { scale: 0.96 } } : {};
    const clickProps = interactive
        ? { onClick: playTapReaction, role: 'button', tabIndex: 0, 'aria-pressed': false }
        : {};

    return (
        <motion.div
            ref={wrapperRef}
            {...hoverProps}
            {...clickProps}
            aria-label={ariaLabel}
            className={cn(
                'relative flex items-center justify-center rounded-full',
                ringClass,
                MASCOT_GRADIENT,
                moodRing(mood),
                sizeClass,
                interactive ? 'cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500' : '',
                className,
            )}
        >
            {/* Inner motion layer carries the idle + tap reaction animations so
                the hover scale on the outer wrapper doesn't fight with the
                inner one. Reaction takes priority over idle once triggered. */}
            <motion.div
                className="absolute inset-0 flex items-center justify-center"
                variants={activeVariants ?? undefined}
                animate={activeState}
                /* v8 ignore next 3 — fires from FM's RAF loop; jsdom never invokes it. */
                onAnimationComplete={() => {
                    if (playingReaction) setReactionIdx(null);
                }}
            >
                <TemariBody size={sigilPixels} color={color} className="absolute inset-0 opacity-80" />
                <TemariFace
                    mood={mood}
                    size={sigilPixels}
                    color={color}
                    gaze={gazeTracking ? gaze : { x: 0, y: 0 }}
                    className="absolute inset-0"
                />
                <TemariSigil
                    pattern={sigilPattern}
                    size={sigilPixels}
                    color={color}
                    accessory={accessory}
                    className="absolute inset-0 mix-blend-multiply dark:mix-blend-screen"
                />
            </motion.div>
        </motion.div>
    );
}
