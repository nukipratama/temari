import type { VariantProps } from 'class-variance-authority';

import { type ElementType, type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { eyebrowVariants } from '@/lib/variants';

type EyebrowTag = 'div' | 'span' | 'h2' | 'h3' | 'dt' | 'footer';

interface EyebrowProps extends VariantProps<typeof eyebrowVariants> {
    token: 'micro' | 'small' | 'hero';
    as?: EyebrowTag;
    /** A leading dot in the label colour. */
    dot?: boolean;
    /** A hairline rule running from the label to the end of the row. */
    rule?: boolean;
    children: ReactNode;
    className?: string;
}

export default function Eyebrow({
    as,
    token,
    tone,
    dot = false,
    rule = false,
    className,
    children,
}: Readonly<EyebrowProps>) {
    const Tag = (as ?? 'div') as ElementType;

    if (!dot && !rule) {
        return (
            <Tag className={cn(eyebrowVariants({ token, tone }), className)}>
                {children}
            </Tag>
        );
    }

    return (
        <Tag
            className={cn(
                eyebrowVariants({ token, tone }),
                'flex items-center',
                rule ? 'gap-3' : 'gap-1.5',
                className,
            )}
        >
            {dot && (
                <span
                    aria-hidden
                    className="size-1.5 shrink-0 rounded-full bg-current"
                />
            )}
            <span>{children}</span>
            {rule && (
                <span
                    aria-hidden
                    className="h-px flex-1 bg-current opacity-20"
                />
            )}
        </Tag>
    );
}
