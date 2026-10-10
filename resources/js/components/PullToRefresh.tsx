import { type ReactNode, Suspense, useRef, useState } from 'react';

import type { PullMotion } from '@/components/PullToRefreshGesture';

import { cn } from '@/lib/cn';
import { lazyIsland } from '@/lib/lazyIsland';

const PullToRefreshGesture = lazyIsland(
    () => import('@/components/PullToRefreshGesture'),
);

interface PullToRefreshProps {
    children: ReactNode;
}

export default function PullToRefresh({
    children,
}: Readonly<PullToRefreshProps>) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [coarse] = useState(
        () => globalThis.matchMedia?.('(pointer: coarse)').matches ?? true,
    );
    const [motion, setMotion] = useState<PullMotion>({
        slide: 0,
        animate: true,
    });

    return (
        <div ref={containerRef} className="relative">
            {coarse && (
                <Suspense fallback={null}>
                    <PullToRefreshGesture
                        containerRef={containerRef}
                        onMotion={setMotion}
                    />
                </Suspense>
            )}
            <div
                data-testid="pull-to-refresh-content"
                className={cn(
                    motion.animate &&
                        'transition-transform duration-200 ease-out',
                )}
                style={
                    motion.slide > 0
                        ? { transform: `translateY(${motion.slide}px)` }
                        : undefined
                }
            >
                {children}
            </div>
        </div>
    );
}
