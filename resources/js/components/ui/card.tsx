import { useRender } from '@base-ui/react/use-render';
import * as React from 'react';

import { cn } from '@/lib/cn';

export type CardTone = 'default' | 'empty';
export type CardPadding = 'none' | 'panel' | 'card' | 'hero';

const TONE_CLASS: Record<CardTone, string> = {
    default: 'border-border',
    empty: 'border-border-strong',
};

const PADDING_CLASS: Record<CardPadding, string> = {
    none: '',
    panel: 'pad-panel',
    card: 'pad-card',
    hero: 'pad-hero',
};

type CardProps = useRender.ComponentProps<'div'> & {
    /** Default 'default'; 'empty' stands in for content that is not there yet. */
    tone?: CardTone;
    /** Default 'card' — the --pad-card role. */
    padding?: CardPadding;
};

function Card({
    tone = 'default',
    padding = 'card',
    className,
    render,
    ref,
    ...props
}: CardProps) {
    return useRender({
        defaultTagName: 'div',
        render,
        ref,
        props: {
            ...props,
            'data-slot': 'card',
            'data-tone': tone,
            className: cn(
                'rounded-panel border bg-card text-card-foreground shadow-e1',
                TONE_CLASS[tone],
                PADDING_CLASS[padding],
                className,
            ),
        },
    });
}

function CardHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-header"
            className={cn(
                'group/card-header @container/card-header grid auto-rows-min items-start gap-1.5 not-last:mb-4 has-data-[slot=card-action]:grid-cols-[1fr_auto] has-data-[slot=card-description]:grid-rows-[auto_auto]',
                className,
            )}
            {...props}
        />
    );
}

function CardTitle({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-title"
            className={cn('font-sans text-base font-medium', className)}
            {...props}
        />
    );
}

function CardDescription({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-description"
            className={cn('text-sm text-muted-foreground', className)}
            {...props}
        />
    );
}

function CardAction({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-action"
            className={cn(
                'col-start-2 row-span-2 row-start-1 self-start justify-self-end',
                className,
            )}
            {...props}
        />
    );
}

function CardContent(props: React.ComponentProps<'div'>) {
    return <div data-slot="card-content" {...props} />;
}

function CardFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-footer"
            className={cn('flex items-center not-first:mt-4', className)}
            {...props}
        />
    );
}

export {
    Card,
    CardHeader,
    CardFooter,
    CardTitle,
    CardAction,
    CardDescription,
    CardContent,
};
