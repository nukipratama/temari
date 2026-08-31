import type { ReactNode } from 'react';

import FaceIcon from '@/components/temari/FaceIcon';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';

interface EmptyPanelProps {
    /** Draw Temari's face above the copy, as the prototype's empty states do. */
    face?: boolean;
    title: string;
    body?: string;
    action?: ReactNode;
    as?: 'div' | 'section' | 'article' | 'aside' | 'li';
    className?: string;
}

export default function EmptyPanel({
    face = false,
    title,
    body,
    action,
    as = 'div',
    className,
}: Readonly<EmptyPanelProps>) {
    return (
        <Card
            as={as}
            tone="empty"
            padding="hero"
            className={cn('text-center', className)}
        >
            {face && <FaceIcon size={48} />}
            <p
                className={cn(
                    'font-serif italic text-2xl text-text-2',
                    face && 'mt-4',
                )}
            >
                {title}
            </p>
            {body && (
                <p className="mt-2 font-sans text-sm text-text-2">{body}</p>
            )}
            {action}
        </Card>
    );
}
