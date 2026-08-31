import { motion } from 'framer-motion';
import { type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

interface PageContainerProps {
    children: ReactNode;
    className?: string;
}

/**
 * Standard page shell, reproducing the prototype's own responsive model: the
 * single mobile column below 900px, a centred 760px column above it. One
 * breakpoint, no intermediate steps. Carries the shared fadeInUp entrance so
 * pages stay a one-line swap.
 */
const CONTAINER =
    'mx-auto w-full px-4 py-6 min-[900px]:max-w-[760px] min-[900px]:px-6';

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
