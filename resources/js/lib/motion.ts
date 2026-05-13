import type { Transition, Variants } from 'framer-motion';

/**
 * Shared Framer Motion variants + transitions. Components import these
 * to keep timing/easing consistent across the app instead of inlining
 * magic numbers everywhere.
 *
 * Reduced-motion is handled by FM internally (transitions degrade to
 * instant), so these variants are safe to use unconditionally — the
 * `useReducedMotion()` hook is only needed for opt-in effects like
 * count-up animations that don't go through `motion.div`.
 */

const enterEase: Transition = {
    duration: 0.32,
    ease: [0.22, 1, 0.36, 1], // out-expo-ish — gentle deceleration
};

export const fadeInUp: Variants = {
    hidden: { opacity: 0, y: 8 },
    visible: { opacity: 1, y: 0, transition: enterEase },
};

export const staggerChildren: Variants = {
    hidden: {},
    visible: {
        transition: { staggerChildren: 0.06, delayChildren: 0.04 },
    },
};

export const staggerItem: Variants = {
    hidden: { opacity: 0, y: 6 },
    visible: { opacity: 1, y: 0, transition: enterEase },
};

/**
 * Mascot idle breath — gentle 2.5s pulse. FM auto-disables under
 * reduced-motion.
 */
export const breath: Variants = {
    idle: {
        scale: [1, 1.02, 1],
        transition: { duration: 2.5, repeat: Infinity, ease: 'easeInOut' },
    },
};

/**
 * Mood-specific idle animations. Replaces the one-size-fits-all `breath`
 * for hero placements where the mascot's mood should feel embodied:
 *   - glow/bouncy: light hop
 *   - dim: slow sleepy sway
 *   - spinning: actual gentle rotation
 *   - wobble: tilt → recover (woozy/cooked)
 *   - squished/default: standard breath
 *
 * Each variant repeats forever. Apply with `animate="idle"` on a motion
 * wrapper; FM short-circuits all of them under reduced-motion.
 */
export const idleByMood: Record<string, Variants> = {
    glow: {
        idle: {
            y: [0, -3, 0],
            scale: [1, 1.025, 1],
            transition: { duration: 1.6, repeat: Infinity, ease: 'easeInOut' },
        },
    },
    bouncy: {
        idle: {
            y: [0, -4, 0],
            transition: { duration: 1.2, repeat: Infinity, ease: 'easeOut' },
        },
    },
    dim: {
        idle: {
            rotate: [-2, 2, -2],
            transition: { duration: 4.5, repeat: Infinity, ease: 'easeInOut' },
        },
    },
    spinning: {
        idle: {
            rotate: [0, 360],
            transition: { duration: 12, repeat: Infinity, ease: 'linear' },
        },
    },
    wobble: {
        idle: {
            rotate: [-3, 3, -3, 0],
            transition: { duration: 1.4, repeat: Infinity, repeatDelay: 1.5, ease: 'easeInOut' },
        },
    },
    squished: breath,
};

/** Quick wave: ±12° rotation triple. ~600ms — one-shot tap reaction. */
export const wave: Variants = {
    rest: { rotate: 0 },
    play: {
        rotate: [0, -12, 12, -8, 0],
        transition: { duration: 0.6, ease: 'easeInOut' },
    },
};

/** Quick hop: pop up then return. ~500ms — one-shot tap reaction. */
export const hop: Variants = {
    rest: { y: 0 },
    play: {
        y: [0, -14, 0],
        transition: { duration: 0.5, ease: 'easeOut' },
    },
};

/** Quick spin: full rotation. ~800ms — one-shot tap reaction. */
export const spin: Variants = {
    rest: { rotate: 0 },
    play: {
        rotate: [0, 360],
        transition: { duration: 0.8, ease: 'easeInOut' },
    },
};

/** One-shot reactions cycled by [[TemariMascot]] on each tap. */
export const tapReactions = [wave, hop, spin] as const;

/** Accessory mount: scale + fade-in. */
export const accessoryPop: Variants = {
    hidden: { scale: 0, opacity: 0, rotate: -20 },
    visible: {
        scale: 1,
        opacity: 1,
        rotate: 0,
        transition: { duration: 0.45, ease: 'backOut' },
    },
};

/**
 * Tap feedback for clickable surfaces. Apply via `whileTap={pressShrink}`.
 */
export const pressShrink = { scale: 0.97 };

/**
 * Sidebar slide-from-left drawer (mobile). Closed = off-screen left.
 */
export const drawerSlide: Variants = {
    closed: { x: '-100%', transition: { duration: 0.22, ease: 'easeIn' } },
    open: { x: 0, transition: { duration: 0.28, ease: 'easeOut' } },
};
