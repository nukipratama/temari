import type { ReactNode } from 'react';
import Card from '@/components/ui/Card';
import Temari from '@/components/temari/Temari';
import type { TemariPose } from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';

interface EmptyPanelProps {
    pose?: TemariPose;
    title: string;
    body?: string;
    action?: ReactNode;
    className: string;
    titleClassName?: string;
    bodyClassName?: string;
}

export default function EmptyPanel({
    pose,
    title,
    body,
    action,
    className,
    titleClassName,
    bodyClassName,
}: Readonly<EmptyPanelProps>) {
    return (
        <Card tone="empty" padding="lg" className={cn('text-center', className)}>
            {pose && <Temari pose={pose} size={128} animate />}
            <p className={cn('font-display italic text-2xl text-ink-2', pose && 'mt-4', titleClassName)}>
                {title}
            </p>
            {body && (
                <p className={cn('mt-2 font-sans text-sm text-ink-2', bodyClassName)}>{body}</p>
            )}
            {action}
        </Card>
    );
}
