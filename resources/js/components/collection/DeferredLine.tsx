import type { ComponentProps } from 'react';

import { Suspense } from 'react';

import { LazyLine } from '@/components/collection/LazyLineChart';
import Skeleton from '@/components/ui/Skeleton';
import { useAfterPaint } from '@/hooks/useAfterPaint';

export default function DeferredLine(props: ComponentProps<typeof LazyLine>) {
    const ready = useAfterPaint();
    const fallback = <Skeleton className="h-full w-full rounded-lg" />;

    return (
        <Suspense fallback={fallback}>
            {ready ? <LazyLine {...props} /> : fallback}
        </Suspense>
    );
}
