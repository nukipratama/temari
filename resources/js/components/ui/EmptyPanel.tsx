import type { ReactNode } from 'react';

import type { TemariPose } from '@/components/temari/TemariProto';

import Temari from '@/components/temari/Temari';
import Card from '@/components/ui/Card';
import { cn } from '@/lib/cn';

interface EmptyPanelProps {
    pose?: TemariPose;
    title: string;
    body?: string;
    action?: ReactNode;
    className: string;
}

export default function EmptyPanel({
    pose,
    title,
    body,
    action,
    className,
}: Readonly<EmptyPanelProps>) {
    return (
        <Card
            tone="empty"
            padding="hero"
            className={cn('text-center', className)}
        >
            {pose && <Temari pose={pose} size={128} animate />}
            <p
                className={cn(
                    'font-display italic text-2xl text-ink-2',
                    pose && 'mt-4',
                )}
            >
                {title}
            </p>
            {body && (
                <p className="mt-2 font-sans text-sm text-ink-2">{body}</p>
            )}
            {action}
        </Card>
    );
}
