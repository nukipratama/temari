import type { ReactNode } from 'react';
import { Icon } from '@iconify/react';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import type { AnalysisPayload } from '@/types/inertia';
import UnavailableNote from './UnavailableNote';

export type AnalysisStatusSize = 'sm' | 'md';

interface Props {
    analysis: AnalysisPayload;
    inertiaReloadProps?: string[];
    size?: AnalysisStatusSize;
    /** Render the LLM content. Receives the resolved narrative string. */
    renderContent?: (content: string) => ReactNode;
    /** Whether to show the manual trigger button when status is `done`. */
    allowReanalyze?: boolean;
}

const TEXT_SIZE: Record<AnalysisStatusSize, string> = {
    sm: 'text-sm leading-relaxed',
    md: 'text-base leading-relaxed',
};

export default function AnalysisStatus({
    analysis,
    inertiaReloadProps = [],
    size = 'md',
    renderContent,
    allowReanalyze = true,
}: Readonly<Props>) {
    const { status, pending, trigger } = useAnalysisTrigger(analysis, inertiaReloadProps);
    const effectiveStatus = pending ? 'queued' : status;
    const content = analysis.content;

    if (effectiveStatus === 'done' && content !== null) {
        return (
            <div className="flex flex-col gap-1">
                <div className={`${TEXT_SIZE[size]} text-ink`}>
                    {renderContent ? renderContent(content) : content}
                </div>
                {allowReanalyze && (
                    <button
                        type="button"
                        onClick={trigger}
                        className="inline-flex items-center self-start gap-1 text-xs text-ink-meta hover:text-brand-700 transition-colors"
                    >
                        <Icon icon="mdi:refresh" aria-hidden />
                        <span>Analisis ulang</span>
                    </button>
                )}
            </div>
        );
    }

    if (effectiveStatus === 'queued' || effectiveStatus === 'processing') {
        return (
            <span
                className="inline-flex items-center gap-2 rounded-full bg-surface-sunken text-ink-meta text-xs px-3 py-1.5"
                role="status"
                aria-live="polite"
            >
                <Icon icon="mdi:loading" className="animate-spin" aria-hidden />
                <span>Lagi dipikirin Temari…</span>
            </span>
        );
    }

    if (effectiveStatus === 'failed') {
        return (
            <div className="flex flex-col gap-1.5">
                <UnavailableNote size={size} />
                {allowReanalyze && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={pending}
                        className="inline-flex items-center self-start gap-1 text-xs text-brand-700 hover:text-brand-800 transition-colors disabled:opacity-50"
                    >
                        <Icon icon="mdi:reload" aria-hidden />
                        <span>Coba lagi</span>
                    </button>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1.5">
            <span className="inline-flex items-center gap-1.5 text-xs text-ink-meta">
                <Icon icon="mdi:sparkles-outline" aria-hidden />
                <span>Belum dianalisis Temari.</span>
            </span>
            {allowReanalyze && (
                <button
                    type="button"
                    onClick={trigger}
                    disabled={pending}
                    className="inline-flex items-center self-start gap-1 rounded-full bg-brand-700 text-white text-xs px-3 py-1 font-semibold hover:bg-brand-800 transition-colors disabled:opacity-50"
                >
                    <Icon icon="mdi:auto-fix" aria-hidden />
                    <span>Analisis sekarang</span>
                </button>
            )}
        </div>
    );
}
