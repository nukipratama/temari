import { cn } from '@/lib/cn';
import type { Mood } from '@/types/inertia';

interface MoodChipProps {
    mood: Mood;
    label?: string;
    size?: 'sm' | 'md';
    onSky?: boolean;
    className?: string;
}

const MOOD_DOT: Record<Mood, string> = {
    nyala: 'bg-mood-nyala',
    enteng: 'bg-mood-enteng',
    lemes: 'bg-mood-lemes',
    oleng: 'bg-mood-oleng',
    mumet: 'bg-mood-mumet',
    adem: 'bg-mood-adem',
};

const MOOD_BG: Record<Mood, string> = {
    nyala: 'bg-mood-nyala-bg',
    enteng: 'bg-mood-enteng-bg',
    lemes: 'bg-mood-lemes-bg',
    oleng: 'bg-mood-oleng-bg',
    mumet: 'bg-mood-mumet-bg',
    adem: 'bg-mood-adem-bg',
};

const MOOD_LABEL: Record<Mood, string> = {
    nyala: 'Nyala',
    enteng: 'Enteng',
    lemes: 'Lemes',
    oleng: 'Oleng',
    mumet: 'Mumet',
    adem: 'Adem',
};

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
                onSky ? 'bg-cream/10 text-cream' : cn(MOOD_BG[mood], 'text-ink'),
                className,
            )}
        >
            <span aria-hidden className={cn('h-2 w-2 rounded-full', MOOD_DOT[mood])} />
            {label ?? MOOD_LABEL[mood]}
        </span>
    );
}
