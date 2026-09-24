import type { ReactNode } from 'react';

import TemariMascot from '@/components/temari/TemariMascot';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';

interface EmptyPanelProps {
    /** Draw Temari dozing beside the copy. */
    face?: boolean;
    /** Temari left with the copy beside it, or inline with a centred title. */
    layout?: 'centered' | 'horizontal';
    title: string;
    body?: string;
    action?: ReactNode;
    as?: 'div' | 'section' | 'article' | 'aside' | 'li';
    className?: string;
}

export default function EmptyPanel({
    face = false,
    layout = 'centered',
    title,
    body,
    action,
    as = 'div',
    className,
}: Readonly<EmptyPanelProps>) {
    const horizontal = layout === 'horizontal';

    return (
        <Card
            as={as}
            tone="empty"
            padding="hero"
            className={cn(
                horizontal
                    ? 'flex items-center gap-3.5 text-left'
                    : 'text-center',
                className,
            )}
        >
            {face && horizontal && <TemariMascot pose="sleepy" size={40} />}
            <div className={cn(horizontal && 'min-w-0')}>
                <p
                    className={cn(
                        'text-2xl text-text-2',
                        face &&
                            !horizontal &&
                            'inline-flex items-center gap-2.5',
                    )}
                >
                    {face && !horizontal && (
                        <TemariMascot pose="sleepy" size={36} />
                    )}
                    {title}
                </p>
                {body && (
                    <p className="mt-2 font-sans text-sm text-text-2">{body}</p>
                )}
                {action}
            </div>
        </Card>
    );
}
