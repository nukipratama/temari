import { motion } from 'framer-motion';

import type { Mood } from '@/types/inertia';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { MOOD_FILL, MOOD_LABEL, MOOD_SOFT_FILL } from '@/lib/mood';
import { countUpEase } from '@/lib/motion';

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
                    tone="onSky"
                    padding="card"
                    className={cn('text-center', className)}
                >
                    <p className="font-serif text-base italic text-cream/85">
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
            {/* Gapped, individually-rounded segments (not one continuous bar)
                mirror the prototype's own time-in-zone treatment, so the
                identity read gets the same distinctive shape used elsewhere
                for "how the last N weeks broke down". */}
            <div
                className={cn(
                    'flex h-4 w-full gap-[3px] rounded-full',
                    onSky ? 'ring-1 ring-cream/15' : 'ring-1 ring-border',
                )}
            >
                {mix.map((slice, index) => (
                    <motion.div
                        key={slice.mood}
                        className={cn(
                            'h-full origin-left rounded-full',
                            MOOD_FILL[slice.mood],
                        )}
                        style={{ width: `${slice.percent}%` }}
                        initial={{ scaleX: 0 }}
                        animate={{ scaleX: 1 }}
                        transition={{
                            duration: 0.6,
                            delay: index * 0.06,
                            ease: countUpEase,
                        }}
                        aria-label={`${MOOD_LABEL[slice.mood]} ${slice.percent}%`}
                    />
                ))}
            </div>
            <ul className="flex flex-wrap gap-1.5 text-xs">
                {mix.map((slice) => (
                    <li
                        key={slice.mood}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1',
                            onSky
                                ? 'bg-cream/[0.08] text-ink-on-sky'
                                : cn(MOOD_SOFT_FILL[slice.mood], 'text-text-2'),
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
                                onSky ? 'text-cream' : 'text-foreground',
                            )}
                        >
                            {MOOD_LABEL[slice.mood]}
                        </span>
                        <span
                            className={cn(
                                'font-mono font-semibold tabular-nums',
                                onSky ? 'text-ink-on-sky' : 'text-text-2',
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
