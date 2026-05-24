import { Icon } from '@iconify/react';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Card from '@/components/ui/Card';
import { cn } from '@/lib/cn';
import type { AnalysisPayload } from '@/types/inertia';

interface LensConfig {
    id: 'cerita' | 'terjemahan' | 'split' | 'hr';
    icon: string;
    label: string;
    analysis: AnalysisPayload;
    /** Tailwind color token for the left rule + icon ("leaf" | "sky" | "citrus" | "ember"). */
    tone: 'leaf' | 'sky' | 'citrus' | 'ember';
}

interface FourLensGridProps {
    cerita: AnalysisPayload;
    terjemahan: AnalysisPayload;
    split: AnalysisPayload;
    hr: AnalysisPayload;
    /** Inertia partial-reload prop names to refresh when the user triggers analysis. */
    inertiaReloadProps?: string[];
    className?: string;
}

const TONE_BORDER: Record<LensConfig['tone'], string> = {
    leaf: 'border-l-leaf',
    sky: 'border-l-sky',
    citrus: 'border-l-citrus',
    ember: 'border-l-ember',
};

const TONE_ICON: Record<LensConfig['tone'], string> = {
    leaf: 'text-leaf',
    sky: 'text-sky',
    citrus: 'text-citrus',
    ember: 'text-ember',
};

const DEFAULT_RELOAD_PROPS = ['speechAnalysis', 'insightTechnical', 'insightSplits', 'insightZones'];

export default function FourLensGrid({
    cerita,
    terjemahan,
    split,
    hr,
    inertiaReloadProps = DEFAULT_RELOAD_PROPS,
    className,
}: Readonly<FourLensGridProps>) {
    const lenses: ReadonlyArray<LensConfig> = [
        { id: 'cerita', icon: 'mdi:chat-outline', label: 'Cerita lari ini', analysis: cerita, tone: 'leaf' },
        { id: 'terjemahan', icon: 'mdi:stethoscope', label: 'Terjemahan teknis', analysis: terjemahan, tone: 'ember' },
        { id: 'split', icon: 'mdi:timer-outline', label: 'Split paling seru', analysis: split, tone: 'citrus' },
        { id: 'hr', icon: 'mdi:heart-pulse', label: 'Zona HR', analysis: hr, tone: 'sky' },
    ];

    return (
        <div className={cn('grid gap-3.5 sm:grid-cols-2', className)}>
            {lenses.map((lens) => (
                <Card
                    key={lens.id}
                    as="article"
                    padding="lg"
                    className={cn('border-l-[3px]', TONE_BORDER[lens.tone])}
                >
                    <div className="mb-2.5 flex items-center gap-2">
                        <Icon
                            icon={lens.icon}
                            width={14}
                            height={14}
                            aria-hidden
                            className={cn(TONE_ICON[lens.tone])}
                        />
                        <div className="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-3">
                            {lens.label}
                        </div>
                    </div>
                    <AnalysisStatus
                        analysis={lens.analysis}
                        inertiaReloadProps={inertiaReloadProps}
                        renderContent={(text) => (
                            <p className="font-sans text-[15px] leading-relaxed text-ink">
                                {text}
                            </p>
                        )}
                    />
                </Card>
            ))}
        </div>
    );
}
