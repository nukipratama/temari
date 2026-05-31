import { cn } from '@/lib/cn';
import AnalysisStatus from './AnalysisStatus';
import TemariMascot from './TemariMascot';
import type { AnalysisPayload, Mood, StoryLine } from '@/types/inertia';

interface TemariBubbleProps {
    line: StoryLine | null;
    speechAnalysis: AnalysisPayload;
    size?: 'sm' | 'lg';
    inertiaReloadProps?: string[];
    className?: string;
}

export default function TemariBubble({
    line,
    speechAnalysis,
    size = 'lg',
    inertiaReloadProps = [],
    className,
}: Readonly<TemariBubbleProps>) {
    const mood: Mood = line?.mood ?? 'adem';

    const isLarge = size === 'lg';
    const mascotSizeClass = isLarge ? 'h-36 w-36 shrink-0' : 'h-20 w-20 shrink-0';
    const bodyPad = isLarge ? 'p-5' : 'p-3';

    return (
        <div
            className={cn(
                'flex items-start gap-4 rounded-2xl border border-line bg-surface-elev',
                bodyPad,
                className,
            )}
        >
            <TemariMascot
                mood={mood}
                sizeClass={mascotSizeClass}
                idle={isLarge ? 'mood' : 'breath'}
                gazeTracking={isLarge}
                ornaments={isLarge}
                aria-label={`Temari mood ${mood}`}
            />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold tracking-tight text-ink">Temari</span>
                    <span className="font-mono text-[11px] uppercase tracking-wider text-ink-3">{mood}</span>
                </div>
                <div className="mt-1">
                    <AnalysisStatus
                        analysis={speechAnalysis}
                        inertiaReloadProps={inertiaReloadProps}
                        size={isLarge ? 'md' : 'sm'}
                    />
                </div>
            </div>
        </div>
    );
}
