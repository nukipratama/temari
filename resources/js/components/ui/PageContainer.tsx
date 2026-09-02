import { motion } from 'framer-motion';
import { type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

interface PageContainerProps {
    children: ReactNode;
    className?: string;
}

/**
 * Standard page shell: the single mobile column below 900px, the prototype's
 * centred 760px column above it, and a second step to 1040px at 1280px. The
 * first breakpoint is the prototype's own; the second is ours, because its
 * PhoneFrame never renders past the frame and so draws no reference for a
 * desktop width at all. Carries the shared fadeInUp entrance so pages stay a
 * one-line swap.
 */
const CONTAINER =
    'mx-auto w-full px-4 py-6 min-[900px]:max-w-column min-[900px]:px-6 min-[1280px]:max-w-column-wide';

export default function PageContainer({
    children,
    className,
}: Readonly<PageContainerProps>) {
    return (
        <motion.div
            variants={fadeInUp}
            initial="hidden"
            animate="visible"
            className={cn(CONTAINER, className)}
        >
            {children}
        </motion.div>
    );
}
