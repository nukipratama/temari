import { Link } from '@inertiajs/react';
import { type MouseEventHandler, type ReactNode } from 'react';

import { cn } from '@/lib/cn';
import { cardVariants } from '@/lib/variants';

import { type CardPadding, type CardTone } from './Card';

interface LinkCardProps {
    href: string;
    /** Default 'card'. */
    tone?: CardTone;
    /** Default 'card' — the --pad-card role. */
    padding?: CardPadding;
    onClick?: MouseEventHandler<Element>;
    className?: string;
    children: ReactNode;
}

export default function LinkCard({
    href,
    tone = 'card',
    padding = 'card',
    onClick,
    className,
    children,
}: Readonly<LinkCardProps>) {
    return (
        <Link
            href={href}
            onClick={onClick}
            className={cn(
                cardVariants({ tone, padding }),
                'block focus-ring',
                className,
            )}
        >
            {children}
        </Link>
    );
}
