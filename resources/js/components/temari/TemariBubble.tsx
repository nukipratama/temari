import { cn } from '@/lib/cn';
import TemariMascot from './TemariMascot';
import type { Mood, StoryLine } from '@/types/inertia';

interface TemariBubbleProps {
    line: StoryLine | null;
    size?: 'sm' | 'lg';
    /**
     * Additional one-liners from Temari for the same activity. Previously
     * cycled behind a "tap untuk ganti" interaction; now rendered inline
     * as muted "alt takes" beneath the primary line so the user sees
     * everything Temari has to say without having to discover the tap.
     */
    variations?: string[];
    accessory?: string | null;
    className?: string;
}

export default function TemariBubble({
    line,
    size = 'lg',
    variations = [],
    accessory = null,
    className,
}: Readonly<TemariBubbleProps>) {
    const mood: Mood = line?.mood ?? 'dim';
    const primary = line?.speech ?? 'Hai! Temari belum punya cerita untuk aktivitas ini.';
    const sigil = line?.sigil_pattern ?? 'dddd';
    // Variations beyond the primary; deduped against the primary speech
    // because the BE sometimes seeds the first variation with the same
    // line. Without the dedupe the user sees the same sentence twice.
    const altTakes = variations.filter((v) => v !== primary);

    const mascotSizeClass = size === 'lg' ? 'h-24 w-24 shrink-0' : 'h-14 w-14 shrink-0';
    const sigilSize = size === 'lg' ? 96 : 56;
    const bodyPad = size === 'lg' ? 'p-5' : 'p-3';
    const bodyText = size === 'lg' ? 'text-base' : 'text-sm';
    const interactive = size === 'lg';

    return (
        <div
            className={cn(
                'flex items-start gap-4 rounded-2xl border border-line bg-surface-elev dark:border-line-dark dark:bg-surface-dark-elev',
                bodyPad,
                className,
            )}
        >
            <TemariMascot
                mood={mood}
                sigilPattern={sigil}
                accessory={accessory}
                sizeClass={mascotSizeClass}
                sigilPixels={sigilSize}
                idle={interactive ? 'mood' : 'breath'}
                gazeTracking={interactive}
                hoverable={interactive}
                interactive={interactive}
                aria-label="Temari"
            />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold tracking-tight text-ink dark:text-ink-dark">Temari</span>
                    <span className="text-[10px] uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                        {mood}
                    </span>
                </div>
                <p className={cn('mt-1 leading-relaxed text-ink dark:text-ink-dark', bodyText)}>{primary}</p>
                {altTakes.length > 0 && (
                    <ul className="mt-3 space-y-1.5 border-t border-line pt-3 text-sm leading-relaxed text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">
                        {altTakes.map((take) => (
                            <li key={take} className="flex items-start gap-2">
                                <span aria-hidden className="mt-1 inline-block h-1 w-1 shrink-0 rounded-full bg-brand-500/60" />
                                <span>{take}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
