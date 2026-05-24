import { type ReactNode } from 'react';
import TemariGlyph from './TemariGlyph';
import { cn } from '@/lib/cn';

interface VoiceCardProps {
    children: ReactNode;
    attribution?: string;
    /** Pose hint shown in the attribution line (e.g. "pumped", "reading"). */
    pose?: string;
    onSky?: boolean;
    className?: string;
}

export default function VoiceCard({
    children,
    attribution = 'Temari',
    pose,
    onSky = false,
    className,
}: Readonly<VoiceCardProps>) {
    return (
        <div className={cn('flex items-start gap-4', className)}>
            <TemariGlyph size={36} ringColor="horizon" />
            <div className="min-w-0 flex-1">
                <p
                    className={cn(
                        'font-display text-[22px] italic leading-snug tracking-[-0.005em]',
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
        </div>
    );
}
