import { type ElementType, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { eyebrowVariants } from '@/lib/variants';
import type { VariantProps } from 'class-variance-authority';

type EyebrowTag = 'div' | 'span' | 'h3' | 'dt' | 'footer';

interface EyebrowProps extends VariantProps<typeof eyebrowVariants> {
    as?: EyebrowTag;
    children: ReactNode;
    className?: string;
}

export default function Eyebrow({ as, size, tracking, weight, tone, className, children }: Readonly<EyebrowProps>) {
    const Tag = (as ?? 'div') as ElementType;
    return <Tag className={cn(eyebrowVariants({ size, tracking, weight, tone }), className)}>{children}</Tag>;
}
