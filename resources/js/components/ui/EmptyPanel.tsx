import type { ReactNode } from 'react';

import type { TemariPose } from '@/components/temari/TemariProto';

import Temari from '@/components/temari/Temari';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';

interface EmptyPanelProps {
    pose?: TemariPose;
    title: string;
    body?: string;
    action?: ReactNode;
    as?: 'div' | 'section' | 'article' | 'aside' | 'li';
    className?: string;
}

export default function EmptyPanel({
    pose,
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
            {pose && <Temari pose={pose} size={128} animate />}
            <p
                className={cn(
                    'font-serif italic text-2xl text-text-2',
                    pose && 'mt-4',
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
