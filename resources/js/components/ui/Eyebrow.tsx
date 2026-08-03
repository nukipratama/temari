import type { VariantProps } from 'class-variance-authority';

import { type ElementType, type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { eyebrowVariants } from '@/lib/variants';

type EyebrowTag = 'div' | 'span' | 'h3' | 'dt' | 'footer';

interface EyebrowProps extends VariantProps<typeof eyebrowVariants> {
    token: 'micro' | 'small' | 'hero';
    as?: EyebrowTag;
    children: ReactNode;
    className?: string;
}

export default function Eyebrow({
    as,
    token,
    tone,
    className,
    children,
}: Readonly<EyebrowProps>) {
    const Tag = (as ?? 'div') as ElementType;
    return (
        <Tag className={cn(eyebrowVariants({ token, tone }), className)}>
            {children}
        </Tag>
    );
}
