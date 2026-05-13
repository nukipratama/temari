import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';

/**
 * Inertia `Link` wrapped in Framer Motion so callers get `whileTap` /
 * `whileHover` / variants without rewriting the click + prefetch
 * behavior. Use in any place where the link is a meaningful tap target
 * (cards, list rows, paginator buttons) — adds the "presses inward on
 * tap" affordance that addresses the "clickable things feel inert"
 * pain point.
 */
const MotionLink = motion.create(Link);

export default MotionLink;
