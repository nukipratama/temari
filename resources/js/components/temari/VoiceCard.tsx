import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface VoiceCardProps {
    children: ReactNode;
    attribution?: string;
    /** Pose hint shown in the attribution line (e.g. "pumped", "reading"). */
    pose?: string;
    onSky?: boolean;
    className?: string;
}

/**
 * Quote block in Temari's voice. No avatar — surfaces that need one render
 * the full TemariProto mascot on the same panel; doubling up with a tiny
 * "T" badge is redundant. Use this for the prose only.
 */
export default function VoiceCard({
    children,
    attribution = 'Temari',
    pose,
    onSky = false,
    className,
}: Readonly<VoiceCardProps>) {
    return (
        <div className={cn('min-w-0', className)}>
            <p
                className={cn(
                    'font-display text-quote-lg italic',
                    onSky ? 'text-cream' : 'text-ink',
                )}
            >
                “{children}”
            </p>
            <div
                className={cn(
                    'mt-2 font-mono text-[9px] uppercase tracking-[0.16em]',
                    onSky ? 'text-cream/50' : 'text-ink-3',
                )}
            >
                — {attribution}
                {pose != null && pose !== '' && <> · {pose}</>}
            </div>
        </div>
    );
}
