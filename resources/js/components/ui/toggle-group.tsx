import { Toggle as TogglePrimitive } from '@base-ui/react/toggle';
import { ToggleGroup as ToggleGroupPrimitive } from '@base-ui/react/toggle-group';
import { cva } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/cn';

type ToggleGroupSize = 'sm' | 'md';

const ToggleGroupSizeContext = React.createContext<ToggleGroupSize>('sm');

const toggleGroupItemVariants = cva(
    'focus-ring pressable inline-flex items-center justify-center gap-1.5 rounded-full border border-border text-label-micro text-text-3 transition hover:border-horizon/60 hover:text-foreground disabled:pointer-events-none disabled:opacity-60 data-[pressed]:border-horizon data-[pressed]:bg-horizon/[0.18] data-[pressed]:text-horizon-ink',
    {
        variants: {
            size: {
                sm: 'min-h-8 px-3 py-1.5',
                md: 'min-h-11 px-3 py-2',
            },
        },
    },
);

type ToggleGroupProps<Value extends string> = Omit<
    ToggleGroupPrimitive.Props<Value>,
    'className' | 'defaultValue' | 'multiple' | 'onValueChange' | 'value'
> & {
    /** The chosen item, or null when none matches (a custom value). */
    value: Value | null;
    /** Fires with the newly chosen item; pressing the chosen one again keeps it. Omit it when each item is a link. */
    onValueChange?: (value: Value) => void;
    /** Default 'sm' for inline filters; 'md' is the 44px touch size for sheets and settings. */
    size?: ToggleGroupSize;
    className?: string;
};

function ToggleGroup<Value extends string>({
    value,
    onValueChange,
    size = 'sm',
    className,
    children,
    ...props
}: ToggleGroupProps<Value>) {
    return (
        <ToggleGroupPrimitive<Value>
            data-slot="toggle-group"
            value={value === null ? [] : [value]}
            onValueChange={(next) => {
                const [chosen] = next;
                if (chosen !== undefined) {
                    onValueChange?.(chosen);
                }
            }}
            className={cn('flex flex-wrap gap-1.5', className)}
            {...props}
        >
            <ToggleGroupSizeContext value={size}>
                {children}
            </ToggleGroupSizeContext>
        </ToggleGroupPrimitive>
    );
}

function ToggleGroupItem({
    className,
    ...props
}: Omit<TogglePrimitive.Props, 'className'> & { className?: string }) {
    const size = React.use(ToggleGroupSizeContext);

    return (
        <TogglePrimitive
            data-slot="toggle-group-item"
            className={cn(toggleGroupItemVariants({ size }), className)}
            {...props}
        />
    );
}

export { ToggleGroup, ToggleGroupItem };
