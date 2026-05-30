import { type ReactNode } from 'react';
import { motion } from 'framer-motion';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

interface PageContainerProps {
    children: ReactNode;
    className?: string;
    /** Skip the fadeInUp entrance (renders a plain div). Default: animated. */
    static?: boolean;
}

/**
 * Standard page shell: centers content and caps its width so it stops
 * sprawling edge-to-edge on large screens. The cap eases up at `2xl`
 * (1440 → 1680) so a 2K panel never feels marooned in empty gutters.
 * Carries the shared fadeInUp entrance so pages stay a one-line swap.
 */
const CONTAINER =
    'mx-auto w-full max-w-[1440px] px-5 py-6 sm:px-8 lg:px-14 lg:py-8 2xl:max-w-[1680px] 2xl:px-20';

export default function PageContainer({
    children,
    className,
    static: isStatic = false,
}: Readonly<PageContainerProps>) {
    if (isStatic) {
        return <div className={cn(CONTAINER, className)}>{children}</div>;
    }

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
