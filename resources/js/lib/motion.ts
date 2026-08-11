import type { Transition, Variants } from 'framer-motion';

const enterEase: Transition = {
    duration: 0.32,
    ease: [0.22, 1, 0.36, 1],
};

export const fadeInUp: Variants = {
    hidden: { opacity: 0, y: 8 },
    visible: { opacity: 1, y: 0, transition: enterEase },
};

// Custom bezier curves — feel more organic than `easeInOut`.
const SOFT_EASE: [number, number, number, number] = [0.4, 0, 0.2, 1]; // material standard
const LIVELY_EASE: [number, number, number, number] = [0.34, 1.1, 0.64, 1]; // slight overshoot

export const breath: Variants = {
    idle: {
        y: [0, -1, -2, -1.5, 0],
        scale: [1, 1.005, 1.01, 1.006, 1],
        transition: {
            duration: 5.5,
            repeat: Infinity,
            ease: SOFT_EASE,
            times: [0, 0.3, 0.5, 0.7, 1],
        },
    },
};

// Mood idle variants are layered (y + rotate + subtle scale) with asymmetric
// keyframes + custom `times` arrays so the loop doesn't read as a metronome
// pulse. Longer periods (6-8s) make the cycle less perceptible.
export const idleByMood: Record<string, Variants> = {
    // Glow = jogging FORWARD-RIGHT. 3D body rotation is applied via CSS
    // perspective/rotateY in the Temari wrapper; here we keep just the
    // subtle breath so the body has a tiny "alive" pulse on top of the 3D pose.
    glow: {
        idle: {
            scale: [1, 1.008, 1],
            transition: {
                duration: 3.5,
                repeat: Infinity,
                ease: SOFT_EASE,
            },
        },
    },
    // Bouncy = high-energy running forward-right. 3D facing handled in mascot
    // wrapper; here we keep the hops + scale pulses.
    bouncy: {
        idle: {
            y: [0, -14, 0, -6, -1, 0],
            scale: [1, 0.96, 1.06, 1, 1.02, 1],
            transition: {
                duration: 1.8,
                repeat: Infinity,
                ease: LIVELY_EASE,
                times: [0, 0.35, 0.55, 0.75, 0.88, 1],
            },
        },
    },
    dim: {
        idle: {
            rotate: [-3.5, 3.5, -2, 4, -3.5],
            y: [0, 2.5, 1, 3, 0],
            transition: {
                duration: 7.5,
                repeat: Infinity,
                ease: SOFT_EASE,
                times: [0, 0.28, 0.5, 0.78, 1],
            },
        },
    },
    spinning: {
        idle: {
            rotate: [0, 360],
            transition: { duration: 12, repeat: Infinity, ease: 'linear' },
        },
    },
    // Wobble = running forward-right but unsteady. 3D facing handled in mascot
    // wrapper; here we keep a small rotate/x wobble = form breaking down.
    wobble: {
        idle: {
            rotate: [-1.5, 1, -1.5, 1, -1.5],
            x: [0, -1, 1, -1, 0],
            transition: {
                duration: 3.6,
                repeat: Infinity,
                ease: SOFT_EASE,
                times: [0, 0.25, 0.5, 0.75, 1],
            },
        },
    },
    squished: {
        idle: {
            scale: [1, 0.94, 1.04, 0.97, 1.02, 1],
            y: [0, 4, 1, 3, 0.5, 0],
            transition: {
                duration: 4.5,
                repeat: Infinity,
                ease: SOFT_EASE,
                times: [0, 0.25, 0.45, 0.65, 0.85, 1],
            },
        },
    },
};

// Random fidget gestures fired by useIdleFidget — picked one-at-a-time so the
// mascot's "breaks" between idle loops feel varied rather than the same shake.
// Each entry is a single animate keyframe array spec passed to Framer Motion.
export type FidgetPattern = {
    rotate?: number[];
    y?: number[];
    x?: number[];
    scale?: number[];
};

export const FIDGET_PATTERNS: ReadonlyArray<FidgetPattern> = [
    { rotate: [0, -6, 8, -3, 0] }, // head shake (assertive)
    { rotate: [0, 10, 10, 0], y: [0, -2, -2, 0] }, // tilt right & hold
    { rotate: [0, -10, -10, 0], y: [0, -2, -2, 0] }, // tilt left & hold
    { y: [0, -16, 0] }, // mini hop
    { scale: [1, 1.12, 1] }, // pop
    { x: [0, -6, 6, 0] }, // sideways wiggle
    { rotate: [0, 4, -4, 4, 0], y: [0, -3, 0] }, // bobble (head shake + small hop)
    { y: [0, -6, -10, -6, 0], scale: [1, 1.04, 1.06, 1.04, 1] }, // stretch reach-up
    { y: [0, -10, 0, -10, 0] }, // double-hop (warm-up)
    { rotate: [0, -3, 3, 0], y: [0, 2, -8, 0] }, // crouch-then-pop
];

// Tier-1 press feedback, shared with the `.pressable` CSS primitive in
// app.css: the same 150ms LIVELY_EASE curve and the same 0.97 scale / 70%
// opacity dip, so a MotionLink and a plain pressable button read as one
// convention instead of two. `scale` is a transform, so
// `MotionConfig reducedMotion="user"` reduces it to an instant snap;
// `opacity` keeps animating, matching how `.pressable` keeps its opacity
// dip out of the `motion-safe:` gate as the surviving reduced-motion cue.
export const pressShrink = {
    scale: 0.97,
    opacity: 0.7,
    transition: { duration: 0.15, ease: LIVELY_EASE },
};

// ─── Tier 1: route transitions (global / subtle) ───────────────────────
// Thin top-of-viewport bar for an in-flight full-page navigation.
// Deliberately its own element rather than a wrapper around page content:
// AppShell's <main> is unkeyed on purpose (keying it once caused 25 card
// remounts on Collection), so this drives a sibling bar instead of
// animating the content subtree. `scaleX` (transform-origin: left) reads
// as filling in; `done` snaps to full width and fades rather than
// looping, so it reads as "arrived" instead of an indefinite spinner.
export const routeProgressBar: Variants = {
    idle: { scaleX: 0, opacity: 0 },
    loading: {
        opacity: 1,
        scaleX: [0, 0.35, 0.6, 0.75],
        transition: {
            duration: 1.4,
            ease: SOFT_EASE,
            times: [0, 0.3, 0.65, 1],
        },
    },
    done: {
        scaleX: 1,
        opacity: [1, 1, 0],
        transition: { duration: 0.4, ease: SOFT_EASE, times: [0, 0.5, 1] },
    },
};

// ─── Tier 2: data reveal ────────────────────────────────────────────────
// Landing curve for animated stat reveals (KPI count-ups via useCountUp).
// Ease-out only, no overshoot — a tallying number should settle exactly on
// its target, not bounce past it the way a tier-1 press or a celebratory
// pop does.
export const countUpEase: [number, number, number, number] = [0.16, 1, 0.3, 1];

// SVG path draw-in for chart lines / route glyphs — `pathLength` ticks from
// 0 to 1 so a line reads as being traced, not popped in whole.
export const drawIn: Variants = {
    hidden: { pathLength: 0, opacity: 0 },
    visible: {
        pathLength: 1,
        opacity: 1,
        transition: {
            pathLength: { duration: 0.9, ease: SOFT_EASE },
            opacity: { duration: 0.3 },
        },
    },
};

// Stagger wrapper for a group of data-reveal children (KPI tiles, stat
// rows). Pair with `fadeInUp` on each child so the group reads as a
// sequence landing in order, not a single flash.
export const staggerContainer: Variants = {
    hidden: {},
    visible: {
        transition: { staggerChildren: 0.06, delayChildren: 0.05 },
    },
};

// Bottom-nav tab icon. iOS tab bars carry the active state with tint alone and
// no travelling indicator, so the only motion here is a short pop on the icon
// that just became active — enough to confirm the tap landed, not a slider.
// The keyframe array replays whenever the variant flips, so it fires on each
// tab change rather than only on mount.
export const tabIconPop: Variants = {
    idle: { scale: 1 },
    active: {
        scale: [1, 1.18, 1],
        transition: { duration: 0.34, ease: LIVELY_EASE, times: [0, 0.4, 1] },
    },
};
