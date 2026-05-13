import { useState } from 'react';
import { cn } from '@/lib/cn';
import { MOOD_FACE, MASCOT_GRADIENT, moodRing, moodSigilColor } from '@/lib/mood';
import TemariSigil from './TemariSigil';
import type { Mood, StoryLine } from '@/types/inertia';

interface TemariBubbleProps {
    line: StoryLine | null;
    size?: 'sm' | 'lg';
    variations?: string[];
    accessory?: string | null;
    className?: string;
}

/**
 * Speech-bubble version of Temari with optional tap-to-cycle variations.
 * Replaces window.temariCycle from the old Blade component with useState.
 */
export default function TemariBubble({
    line,
    size = 'lg',
    variations = [],
    accessory = null,
    className,
}: Readonly<TemariBubbleProps>) {
    const mood: Mood = (line?.mood ?? 'dim') as Mood;
    const baseSpeech = line?.speech ?? 'Hai! Temari belum punya cerita untuk aktivitas ini.';
    const sigil = line?.sigil_pattern ?? 'dddd';
    const hasVariations = variations.length > 1;

    const [idx, setIdx] = useState(0);
    const speech = hasVariations ? variations[idx] : baseSpeech;
    const cycle = () => setIdx((i) => (i + 1) % variations.length);

    const mascotSize = size === 'lg' ? 'h-24 w-24 text-3xl' : 'h-14 w-14 text-xl';
    const sigilSize = size === 'lg' ? 96 : 56;
    const bodyPad = size === 'lg' ? 'p-5' : 'p-3';
    const bodyText = size === 'lg' ? 'text-base' : 'text-sm';
    const sigilColor = moodSigilColor(mood);

    const mascot = (
        <>
            <span className="relative z-10">{MOOD_FACE[mood]}</span>
            <TemariSigil
                pattern={sigil}
                size={sigilSize}
                color={sigilColor}
                accessory={accessory}
                className="absolute inset-0 mix-blend-multiply dark:mix-blend-screen"
            />
        </>
    );

    return (
        <div
            className={cn(
                'flex items-start gap-4 rounded-2xl border border-line bg-surface-elev dark:border-line-dark dark:bg-surface-dark-elev',
                bodyPad,
                className,
            )}
        >
            <div className="relative shrink-0">
                {hasVariations ? (
                    <button
                        type="button"
                        onClick={cycle}
                        aria-label="Ganti kata Temari"
                        className={cn(
                            mascotSize,
                            'relative flex items-center justify-center rounded-full ring-4 transition hover:scale-105 active:scale-95',
                            MASCOT_GRADIENT,
                            moodRing(mood),
                        )}
                    >
                        {mascot}
                    </button>
                ) : (
                    <div
                        className={cn(
                            mascotSize,
                            'relative flex items-center justify-center rounded-full ring-4',
                            MASCOT_GRADIENT,
                            moodRing(mood),
                        )}
                    >
                        {mascot}
                    </div>
                )}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold tracking-tight">Temari</span>
                    <span className="text-[10px] uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">{mood}</span>
                    {hasVariations && (
                        <span className="text-[10px] uppercase tracking-wider text-brand-600 dark:text-brand-400">
                            tap untuk ganti
                        </span>
                    )}
                </div>
                <p className={cn('mt-1 leading-relaxed text-ink dark:text-ink-dark', bodyText)}>{speech}</p>
            </div>
        </div>
    );
}
