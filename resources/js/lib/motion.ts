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
