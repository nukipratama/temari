import { createContext, useContext, type ReactNode } from 'react';

import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Set by `<CompactPanels>` so every `TrendPanel` inside reads as supporting
 * evidence for the narration headline rather than an equal-weight sibling,
 * without threading a prop through each of the six section components.
 */
const CompactContext = createContext(false);

export function CompactPanels({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <CompactContext.Provider value={true}>
            {children}
        </CompactContext.Provider>
    );
}

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
    const compact = useContext(CompactContext);
    return (
        <Card
            size={compact ? 'sm' : 'default'}
            className={cn('scroll-mt-20', className)}
        >
            <CardHeader className="gap-2">
                <span className="eyebrow text-[11px] text-ink-3">
                    {eyebrow}
                </span>
                <CardTitle
                    className={cn('display', compact ? 'text-base' : 'text-lg')}
                >
                    {title}
                </CardTitle>
                <CardDescription
                    className={cn(
                        'max-w-prose text-ink-3',
                        compact && 'text-xs',
                    )}
                >
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
