import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import { Icon } from '@iconify/react';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Card from '@/components/ui/Card';
import { csrfToken } from '@/lib/http';
import { renderBold } from '@/lib/richText';
import { cn } from '@/lib/cn';
import type { AnalysisPayload } from '@/types/inertia';

interface LensConfig {
    id: 'cerita' | 'terjemahan' | 'split' | 'hr';
    icon: string;
    label: string;
    analysis: AnalysisPayload;
    tone: 'leaf' | 'sky' | 'citrus' | 'ember';
}

interface FourLensGridProps {
    cerita: AnalysisPayload;
    terjemahan: AnalysisPayload;
    split: AnalysisPayload;
    hr: AnalysisPayload;
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

async function triggerOne(analysis: AnalysisPayload): Promise<void> {
    const base = `/api/analyses/${analysis.type}/${analysis.subject_id}/trigger`;
    const url = analysis.discriminator
        ? `${base}?discriminator=${encodeURIComponent(analysis.discriminator)}`
        : base;
    await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });
}

export default function FourLensGrid({
    cerita,
    terjemahan,
    split,
    hr,
    inertiaReloadProps = DEFAULT_RELOAD_PROPS,
    className,
}: Readonly<FourLensGridProps>) {
    const [bulkPending, setBulkPending] = useState(false);

    const lenses = useMemo<ReadonlyArray<LensConfig>>(() => [
        { id: 'cerita', icon: 'mdi:chat-outline', label: 'Cerita lari ini', analysis: cerita, tone: 'leaf' },
        { id: 'terjemahan', icon: 'mdi:stethoscope', label: 'Terjemahan teknis', analysis: terjemahan, tone: 'ember' },
        { id: 'split', icon: 'mdi:timer-outline', label: 'Split paling seru', analysis: split, tone: 'citrus' },
        { id: 'hr', icon: 'mdi:heart-pulse', label: 'Zona HR', analysis: hr, tone: 'sky' },
    ], [cerita, terjemahan, split, hr]);

    const triggerAll = useCallback(async () => {
        if (bulkPending) return;
        setBulkPending(true);
        await Promise.allSettled(lenses.map((l) => triggerOne(l.analysis)));
        router.reload({ only: inertiaReloadProps });
        setBulkPending(false);
    }, [bulkPending, lenses, inertiaReloadProps]);

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            {/* Single re-analyze control */}
            <div className="flex justify-start">
                <button
                    type="button"
                    onClick={triggerAll}
                    disabled={bulkPending}
                    className="focus-ring rounded inline-flex items-center gap-1.5 font-mono font-bold text-[11px] uppercase tracking-[0.1em] text-ink-2 transition hover:text-leaf-deep disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Icon icon={bulkPending ? 'mdi:loading' : 'mdi:refresh'} className={cn(bulkPending && 'animate-spin')} aria-hidden />
                    {bulkPending ? 'Lagi dibaca…' : 'Baca ulang semua'}
                </button>
            </div>

            <div className="flex flex-col gap-3.5">
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
                            <div className="font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                                {lens.label}
                            </div>
                        </div>
                        <AnalysisStatus
                            analysis={lens.analysis}
                            inertiaReloadProps={inertiaReloadProps}
                            allowReanalyze={false}
                            showTimestamp={false}
                            renderContent={(text) => (
                                <p className="font-sans text-[15px] leading-relaxed text-ink">
                                    {renderBold(text)}
                                </p>
                            )}
                        />
                    </Card>
                ))}
            </div>
        </div>
    );
}
