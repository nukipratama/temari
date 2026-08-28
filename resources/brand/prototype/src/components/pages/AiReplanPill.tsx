import { Clock } from 'lucide-react';

import { cn } from '@/lib/utils';

export function AiReplanPill({ className }: Readonly<{ className?: string }>) {
    return (
        <span
            className={cn(
                'inline-flex flex-none cursor-not-allowed items-center gap-1.25 rounded-full bg-muted px-2.75 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase',
                className,
            )}
        >
            <Clock className="size-3" aria-hidden />
            next in 5h 40m
        </span>
    );
}
