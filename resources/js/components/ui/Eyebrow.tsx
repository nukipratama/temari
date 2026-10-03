import type { VariantProps } from 'class-variance-authority';

import { type ElementType, type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { eyebrowVariants } from '@/lib/variants';

type EyebrowTag = 'div' | 'span' | 'h2' | 'h3' | 'dt' | 'footer';

interface EyebrowProps extends VariantProps<typeof eyebrowVariants> {
    token: 'micro' | 'small' | 'hero';
    as?: EyebrowTag;
    /** A hairline rule running from the label to the end of the row. */
    rule?: boolean;
    children: ReactNode;
    className?: string;
}

export default function Eyebrow({
    as,
    token,
    tone,
    rule = false,
    className,
    children,
}: Readonly<EyebrowProps>) {
    const Tag = (as ?? 'div') as ElementType;

    if (!rule) {
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
                'flex items-center gap-3',
                className,
            )}
        >
            <span>{children}</span>
            <span aria-hidden className="h-px flex-1 bg-current opacity-20" />
        </Tag>
    );
}
