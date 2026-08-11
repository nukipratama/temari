import type { ComponentProps } from 'react';

import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';

import { pressShrink } from '@/lib/motion';

const MotionAnchor = motion.create(Link);

/**
 * Every MotionLink gets the tier-1 `pressShrink` press feedback by default,
 * matching the `.pressable` CSS primitive's timing so call sites don't each
 * repeat `whileTap={pressShrink}`. Pass a different `whileTap` to override.
 */
export default function MotionLink({
    whileTap = pressShrink,
    ...props
}: ComponentProps<typeof MotionAnchor>) {
    return <MotionAnchor whileTap={whileTap} {...props} />;
}
