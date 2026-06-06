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
    { rotate: [0, -6, 8, -3, 0] },                       // head shake (assertive)
    { rotate: [0, 10, 10, 0], y: [0, -2, -2, 0] },       // tilt right & hold
    { rotate: [0, -10, -10, 0], y: [0, -2, -2, 0] },     // tilt left & hold
    { y: [0, -16, 0] },                                   // mini hop
    { scale: [1, 1.12, 1] },                              // pop
    { x: [0, -6, 6, 0] },                                 // sideways wiggle
    { rotate: [0, 4, -4, 4, 0], y: [0, -3, 0] },         // bobble (head shake + small hop)
    { y: [0, -6, -10, -6, 0], scale: [1, 1.04, 1.06, 1.04, 1] }, // stretch reach-up
    { y: [0, -10, 0, -10, 0] },                          // double-hop (warm-up)
    { rotate: [0, -3, 3, 0], y: [0, 2, -8, 0] },         // crouch-then-pop
];

export const pressShrink = { scale: 0.97 };
