import { useRender } from '@base-ui/react/use-render';

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

export { Card };
