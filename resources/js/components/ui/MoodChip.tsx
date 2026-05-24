import { cn } from '@/lib/cn';
import { MOOD_FILL, MOOD_LABEL, MOOD_SOFT_FILL } from '@/lib/mood';
import type { Mood } from '@/types/inertia';

interface MoodChipProps {
    mood: Mood;
    label?: string;
    size?: 'sm' | 'md';
    onSky?: boolean;
    className?: string;
}

export default function MoodChip({
    mood,
    label,
    size = 'sm',
    onSky = false,
    className,
}: Readonly<MoodChipProps>) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full font-mono font-semibold uppercase tracking-[0.08em]',
                size === 'sm' ? 'px-2.5 py-1 text-[10px]' : 'px-3 py-1.5 text-[11px]',
                onSky ? 'bg-cream/10 text-cream' : cn(MOOD_SOFT_FILL[mood], 'text-ink'),
                className,
            )}
        >
            <span aria-hidden className={cn('h-2 w-2 rounded-full', MOOD_FILL[mood])} />
            {label ?? MOOD_LABEL[mood]}
        </span>
    );
}
