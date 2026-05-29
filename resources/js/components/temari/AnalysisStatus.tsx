import { useEffect, useState, type ReactNode } from 'react';
import { Icon } from '@iconify/react';
import { RATE_LIMITED_ERROR, useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { formatDurationHMS, formatRelativeId } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
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
    /** Whether to render the "Dibuat …" relative timestamp when status is `done`. */
    showTimestamp?: boolean;
    /** Use cream-tinted colours for non-done states when rendered on a dark sky panel. */
    onSky?: boolean;
}

const TEXT_SIZE: Record<AnalysisStatusSize, string> = {
    sm: 'text-sm leading-relaxed',
    md: 'text-base leading-relaxed',
};

function RateLimitedNote() {
    return (
        <span className="text-xs text-horizon-deep">
            Pelan-pelan, Temari kewalahan. Coba lagi sebentar ya.
        </span>
    );
}

function useCooldownCountdown(initialSeconds: number | null | undefined): number {
    const [remaining, setRemaining] = useState(() => Math.max(0, initialSeconds ?? 0));

    useEffect(() => {
        setRemaining(Math.max(0, initialSeconds ?? 0));
    }, [initialSeconds]);

    const ticking = remaining > 0;
    useEffect(() => {
        if (!ticking) return;
        const id = globalThis.setInterval(() => {
            setRemaining((r) => (r <= 1 ? 0 : r - 1));
        }, 1000);
        return () => globalThis.clearInterval(id);
    }, [ticking]);

    return remaining;
}

export default function AnalysisStatus({
    analysis,
    inertiaReloadProps = [],
    size = 'md',
    renderContent,
    allowReanalyze = true,
    showTimestamp = true,
    onSky = false,
}: Readonly<Props>) {
    const { status, pending, error, retryAfterSeconds, trigger } = useAnalysisTrigger(analysis, inertiaReloadProps);
    const effectiveStatus = pending ? 'queued' : status;
    const content = analysis.content;
    const attempts = analysis.attempts ?? 0;
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const rateLimited = error === RATE_LIMITED_ERROR;

    if (effectiveStatus === 'done' && content !== null) {
        const cooling = cooldownRemaining > 0;
        return (
            <div className="flex flex-col gap-1">
                <div className={`${TEXT_SIZE[size]} whitespace-pre-line text-ink`}>
                    {renderContent ? renderContent(content) : renderBold(content)}
                </div>
                {showTimestamp && analysis.generated_at && (
                    <span className="text-xs text-ink-3">
                        Dibuat {formatRelativeId(analysis.generated_at)}
                    </span>
                )}
                {allowReanalyze && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={cooling || pending}
                        className="inline-flex items-center self-start gap-1 text-xs text-ink-3 hover:text-leaf-deep transition-colors disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-ink-3"
                    >
                        <Icon icon="mdi:refresh" aria-hidden />
                        <span>
                            {cooling
                                ? `Tunggu ${formatDurationHMS(cooldownRemaining)} ya`
                                : 'Baca ulang'}
                        </span>
                    </button>
                )}
                {rateLimited && <RateLimitedNote />}
            </div>
        );
    }

    if (effectiveStatus === 'queued' || effectiveStatus === 'processing') {
        return (
            <span
                className={`inline-flex items-center gap-2 rounded-full text-xs px-3 py-1.5 ${onSky ? 'bg-cream/10 text-cream/70' : 'bg-surface-sunken text-ink-2'}`}
                role="status"
                aria-live="polite"
            >
                <Icon icon="mdi:loading" className="animate-spin" aria-hidden />
                <span>
                    Lagi dipikirin Temari…
                    {attempts > 1 && ` (percobaan ${attempts})`}
                </span>
            </span>
        );
    }

    if (effectiveStatus === 'failed') {
        return (
            <div className="flex flex-col gap-1.5">
                <UnavailableNote size={size} />
                {rateLimited && <RateLimitedNote />}
                {allowReanalyze && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={pending}
                        className="inline-flex items-center self-start gap-1 text-xs text-leaf-deep hover:text-ink transition-colors disabled:opacity-50"
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
            <span className={`inline-flex items-center gap-1.5 text-xs ${onSky ? 'text-cream/60' : 'text-ink-2'}`}>
                <Icon icon="mdi:sparkles-outline" aria-hidden />
                <span>Belum dibaca Temari.</span>
            </span>
            {allowReanalyze && (
                <button
                    type="button"
                    onClick={trigger}
                    disabled={pending}
                    className="inline-flex items-center self-start gap-1 rounded-full bg-leaf-deep text-cream text-xs px-3 py-1 font-semibold transition hover:opacity-90 disabled:opacity-50"
                >
                    <Icon icon="mdi:auto-fix" aria-hidden />
                    <span>Minta Temari bacain</span>
                </button>
            )}
        </div>
    );
}
