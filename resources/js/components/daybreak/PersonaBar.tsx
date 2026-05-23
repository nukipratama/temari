import { cn } from '@/lib/cn';
import type { Mood } from '@/types/inertia';

export interface PersonaSlice {
    mood: Mood;
    count: number;
    percent: number;
}

interface PersonaBarProps {
    mix: ReadonlyArray<PersonaSlice>;
    className?: string;
}

const MOOD_BG: Record<Mood, string> = {
    nyala: 'bg-mood-nyala',
    enteng: 'bg-mood-enteng',
    lemes: 'bg-mood-lemes',
    oleng: 'bg-mood-oleng',
    mumet: 'bg-mood-mumet',
    adem: 'bg-mood-adem',
};

const MOOD_LABEL: Record<Mood, string> = {
    nyala: 'Nyala',
    enteng: 'Enteng',
    lemes: 'Lemes',
    oleng: 'Oleng',
    mumet: 'Mumet',
    adem: 'Adem',
};

export default function PersonaBar({ mix, className }: Readonly<PersonaBarProps>) {
    if (mix.length === 0) {
        return (
            <div className={cn('rounded-2xl border-2 border-dashed border-cream-deep bg-cream/40 px-6 py-5 text-center', className)}>
                <p className="font-display text-base italic text-ink-3">
                    Belum ada cukup lari buat baca personamu.
                </p>
            </div>
        );
    }

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div className="flex h-3 w-full overflow-hidden rounded-full">
                {mix.map((slice) => (
                    <div
                        key={slice.mood}
                        className={cn('h-full', MOOD_BG[slice.mood])}
                        style={{ width: `${slice.percent}%` }}
                        aria-label={`${MOOD_LABEL[slice.mood]} ${slice.percent}%`}
                    />
                ))}
            </div>
            <ul className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs">
                {mix.map((slice) => (
                    <li key={slice.mood} className="inline-flex items-center gap-1.5 text-ink-2">
                        <span aria-hidden className={cn('h-2 w-2 rounded-full', MOOD_BG[slice.mood])} />
                        <span className="font-medium text-ink">{MOOD_LABEL[slice.mood]}</span>
                        <span className="font-mono tabular-nums text-ink-3">{slice.percent.toFixed(1)}%</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
