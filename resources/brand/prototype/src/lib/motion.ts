import type { Transition, Variants } from 'framer-motion';

const enterEase: Transition = { duration: 0.32, ease: [0.22, 1, 0.36, 1] };

export const fadeInUp: Variants = {
    hidden: { opacity: 0, y: 8 },
    visible: { opacity: 1, y: 0, transition: enterEase },
};

const LIVELY_EASE: [number, number, number, number] = [0.34, 1.1, 0.64, 1];

export const pressShrink = {
    scale: 0.97,
    opacity: 0.7,
    transition: { duration: 0.15, ease: LIVELY_EASE },
};

export const countUpEase: [number, number, number, number] = [0.16, 1, 0.3, 1];

export const staggerContainer: Variants = {
    hidden: {},
    visible: { transition: { staggerChildren: 0.06, delayChildren: 0.05 } },
};

export const tabIconPop: Variants = {
    idle: { scale: 1 },
    active: {
        scale: [1, 1.18, 1],
        transition: { duration: 0.34, ease: LIVELY_EASE, times: [0, 0.4, 1] },
    },
};
