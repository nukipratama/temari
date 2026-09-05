import type { ReactNode } from 'react';

import FaceIcon from '@/components/temari/FaceIcon';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';

interface EmptyPanelProps {
    /** Draw Temari's face beside or above the copy, as the prototype's empty states do. */
    face?: boolean;
    /**
     * The prototype draws its face at 40 everywhere except Plan's whole-page
     * "no plan yet.", which takes 48. Both axes are per-screen; see PS12.
     */
    faceSize?: 40 | 48;
    /** Face left with the copy beside it, or face on top with everything centred. */
    layout?: 'centered' | 'horizontal';
    title: string;
    body?: string;
    action?: ReactNode;
    as?: 'div' | 'section' | 'article' | 'aside' | 'li';
    className?: string;
}

export default function EmptyPanel({
    face = false,
    faceSize = 40,
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
            {face && <FaceIcon size={faceSize} />}
            <div className={cn(horizontal && 'min-w-0')}>
                <p
                    className={cn(
                        'font-serif italic text-2xl text-text-2',
                        face && !horizontal && 'mt-4',
                    )}
                >
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
