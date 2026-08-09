import type { Mood } from '@/types/inertia';

import Card from '@/components/ui/Card';
import EmptyPanel from '@/components/ui/EmptyPanel';
import { cn } from '@/lib/cn';
import { MOOD_FILL, MOOD_LABEL } from '@/lib/mood';

export interface PersonaSlice {
    mood: Mood;
    count: number;
    percent: number;
}

interface PersonaBarProps {
    mix: ReadonlyArray<PersonaSlice>;
    /** `onSky` = cream/tinted-glass treatment for use on a dark HeroPanel. */
    onSky?: boolean;
    className?: string;
}

export default function PersonaBar({
    mix,
    onSky = false,
    className,
}: Readonly<PersonaBarProps>) {
    if (mix.length === 0) {
        if (onSky) {
            return (
                <Card
                    tone="sky-glass"
                    padding="md"
                    className={cn('text-center', className)}
                >
                    <p className="font-display text-base italic text-cream/85">
                        Not enough runs yet to read your persona.
                    </p>
                </Card>
            );
        }
        return (
            <EmptyPanel
                title="Not enough runs yet to read your persona."
                className={cn('py-5', className)}
            />
        );
    }

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div
                className={cn(
                    'flex h-4 w-full overflow-hidden rounded-full',
                    onSky && 'ring-1 ring-cream/15',
                )}
            >
                {mix.map((slice) => (
                    <div
                        key={slice.mood}
                        className={cn('h-full', MOOD_FILL[slice.mood])}
                        style={{ width: `${slice.percent}%` }}
                        aria-label={`${MOOD_LABEL[slice.mood]} ${slice.percent}%`}
                    />
                ))}
            </div>
            <ul className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs">
                {mix.map((slice) => (
                    <li
                        key={slice.mood}
                        className={cn(
                            'inline-flex items-center gap-1.5',
                            onSky ? 'text-ink-on-sky' : 'text-ink-2',
                        )}
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'h-2 w-2 rounded-full',
                                MOOD_FILL[slice.mood],
                            )}
                        />
                        <span
                            className={cn(
                                'font-medium',
                                onSky ? 'text-cream' : 'text-ink',
                            )}
                        >
                            {MOOD_LABEL[slice.mood]}
                        </span>
                        <span
                            className={cn(
                                'font-mono font-semibold tabular-nums',
                                onSky ? 'text-ink-on-sky' : 'text-ink-2',
                            )}
                        >
                            {slice.percent.toFixed(1)}%
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
