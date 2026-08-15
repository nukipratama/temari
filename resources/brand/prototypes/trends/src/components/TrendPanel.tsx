import type { ReactNode } from 'react';

import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface TrendPanelProps {
    eyebrow: string;
    title: string;
    /** Plain-language gloss. Every technical term on this page gets one. */
    description: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}

export function TrendPanel({
    eyebrow,
    title,
    description,
    action,
    children,
    className,
}: Readonly<TrendPanelProps>) {
    return (
        <Card className={cn('scroll-mt-20', className)}>
            <CardHeader className="gap-2">
                <span className="eyebrow text-[11px] text-ink-3">
                    {eyebrow}
                </span>
                <CardTitle className="display text-lg">{title}</CardTitle>
                <CardDescription className="max-w-prose text-ink-3">
                    {description}
                </CardDescription>
                {action ? <div className="pt-2">{action}</div> : null}
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
                {children}
            </CardContent>
        </Card>
    );
}
